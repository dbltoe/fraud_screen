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
    protected function executeInstall()
    {
        $group_id = $this->getOrCreateConfigGroupId(
            'Fraud Screen',
            'Order screening rules used by the Fraud Screen plugin.'
        );

        $on_off = 'zen_cfg_select_option([\'true\', \'false\'], ';

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
            'configuration_description' => 'Comma-separated regular expressions matched against the order email, without delimiters. Example, for machine-generated addresses such as <code>Jayleen2Etta55@outlook.com</code>:<br><code>^[A-Za-z]+[0-9]+[A-Za-z]+[0-9]*@outlook\\.com$</code><br>Test carefully - a loose pattern will hold real customers.',
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
        ]);

        $this->deleteConfigurationGroup('Fraud Screen', true);

        return true;
    }
}
