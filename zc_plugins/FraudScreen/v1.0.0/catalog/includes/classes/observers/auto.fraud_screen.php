<?php
/**
 * Fraud Screen - order screening observer.
 *
 * Scores an order once it has been created and, if it reaches the configured threshold,
 * moves it to a review status and records the reasons in the order history.
 *
 * Deliberately runs *after* the order exists rather than blocking at checkout:
 *  - the shopper never sees an error, so a false positive costs a delay, not a sale;
 *  - the payment result is already known, so genuine declines are not screened at all;
 *  - the evidence is preserved on the order for whoever reviews it.
 *
 * Nothing in here is allowed to interrupt checkout. Every path is wrapped, and any
 * unexpected condition results in the order being left exactly as it was.
 */
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

class zcObserverFraudScreen extends base
{
    protected array $reasons = [];
    protected int $score = 0;

    public function __construct()
    {
        $this->attach($this, ['NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS']);
    }

    public function update(&$callingClass, $notifier, $paramsArray = [], &$orderObject = null)
    {
        if ($notifier !== 'NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS') {
            return;
        }

        try {
            if (!defined('FRAUD_SCREEN_STATUS') || FRAUD_SCREEN_STATUS !== 'true') {
                return;
            }

            // The notifier is raised as notify($event, $insert_id, $order), so the order id
            // arrives as $paramsArray and the order object as the next parameter.
            $oID = (int)(is_scalar($paramsArray) ? $paramsArray : 0);
            if ($oID <= 0) {
                return;
            }

            $order = $orderObject;
            if (!is_object($order)) {
                // Fall back to the global the checkout process is using.
                global $order;
            }
            if (!is_object($order)) {
                return;
            }

            $this->score = 0;
            $this->reasons = [];

            $this->checkPhone($order);
            $this->checkEmail($order);
            $this->checkGeography($order);
            $this->checkProducts($order);
            $this->checkVelocity($order, $oID);

            $threshold = (int)(defined('FRAUD_SCREEN_THRESHOLD') ? FRAUD_SCREEN_THRESHOLD : 100);
            $held = ($threshold > 0 && $this->score >= $threshold);

            if (defined('FRAUD_SCREEN_LOG') && FRAUD_SCREEN_LOG === 'true') {
                $this->log($oID, $held, $threshold);
            }

            if ($held) {
                $this->holdOrder($oID, $threshold);
            }
        } catch (\Throwable $e) {
            // Screening must never cost a sale. Swallow and, if logging is on, record why.
            if (defined('FRAUD_SCREEN_LOG') && FRAUD_SCREEN_LOG === 'true') {
                @error_log('[FraudScreen] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n", 3, DIR_FS_LOGS . '/fraud_screen.log');
            }
        }
    }

    // ---------------------------------------------------------------- rules ----

    protected function checkPhone($order): void
    {
        $list = $this->csv(defined('FRAUD_SCREEN_BLOCKED_PHONES') ? FRAUD_SCREEN_BLOCKED_PHONES : '');
        if ($list === []) {
            return;
        }
        $phone = $this->digits($order->customer['telephone'] ?? '');
        if ($phone === '') {
            return;
        }
        foreach ($list as $candidate) {
            $candidate = $this->digits($candidate);
            if ($candidate !== '' && $candidate === $phone) {
                $this->add((int)FRAUD_SCREEN_BLOCKED_PHONES_POINTS, 'telephone on watch list');
                return;
            }
        }
    }

    protected function checkEmail($order): void
    {
        $patterns = $this->csv(defined('FRAUD_SCREEN_EMAIL_PATTERNS') ? FRAUD_SCREEN_EMAIL_PATTERNS : '');
        if ($patterns === []) {
            return;
        }
        $email = trim((string)($order->customer['email_address'] ?? ''));
        if ($email === '') {
            return;
        }
        foreach ($patterns as $pattern) {
            // A bad pattern must not break checkout, so suppress and skip.
            $match = @preg_match('/' . str_replace('/', '\/', $pattern) . '/i', $email);
            if ($match === 1) {
                $this->add((int)FRAUD_SCREEN_EMAIL_PATTERNS_POINTS, 'email matches pattern');
                return;
            }
        }
    }

    protected function checkGeography($order): void
    {
        $bill_state = strtolower(trim((string)($order->billing['state'] ?? '')));
        $ship_state = strtolower(trim((string)($order->delivery['state'] ?? '')));
        if ($bill_state !== '' && $ship_state !== '' && $bill_state !== $ship_state) {
            $this->add((int)(defined('FRAUD_SCREEN_STATE_MISMATCH_POINTS') ? FRAUD_SCREEN_STATE_MISMATCH_POINTS : 0), 'bills to a different state than it ships to');
        }

        $bill_country = strtolower(trim((string)($order->billing['country']['title'] ?? $order->billing['country'] ?? '')));
        $ship_country = strtolower(trim((string)($order->delivery['country']['title'] ?? $order->delivery['country'] ?? '')));
        if ($bill_country !== '' && $ship_country !== '' && $bill_country !== $ship_country) {
            $this->add((int)(defined('FRAUD_SCREEN_COUNTRY_MISMATCH_POINTS') ? FRAUD_SCREEN_COUNTRY_MISMATCH_POINTS : 0), 'bills to a different country than it ships to');
        }
    }

    protected function checkProducts($order): void
    {
        $watched = $this->csv(defined('FRAUD_SCREEN_WATCHED_MODELS') ? FRAUD_SCREEN_WATCHED_MODELS : '');
        if ($watched === [] || empty($order->products) || !is_array($order->products)) {
            return;
        }
        foreach ($order->products as $product) {
            $model = trim((string)($product['model'] ?? ''));
            if ($model === '') {
                continue;
            }
            foreach ($watched as $w) {
                if ($w !== '' && stripos($model, $w) === 0) {
                    $this->add((int)FRAUD_SCREEN_WATCHED_MODELS_POINTS, 'contains watched product ' . $model);
                    return;
                }
            }
        }
    }

    protected function checkVelocity($order, int $oID): void
    {
        $days = (int)(defined('FRAUD_SCREEN_VELOCITY_DAYS') ? FRAUD_SCREEN_VELOCITY_DAYS : 0);
        $points = (int)(defined('FRAUD_SCREEN_VELOCITY_POINTS') ? FRAUD_SCREEN_VELOCITY_POINTS : 0);
        if ($days <= 0 || $points <= 0) {
            return;
        }
        global $db;
        if (!isset($db) || !is_object($db)) {
            return;
        }

        $phone = $this->digits($order->customer['telephone'] ?? '');
        $email = trim((string)($order->customer['email_address'] ?? ''));
        if ($phone !== '') {
            // Only a *different* person reusing the number is suspicious. A returning
            // customer ordering again from the same phone is the opposite of a red flag,
            // so orders sharing this email address are excluded.
            $sql =
                "SELECT orders_id, customers_email_address
                   FROM " . TABLE_ORDERS . "
                  WHERE orders_id <> " . $oID . "
                    AND date_purchased > DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
                    AND REPLACE(REPLACE(REPLACE(REPLACE(customers_telephone, '-', ''), ' ', ''), '(', ''), ')', '') = '" . zen_db_input($phone) . "'
                    AND customers_email_address <> '" . zen_db_input($email) . "'
                  LIMIT 1";
            $check = $db->Execute($sql);
            if (!$check->EOF) {
                $this->add($points, 'telephone used by a different customer on order #' . (int)$check->fields['orders_id']);
                return;
            }
        }

        $street = trim((string)($order->delivery['street_address'] ?? ''));
        $surname = trim((string)($order->delivery['lastname'] ?? ''));
        if ($street !== '' && $surname !== '') {
            $sql =
                "SELECT orders_id, delivery_name
                   FROM " . TABLE_ORDERS . "
                  WHERE orders_id <> " . $oID . "
                    AND date_purchased > DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
                    AND delivery_street_address = '" . zen_db_input($street) . "'
                    AND delivery_name NOT LIKE '%" . zen_db_input($surname) . "%'
                  LIMIT 1";
            $check = $db->Execute($sql);
            if (!$check->EOF) {
                $this->add($points, 'delivery address used under another name on order #' . (int)$check->fields['orders_id']);
            }
        }
    }

    // -------------------------------------------------------------- actions ----

    protected function holdOrder(int $oID, int $threshold): void
    {
        global $db;

        $status = (int)(defined('FRAUD_SCREEN_HOLD_STATUS_ID') ? FRAUD_SCREEN_HOLD_STATUS_ID : 1);
        if ($status <= 0) {
            return;
        }

        $comment = sprintf(
            "FRAUD SCREEN: held for review, score %d of %d threshold.\nReasons: %s",
            $this->score,
            $threshold,
            implode('; ', $this->reasons)
        );

        $db->Execute(
            "UPDATE " . TABLE_ORDERS . "
                SET orders_status = " . $status . "
              WHERE orders_id = " . $oID . "
              LIMIT 1"
        );

        // customer_notified = 0: the shopper is not told their order was flagged.
        if (function_exists('zen_update_orders_history')) {
            zen_update_orders_history($oID, $comment, null, $status, 0);
        }

        $this->notifyStaff($oID, $comment);
    }

    protected function notifyStaff(int $oID, string $comment): void
    {
        $to = trim((string)(defined('FRAUD_SCREEN_NOTIFY_EMAIL') ? FRAUD_SCREEN_NOTIFY_EMAIL : ''));
        if ($to === '' || !function_exists('zen_mail')) {
            return;
        }
        $subject = sprintf('Order #%d held by Fraud Screen (score %d)', $oID, $this->score);
        $body = $comment . "\n\n" . sprintf('Order number %d, on %s.', $oID, defined('STORE_NAME') ? STORE_NAME : '');
        $from = defined('EMAIL_FROM') ? EMAIL_FROM : $to;
        $store = defined('STORE_NAME') ? STORE_NAME : '';
        @zen_mail('', $to, $subject, $body, $store, $from, ['EMAIL_MESSAGE_HTML' => nl2br($body)], 'default');
    }

    protected function log(int $oID, bool $held, int $threshold): void
    {
        $line = sprintf(
            "[%s] order #%d score=%d threshold=%d %s reasons=[%s]\n",
            date('Y-m-d H:i:s'),
            $oID,
            $this->score,
            $threshold,
            $held ? 'HELD' : 'passed',
            implode('; ', $this->reasons)
        );
        @error_log($line, 3, DIR_FS_LOGS . '/fraud_screen.log');
    }

    // -------------------------------------------------------------- helpers ----

    protected function add(int $points, string $reason): void
    {
        if ($points <= 0) {
            return;
        }
        $this->score += $points;
        $this->reasons[] = $reason . ' (+' . $points . ')';
    }

    protected function csv($value): array
    {
        $out = [];
        foreach (explode(',', (string)$value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    protected function digits($value): string
    {
        return preg_replace('/\D+/', '', (string)$value);
    }
}
