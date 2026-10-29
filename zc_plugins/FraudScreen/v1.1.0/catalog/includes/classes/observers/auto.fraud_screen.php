<?php
/**
 * Fraud Screen - order screening observer.
 *
 * Scores an order once it has been created and paid and, if it reaches the configured
 * threshold, moves it to a review status and records the reasons in the order history.
 *
 * It runs at NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET, after the payment module's
 * after_process(). A module's after_process() may set the order's status itself (the
 * Authorize.Net Accept.js module does, through zen_update_orders_history), so a hold put
 * on any earlier, at NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS as v1.0.0
 * first did, was silently put back to the paid status. The same order on every release
 * from 1.5.8 through 3.0.0: after_process() is line 16 of
 * includes/modules/pages/checkout_process/header_php.php, this notifier line 18.
 *
 * Deliberately runs *after* the order exists rather than blocking at checkout:
 *  - the shopper never sees an error, so a false positive costs a delay, not a sale;
 *  - the payment result is already known, so genuine declines are not screened at all;
 *  - the evidence is preserved on the order for whoever reviews it.
 *
 * Nothing in here is allowed to interrupt checkout. Every path is wrapped, and any
 * unexpected condition results in the order being left exactly as it was.
 *
 * Add-ons (Fraud Screen Pro, or a store's own observer) extend it through three
 * notifiers, raised here and documented in the readme's developer section:
 *  - NOTIFY_FRAUD_SCREEN_SCORING     after the built-in rules: add points and reasons;
 *  - NOTIFY_FRAUD_SCREEN_BEFORE_HOLD the threshold is reached: release or force the hold;
 *  - NOTIFY_FRAUD_SCREEN_HELD        after an order has been held.
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
        $this->attach($this, ['NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET']);
    }

    public function update(&$callingClass, $notifier, $paramsArray = [], &$orderObject = null)
    {
        if ($notifier !== 'NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET') {
            return;
        }

        try {
            // Switched off with logging on is the dry run the setup steps describe: every
            // order is scored and logged, with what would have happened, and nothing is
            // held. Switched off with logging off does nothing at all.
            $enabled = defined('FRAUD_SCREEN_STATUS') && FRAUD_SCREEN_STATUS === 'true';
            $logging = defined('FRAUD_SCREEN_LOG') && FRAUD_SCREEN_LOG === 'true';
            if (!$enabled && !$logging) {
                return;
            }

            // The notifier is raised as notify($event, $insert_id), so the order id arrives
            // as $paramsArray and the order object comes from the checkout's global $order.
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
            $this->checkNames($order);
            $this->checkProducts($order);
            $this->checkVelocity($order, $oID);

            // Add-on rules. Locals rather than the typed properties, so a hook that hands
            // back the wrong type is cleaned up here instead of throwing a TypeError.
            $score = $this->score;
            $reasons = $this->reasons;
            $this->raise('NOTIFY_FRAUD_SCREEN_SCORING', ['orders_id' => $oID, 'order' => $order], $score, $reasons);
            $this->score = max(0, (int)$score);
            $this->reasons = array_values(array_filter((array)$reasons, 'is_string'));

            $threshold = (int)(defined('FRAUD_SCREEN_THRESHOLD') ? FRAUD_SCREEN_THRESHOLD : 100);
            $reached = ($threshold > 0 && $this->score >= $threshold);

            $trusted = '';
            $hold = false;
            $note = '';
            if ($reached) {
                // Only asked once an order would be held, so a clean order costs no query.
                $trusted = $this->trustedReason($oID);
                $hold = ($enabled && $trusted === '');
                $this->raise(
                    'NOTIFY_FRAUD_SCREEN_BEFORE_HOLD',
                    [
                        'orders_id' => $oID,
                        'order' => $order,
                        'score' => $this->score,
                        'threshold' => $threshold,
                        'reasons' => $this->reasons,
                        'trusted' => $trusted,
                        'dry_run' => !$enabled,
                    ],
                    $hold,
                    $note
                );
                // The dry run never holds, whatever a hook says.
                $hold = ($enabled && (bool)$hold);
                $note = is_string($note) ? trim($note) : '';
            }

            if ($logging) {
                $this->log($oID, $this->verdict($reached, $hold, $trusted, $enabled), $threshold);
            }

            if ($hold) {
                $status = $this->holdOrder($oID, $threshold, $note, $order);
                if ($status > 0) {
                    $this->raise('NOTIFY_FRAUD_SCREEN_HELD', [
                        'orders_id' => $oID,
                        'score' => $this->score,
                        'threshold' => $threshold,
                        'reasons' => $this->reasons,
                        'status_id' => $status,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Screening must never cost a sale. Swallow and, if logging is on, record why.
            $this->logError($e);
        }
    }

    /**
     * Raise one of Fraud Screen's own notifiers. An add-on that throws is logged and
     * ignored, so a broken add-on can't switch off the built-in rules or stop a hold.
     */
    protected function raise(string $event, array $info, &$first = null, &$second = null): void
    {
        try {
            $this->notify($event, $info, $first, $second);
        } catch (\Throwable $e) {
            $this->logError($e, $event);
        }
    }

    protected function logError(\Throwable $e, string $event = ''): void
    {
        if (defined('FRAUD_SCREEN_LOG') && FRAUD_SCREEN_LOG === 'true') {
            $where = ($event === '') ? '' : ' (add-on on ' . $event . ')';
            @error_log('[FraudScreen]' . $where . ' ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n", 3, DIR_FS_LOGS . '/fraud_screen.log');
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

    protected function checkNames($order): void
    {
        $points = (int)(defined('FRAUD_SCREEN_NAME_MISMATCH_POINTS') ? FRAUD_SCREEN_NAME_MISMATCH_POINTS : 0);
        if ($points <= 0) {
            return;
        }
        $bill_name = $this->personName($order->billing ?? []);
        $ship_name = $this->personName($order->delivery ?? []);
        // A virtual order has no delivery name, which is not a mismatch.
        if ($bill_name !== '' && $ship_name !== '' && $bill_name !== $ship_name) {
            $this->add($points, 'billing and delivery names differ');
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

    // ------------------------------------------------------------- trust ----

    /**
     * Why this order's customer is trusted, or '' when they aren't.
     *
     * Trust belongs to a signed-in account, never to an email address: at guest checkout
     * the email is whatever was typed, so trusting an address would let anyone skip the
     * screen by typing a good customer's. And never to a guest: One Page Checkout files
     * every guest order under one shared customer account, whose order history would
     * otherwise make every guest look like a long-standing customer.
     */
    protected function trustedReason(int $oID): string
    {
        $cid = (int)($_SESSION['customer_id'] ?? 0);
        if ($cid <= 0) {
            return '';
        }
        if (function_exists('zen_in_guest_checkout') && zen_in_guest_checkout()) {
            return '';
        }
        if (defined('CHECKOUT_ONE_GUEST_CUSTOMER_ID') && (int)CHECKOUT_ONE_GUEST_CUSTOMER_ID === $cid) {
            return '';
        }

        $listed = $this->csv(defined('FRAUD_SCREEN_TRUSTED_CUSTOMERS') ? FRAUD_SCREEN_TRUSTED_CUSTOMERS : '');
        if (in_array($cid, array_map('intval', $listed), true)) {
            return 'customer #' . $cid . ' is on the trusted list';
        }

        $needed = (int)(defined('FRAUD_SCREEN_TRUSTED_AFTER_ORDERS') ? FRAUD_SCREEN_TRUSTED_AFTER_ORDERS : 0);
        $statuses = array_values(array_filter(
            array_map('intval', $this->csv(defined('FRAUD_SCREEN_TRUSTED_STATUSES') ? FRAUD_SCREEN_TRUSTED_STATUSES : '')),
            static function ($id) {
                return $id > 0;
            }
        ));
        if ($needed <= 0 || $statuses === []) {
            return '';
        }
        global $db;
        if (!isset($db) || !is_object($db)) {
            return '';
        }
        $check = $db->Execute(
            "SELECT COUNT(*) AS total
               FROM " . TABLE_ORDERS . "
              WHERE customers_id = " . $cid . "
                AND orders_id <> " . $oID . "
                AND orders_status IN (" . implode(',', $statuses) . ")"
        );
        $total = $check->EOF ? 0 : (int)$check->fields['total'];
        if ($total >= $needed) {
            return 'customer #' . $cid . ' has ' . $total . ' completed orders';
        }
        return '';
    }

    // -------------------------------------------------------------- actions ----

    /** @return int the status the order was held in, 0 if it wasn't held */
    protected function holdOrder(int $oID, int $threshold, string $note, $order): int
    {
        global $db;

        $status = (int)(defined('FRAUD_SCREEN_HOLD_STATUS_ID') ? FRAUD_SCREEN_HOLD_STATUS_ID : 1);
        if ($status <= 0) {
            return 0;
        }

        $comment = sprintf(
            "FRAUD SCREEN: held for review, score %d of %d threshold.\nReasons: %s",
            $this->score,
            $threshold,
            implode('; ', $this->reasons)
        );
        if ($note !== '') {
            $comment .= "\n" . $note;
        }

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

        $this->notifyStaff($oID, $comment, $order);

        return $status;
    }

    protected function notifyStaff(int $oID, string $comment, $order): void
    {
        $to = trim((string)(defined('FRAUD_SCREEN_NOTIFY_EMAIL') ? FRAUD_SCREEN_NOTIFY_EMAIL : ''));
        if ($to === '' || !function_exists('zen_mail')) {
            return;
        }
        $store = defined('STORE_NAME') ? STORE_NAME : '';
        $subject = sprintf('Order #%d held by Fraud Screen (score %d)', $oID, $this->score);

        // Enough to find and judge the order without opening the admin first. The
        // storefront doesn't know the admin folder's name, so there's no link to it.
        $customer = is_array($order->customer ?? null) ? $order->customer : [];
        $info = is_array($order->info ?? null) ? $order->info : [];
        $name = trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? ''));
        $details = [sprintf('Order number %d, on %s.', $oID, $store)];
        if ($name !== '' || !empty($customer['email_address'])) {
            $details[] = 'Customer: ' . trim($name . ' ' . (empty($customer['email_address']) ? '' : '<' . $customer['email_address'] . '>'));
        }
        if (isset($info['total'])) {
            $details[] = 'Order total: ' . $this->money($info);
        }
        if (!empty($info['payment_method'])) {
            $details[] = 'Payment: ' . $info['payment_method'];
        }
        $body = $comment . "\n\n" . implode("\n", $details);

        $from = defined('EMAIL_FROM') ? EMAIL_FROM : $to;
        @zen_mail('', $to, $subject, $body, $store, $from, ['EMAIL_MESSAGE_HTML' => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))], 'default');
    }

    protected function verdict(bool $reached, bool $hold, string $trusted, bool $enabled): string
    {
        if (!$reached) {
            $verdict = 'passed';
        } elseif ($hold) {
            $verdict = 'HELD';
        } elseif ($trusted !== '') {
            $verdict = 'passed (trusted: ' . $trusted . ')';
        } elseif ($enabled) {
            $verdict = 'passed (released by an add-on)';
        } else {
            $verdict = 'WOULD HOLD';
        }
        return $enabled ? $verdict : $verdict . ' (dry run)';
    }

    /** One line per order. While switched off (the dry run) it says what would have happened. */
    protected function log(int $oID, string $verdict, int $threshold): void
    {
        $line = sprintf(
            "[%s] order #%d score=%d threshold=%d %s reasons=[%s]\n",
            date('Y-m-d H:i:s'),
            $oID,
            $this->score,
            $threshold,
            $verdict,
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

    /** First and last name, lowercased with the spacing evened out, for comparing. */
    protected function personName($address): string
    {
        if (!is_array($address)) {
            return '';
        }
        $name = trim(($address['firstname'] ?? '') . ' ' . ($address['lastname'] ?? ''));
        return strtolower(preg_replace('/\s+/', ' ', $name));
    }

    protected function money(array $info): string
    {
        global $currencies;
        if (isset($currencies) && is_object($currencies) && method_exists($currencies, 'format')) {
            return strip_tags($currencies->format($info['total'], true, (string)($info['currency'] ?? ''), $info['currency_value'] ?? ''));
        }
        return number_format((float)$info['total'], 2) . (empty($info['currency']) ? '' : ' ' . $info['currency']);
    }
}
