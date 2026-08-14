<?php
/**
 * @package Fraud Screen
 * @subpackage plugins
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://zen-cart.com GNU Public License V2.0
 * @version $Id: manifest.php 2026-08-13 19:40:00Z dbltoe $
 */
// -----
// Read Me / GitHub buttons shown in the Plugin Manager's info box, matching the pattern
// established for Admin Add Customer and Social Contact Footer. The Read Me URL is derived
// from this file's own on-disk location rather than a hardcoded version string, so it
// can't go stale on a future version bump. Zen Cart's shipped zc_plugins/.htaccess denies
// everything then explicitly re-allows .html, so readme.html is reachable by design.
//
$fsPluginRelativeDir = basename(dirname(__DIR__)) . '/' . basename(__DIR__);
$fsReadmeUrl = (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/') . 'zc_plugins/' . $fsPluginRelativeDir . '/readme.html';
$fsGithubUrl = 'https://github.com/dbltoe/fraud_screen';
$fsButtonGap = '6px';
$fsLinks =
    '<div style="margin:10px 0 0;padding:0 0 0 ' . $fsButtonGap . '">'
    . '<a href="' . $fsReadmeUrl . '" target="_blank" rel="noopener noreferrer"'
    . ' class="btn btn-primary" role="button"'
    . ' style="margin:0 ' . $fsButtonGap . ' 0 0">Read Me</a>'
    . '<a href="' . $fsGithubUrl . '" target="_blank" rel="noopener noreferrer"'
    . ' class="btn btn-primary" role="button"'
    . ' style="margin:0 ' . $fsButtonGap . ' 0 0">GitHub</a>'
    . '</div>';

return [
    'pluginVersion' => 'v1.0.0',
    'pluginName' => 'Fraud Screen',
    'pluginDescription' =>
        'Scores each incoming order against configurable fraud signals and, when the score reaches your '
        . 'threshold, moves the order to a review status and records why. Screening happens after the order '
        . 'is created rather than during checkout, so the shopper never sees an error and a false positive '
        . 'delays an order instead of losing a sale.<br><br>'
        . 'Signals: reused telephone numbers, email addresses matching patterns you supply, billing and '
        . 'delivery in different states or countries, orders containing products you are being targeted on, '
        . 'and repeat use of a phone number or delivery address by a <em>different</em> customer.<br><br>'
        . 'Installs switched off. Configure the rules, run in log-only mode for a few days to see what would '
        . 'have been held, then enable it.' . $fsLinks,
    'pluginAuthor' => 'My Zen Cart Host (dbltoe)',
    'pluginId' => '0',  // assigned once the Zen Cart forum thread exists
    // -----
    // Uses only the notifier NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS and the
    // encapsulated-plugin installer helpers, both present since v2.1.0. Listed through the
    // v3.0.0 track since nothing here depends on version-specific core internals.
    //
    'zcVersions' => ['v2.1.0', 'v2.2.0', 'v2.2.1', 'v2.2.2', 'v3.0.0'],
    'changelog' => 'readme.html',
    'github_repo' => $fsGithubUrl,
    'pluginGroups' => [],
];
