<?php
/**
 * Fraud Screen - installer.
 *
 * Creates its own configuration group so the settings do not clutter an existing one,
 * and removes everything cleanly on uninstall.
 */

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    /** plugin_control.unique_key: the zc_plugins folder name. */
    public const PLUGIN_KEY = 'FraudScreen';

    protected function executeInstall()
    {
        $group_id = $this->getOrCreateConfigGroupId(
            'Fraud Screen',
            'Order screening rules used by the Fraud Screen plugin.'
        );

        $this->fsAddSettings($group_id);

        // -----
        // Register the configuration group on the admin's Configuration menu. Creating the
        // group and its keys is not enough on its own - without this row the settings exist
        // in the database but there is no way to reach them from the menu.
        //
        if (function_exists('zen_register_admin_page') && function_exists('zen_page_key_exists')) {
            if (!zen_page_key_exists('configFraudScreen')) {
                zen_register_admin_page(
                    'configFraudScreen',
                    'BOX_CONFIGURATION_FRAUD_SCREEN',
                    'FILENAME_CONFIGURATION',
                    'gID=' . $group_id,
                    'configuration',
                    'Y',
                    $group_id
                );
            }
        }

        // Last, so the Settings button finds the page registered above.
        $this->fsRefreshPluginDescription();

        return true;
    }

    /**
     * Every setting, for Install and Upgrade alike. Core's addConfigurationKey() skips a
     * key that already exists, so on Upgrade only the settings new in this version are
     * added and every value the store has already set is left alone.
     */
    protected function fsAddSettings(int $group_id): void
    {
        $on_off ='zen_cfg_select_option([\'true\', \'false\'], ';

        // Deliberately installs switched OFF. The signals below need tuning to your own
        // order patterns first - a shop that ships internationally as a matter of course,
        // for instance, should zero the country rule before enabling anything.
        $this->addConfigurationKey('FRAUD_SCREEN_STATUS', [
            'configuration_title' => 'Enable Fraud Screen?',
            'configuration_value' => 'false',
            'configuration_description' => 'Screen incoming orders and hold those that score at or above the threshold. <strong>Installs switched off on purpose.</strong> Configure the rules below, run with "Log Every Screening Decision" set to <code>true</code> for a few days to see what <em>would</em> have been held, and only then set this to <code>true</code>.',
            'configuration_group_id' => $group_id,
            'sort_order' => 10,
            'set_function' => $on_off,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_THRESHOLD', [
            'configuration_title' => 'Score Threshold',
            'configuration_value' => '100',
            'configuration_description' => 'An order is held when its total score reaches this number. Each rule below contributes points. Raise this to hold fewer orders, lower it to hold more.',
            'configuration_group_id' => $group_id,
            'sort_order' => 20,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_HOLD_STATUS_ID', [
            'configuration_title' => 'Order Status For Held Orders',
            'configuration_value' => '1',
            'configuration_description' => 'Orders that reach the threshold are moved to this order status. Create a dedicated status such as "Fraud Review" so held orders are easy to find.',
            'configuration_group_id' => $group_id,
            'sort_order' => 30,
            'use_function' => 'zen_get_order_status_name',
            'set_function' => 'zen_cfg_pull_down_order_statuses(',
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_BLOCKED_PHONES', [
            'configuration_title' => 'Watched Telephone Numbers',
            'configuration_value' => '',
            'configuration_description' => 'Comma-separated telephone numbers. Digits are compared, so formatting does not matter. Fraud rings routinely change email and IP but reuse phone numbers, which makes this the single most reliable signal.',
            'configuration_group_id' => $group_id,
            'sort_order' => 40,
            'set_function' => 'zen_cfg_textarea(',
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_BLOCKED_PHONES_POINTS', [
            'configuration_title' => 'Points: Watched Telephone',
            'configuration_value' => '100',
            'configuration_description' => 'Points added when the order telephone matches the watched list.',
            'configuration_group_id' => $group_id,
            'sort_order' => 45,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_EMAIL_PATTERNS', [
            'configuration_title' => 'Email Patterns',
            'configuration_value' => '',
            'configuration_description' => 'Comma-separated regular expressions matched against the order email, without delimiters. Example, for machine-generated addresses such as <code>Jayleen2Etta55@outlook.com</code>:<br><code>^[A-Za-z]+[0-9]+[A-Za-z]+[0-9]*@outlook[.]com$</code><br><strong>Do not use backslashes.</strong> Zen Cart strips them from every configuration value on save (<code>zen_db_prepare_input()</code> calls <code>stripslashes()</code>), so <code>\\.</code> silently becomes <code>.</code>. Use a character class instead: <code>[.]</code> for a literal dot, <code>[0-9]</code> rather than <code>\\d</code>, <code>[[:space:]]</code> rather than <code>\\s</code>.<br>Test carefully - a loose pattern will hold real customers.',
            'configuration_group_id' => $group_id,
            'sort_order' => 50,
            'set_function' => 'zen_cfg_textarea(',
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_EMAIL_PATTERNS_POINTS', [
            'configuration_title' => 'Points: Email Pattern',
            'configuration_value' => '60',
            'configuration_description' => 'Points added when the order email matches one of the patterns.',
            'configuration_group_id' => $group_id,
            'sort_order' => 55,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_STATE_MISMATCH_POINTS', [
            'configuration_title' => 'Points: Billing And Delivery States Differ',
            'configuration_value' => '30',
            'configuration_description' => 'Points added when the order bills to one state and ships to another. Normal for gifts, so keep this well below the threshold on its own. Set to 0 to ignore.',
            'configuration_group_id' => $group_id,
            'sort_order' => 60,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_COUNTRY_MISMATCH_POINTS', [
            'configuration_title' => 'Points: Billing And Delivery Countries Differ',
            'configuration_value' => '50',
            'configuration_description' => 'Points added when billing and delivery countries differ. Set to 0 if you ship internationally as a matter of course.',
            'configuration_group_id' => $group_id,
            'sort_order' => 65,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_WATCHED_MODELS', [
            'configuration_title' => 'Watched Product Models',
            'configuration_value' => '',
            'configuration_description' => 'Comma-separated product model numbers being targeted. Matched as a prefix, so <code>CAS-08XXX-Z</code> also matches <code>CAS-08XXX-Z (SR4)</code>. Small, light, easily resold items are the usual targets.',
            'configuration_group_id' => $group_id,
            'sort_order' => 70,
            'set_function' => 'zen_cfg_textarea(',
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_WATCHED_MODELS_POINTS', [
            'configuration_title' => 'Points: Watched Product',
            'configuration_value' => '40',
            'configuration_description' => 'Points added when the order contains a watched product. Meant to combine with other signals rather than trigger on its own.',
            'configuration_group_id' => $group_id,
            'sort_order' => 75,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_VELOCITY_DAYS', [
            'configuration_title' => 'Velocity: Days To Look Back',
            'configuration_value' => '30',
            'configuration_description' => 'How many days of order history to consider when checking whether this telephone number or delivery address has been used before. Set to 0 to disable velocity checks.',
            'configuration_group_id' => $group_id,
            'sort_order' => 80,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_VELOCITY_POINTS', [
            'configuration_title' => 'Points: Repeat Phone Or Address',
            'configuration_value' => '35',
            'configuration_description' => 'Points added when the same telephone number, or the same delivery address under a different surname, appears on an earlier order inside the look-back window.',
            'configuration_group_id' => $group_id,
            'sort_order' => 85,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_NOTIFY_EMAIL', [
            'configuration_title' => 'Notify This Address When An Order Is Held',
            'configuration_value' => '',
            'configuration_description' => 'Optional. Email address to alert when an order is held. Leave empty for no alert. The store owner address is not used automatically, so alerts can go to whoever reviews orders.',
            'configuration_group_id' => $group_id,
            'sort_order' => 90,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_LOG', [
            'configuration_title' => 'Log Every Screening Decision?',
            'configuration_value' => 'false',
            'configuration_description' => 'When <code>true</code>, writes a line to the logs directory for every order screened, held or not. Useful while tuning the points; turn it off once settled.',
            'configuration_group_id' => $group_id,
            'sort_order' => 100,
            'set_function' => $on_off,
        ]);

        // ----- Added in v1.1.0.

        $this->addConfigurationKey('FRAUD_SCREEN_NAME_MISMATCH_POINTS', [
            'configuration_title' => 'Points: Billing And Delivery Names Differ',
            'configuration_value' => '0',
            'configuration_description' => 'Points when the billing name and the delivery name are different people. 0 turns the rule off. Gifts and work addresses trip it all the time, so keep it low (15-25) and let it add to other signals rather than hold an order on its own. Virtual orders, with no delivery name, never count.',
            'configuration_group_id' => $group_id,
            'sort_order' => 66,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_TRUSTED_CUSTOMERS', [
            'configuration_title' => 'Trusted Customers',
            'configuration_value' => '',
            'configuration_description' => 'Comma-separated customer ID numbers (the ID in Customers > Customers, not an email address) whose orders are never held. They are still scored, and logged as trusted. Only signed-in customers can be trusted: a guest checkout never is, since anyone can type any email address.',
            'configuration_group_id' => $group_id,
            'sort_order' => 92,
            'set_function' => 'zen_cfg_textarea(',
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_TRUSTED_AFTER_ORDERS', [
            'configuration_title' => 'Trust After This Many Completed Orders',
            'configuration_value' => '0',
            'configuration_description' => 'Trust a signed-in customer automatically once they have this many earlier orders in the statuses below. 0 turns it off. Guest checkouts never count, including One Page Checkout\'s shared guest account.',
            'configuration_group_id' => $group_id,
            'sort_order' => 94,
        ]);

        $this->addConfigurationKey('FRAUD_SCREEN_TRUSTED_STATUSES', [
            'configuration_title' => 'Statuses That Count As Completed',
            'configuration_value' => '3',
            'configuration_description' => 'Comma-separated order status ID numbers that count toward automatic trust. 3 is Delivered on a standard install; see Localization > Orders Status for yours. Never include the hold status.',
            'configuration_group_id' => $group_id,
            'sort_order' => 96,
        ]);
    }

    /**
     * Adds the settings new since the installed version (existing values are kept) and
     * saves the Plugin Manager panel again (see fsRefreshPluginDescription()). Core's
     * own executeUpgrade() returns true, and so does this.
     */
    protected function executeUpgrade($oldVersion = null)
    {
        $group_id = $this->getOrCreateConfigGroupId(
            'Fraud Screen',
            'Order screening rules used by the Fraud Screen plugin.'
        );
        $this->fsAddSettings($group_id);

        $this->fsRefreshPluginDescription();

        return true;
    }

    protected function executeUninstall()
    {
        $this->deleteConfigurationKeys([
            'FRAUD_SCREEN_STATUS',
            'FRAUD_SCREEN_THRESHOLD',
            'FRAUD_SCREEN_HOLD_STATUS_ID',
            'FRAUD_SCREEN_BLOCKED_PHONES',
            'FRAUD_SCREEN_BLOCKED_PHONES_POINTS',
            'FRAUD_SCREEN_EMAIL_PATTERNS',
            'FRAUD_SCREEN_EMAIL_PATTERNS_POINTS',
            'FRAUD_SCREEN_STATE_MISMATCH_POINTS',
            'FRAUD_SCREEN_COUNTRY_MISMATCH_POINTS',
            'FRAUD_SCREEN_WATCHED_MODELS',
            'FRAUD_SCREEN_WATCHED_MODELS_POINTS',
            'FRAUD_SCREEN_VELOCITY_DAYS',
            'FRAUD_SCREEN_VELOCITY_POINTS',
            'FRAUD_SCREEN_NOTIFY_EMAIL',
            'FRAUD_SCREEN_LOG',
            'FRAUD_SCREEN_NAME_MISMATCH_POINTS',
            'FRAUD_SCREEN_TRUSTED_CUSTOMERS',
            'FRAUD_SCREEN_TRUSTED_AFTER_ORDERS',
            'FRAUD_SCREEN_TRUSTED_STATUSES',
        ]);

        if (function_exists('zen_deregister_admin_pages')) {
            zen_deregister_admin_pages(['configFraudScreen']);
        }

        $this->deleteConfigurationGroup('Fraud Screen', true);

        // After the admin page is gone, so the saved panel has no Settings button.
        $this->fsRefreshPluginDescription();

        return true;
    }

    /**
     * Save the manifest's description again, now that the Settings page has
     * been registered (install, upgrade) or removed (uninstall).
     *
     * Needed on v2.1 (and v1.5.8, v2.0), which store plugin_control.description
     * only when Plugin Manager first sees the plugin, before Install, so the
     * Settings button (built in manifest.php from the registered admin page)
     * would never appear there. v2.2 and later rewrite the description from
     * the manifest on every Plugin Manager visit, so this is a no-op there in
     * effect. Core's own install/uninstall step changes only status and
     * version, so this description stays.
     *
     * @return bool
     */
    protected function fsRefreshPluginDescription()
    {
        if (!defined('TABLE_PLUGIN_CONTROL')) {
            return true;
        }
        $manifest = require dirname(__DIR__) . '/manifest.php';
        if (!is_array($manifest) || !isset($manifest['pluginDescription'])) {
            return true;
        }

        return $this->executeInstallerSql(
            "UPDATE " . TABLE_PLUGIN_CONTROL . "
                SET description = '" . $this->dbConn->prepare_input($manifest['pluginDescription']) . "'
              WHERE unique_key = '" . $this->dbConn->prepare_input(self::PLUGIN_KEY) . "'
              LIMIT 1"
        );
    }
}
