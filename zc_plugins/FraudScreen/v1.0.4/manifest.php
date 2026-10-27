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
// ReadMe | Settings | Forum Thread | GitHub: one row of buttons in Plugin Manager's info box,
// the pattern every free dbltoe plugin uses. The ReadMe URL is derived from this file's own
// on-disk location rather than a hardcoded version string, so it can't go stale on a future
// version bump. Zen Cart's shipped zc_plugins/.htaccess denies everything then explicitly
// re-allows .html, so readme.html is reachable by design.
//
$fsPluginRelativeDir = basename(dirname(__DIR__)) . '/' . basename(__DIR__);
$fsReadmeUrl = (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/') . 'zc_plugins/' . $fsPluginRelativeDir . '/readme.html';
$fsGithubUrl = 'https://github.com/dbltoe/fraud_screen';
// The opening post of the support thread (every free dbltoe plugin has this button).
$fsForumUrl = 'https://www.zen-cart.com/threads/207393?page=1#post-1347418';

// -----
// The Settings button: this plugin's Configuration page, shown only once the plugin is
// installed. Its gID is assigned at install, so it differs from store to store and can't be
// written here; it's read from the admin page the installer registers (page key
// configFraudScreen). The link is relative ("index.php?cmd=configuration&gID=N") because
// Plugin Manager is an admin page, so it survives a domain or admin-folder rename.
//
// Zen Cart reads manifests only on the Plugin Manager page, but the lookup is still guarded so
// a manifest read anywhere else (no database, catalog side, CLI) just leaves the button out.
// On v2.2 and later this file is re-read on every Plugin Manager visit, so the button follows
// the install state by itself; on v2.1 the installer saves the re-read description at Install,
// Upgrade and Uninstall (fsRefreshPluginDescription).
//
$fsSettingsUrl = '';
if (defined('IS_ADMIN_FLAG') && IS_ADMIN_FLAG === true && defined('TABLE_ADMIN_PAGES')
    && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
    $fsPage = $GLOBALS['db']->Execute(
        "SELECT main_page, page_params FROM " . TABLE_ADMIN_PAGES
        . " WHERE page_key = 'configFraudScreen' LIMIT 1"
    );
    if (!$fsPage->EOF && defined($fsPage->fields['main_page'])) {
        $fsSettingsUrl = 'index.php?cmd=' . constant($fsPage->fields['main_page'])
            . '&' . $fsPage->fields['page_params'];
    }
}

// -----
// Styled like Plugin Manager's own Install / Uninstall buttons. Spacing is inline, which beats
// any theme .btn margin: every gap is 4px (the wrapper supplies the space before the first
// button, each button the space after itself) and the side padding is 8px, so all four fit on
// one line from a 1366px-wide window up and the Author line stays where it was. Settings opens
// in the same tab (it's the admin itself); the others open a new one.
//
$fsButtonGap = '4px';
$fsButtonStyle = ' style="margin:0 ' . $fsButtonGap . ' 0 0;padding-left:8px;padding-right:8px"';
$fsButton = static function ($url, $label, $newTab) use ($fsButtonStyle) {
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
        . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '')
        . ' class="btn btn-primary" role="button"' . $fsButtonStyle . '>' . $label . '</a>';
};
$fsLinks =
    '<div style="margin:10px 0 0;padding:0 0 0 ' . $fsButtonGap . '">'
    . $fsButton($fsReadmeUrl, 'ReadMe', true)
    . ($fsSettingsUrl !== '' ? $fsButton($fsSettingsUrl, 'Settings', false) : '')
    . ($fsForumUrl !== '' ? $fsButton($fsForumUrl, 'Forum Thread', true) : '')
    . $fsButton($fsGithubUrl, 'GitHub', true)
    . '</div>';

return [
    'pluginVersion' => 'v1.0.4',
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
    // The Plugins Library's Plugin ID for Fraud Screen (zen-cart.com/plugins/fraud-screen). It must stay
    // an unquoted integer: the Library's stamper only matches a bare number, so a quoted value can't be
    // corrected in the copy it serves.
    'pluginId' => 2464,
    // -----
    // Uses only the notifier NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET and the
    // encapsulated-plugin installer helpers, both present since v2.1.0. Listed through the
    // v3.0.0 track since nothing here depends on version-specific core internals.
    //
    'zcVersions' => ['v2.1.0', 'v2.2.0', 'v2.2.1', 'v2.2.2', 'v2.3.0', 'v3.0.0'],
    'changelog' => 'readme.html',
    'github_repo' => $fsGithubUrl,
    'pluginGroups' => [],
];
