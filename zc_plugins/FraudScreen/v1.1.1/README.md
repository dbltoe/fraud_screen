# Fraud Screen

Version 1.1.1 · Zen Cart 2.1.0 through 3.0.0 · PHP 7.4 through 8.5 · GPL v3 · by My Zen Cart Host (dbltoe)

Scores each incoming order against configurable fraud signals and, when the score reaches your threshold, moves the order to a review status and records why.

Requires Zen Cart v2.1.0 or later. It is an encapsulated plugin: no core or template file is changed.

## What it does, and what it deliberately does not do

It screens the order **after** it has been created, not during checkout. That is a deliberate choice:

- The shopper never sees an error, so a false positive delays an order instead of losing a sale.
- The payment outcome is already known, so declined attempts are never screened.
- The evidence stays on the order for whoever reviews it.

It never blocks a shopper, never shows a message, and never emails the customer. The only visible effect is the order's status and a comment in its history.

## Why these signals

Written after a live store took a run of stolen-card orders across a month. The attacker changed the email address and the IP address on every single order — so blocking either was useless — but reused the same telephone numbers, targeted one specific low-value, easily-resold product, and always billed to one state while shipping to another.

Blocking used email addresses is the obvious response and it does not work: the store owner had already blocked two of the addresses, and the orders kept arriving under new ones.

That is the shape this plugin is built to catch.

## The rules

Each rule contributes points. An order is held when the total reaches the threshold. No single rule except the phone list is meant to trigger a hold on its own.

| Rule | Default | Notes |
| --- | --- | --- |
| Watched telephone numbers | 100 | Compared on digits, so formatting is irrelevant. The most reliable signal. |
| Email pattern match | 60 | Regular expressions you supply. Test them carefully. |
| Billing/delivery states differ | 30 | Perfectly normal for gifts — keep it well below the threshold. |
| Billing/delivery countries differ | 50 | Set to 0 if you ship internationally routinely. |
| Watched product model | 40 | Prefix match, so `CAS-08XXX-Z` also matches `CAS-08XXX-Z (SR4)`. |
| Repeat phone or delivery address | 35 | See below. |
| Billing/delivery names differ | 0 (off) | The billing name and the delivery name are different people. Gifts and work addresses trip it all the time, so if you use it keep it low (15–25) and let it add to other signals. A virtual order, with no delivery name, never counts. |

> **The velocity rule ignores your returning customers.** A phone number only counts if it appears on an earlier order under a *different email address*; a delivery address only counts if it was used under a different surname. A loyal customer ordering again from the same phone is the opposite of a red flag.

> **Never use a backslash in an email pattern.** Zen Cart strips backslashes from every configuration value as it is saved — `zen_db_prepare_input()` calls `stripslashes()` — so `\.` silently becomes `.` and your pattern quietly matches more than you intended. This is core behavior and affects every plugin, not just this one.

Use character classes instead, which need no escaping:

| Instead of | Write | Meaning |
| --- | --- | --- |
| `\.` | `[.]` | a literal dot |
| `\d` | `[0-9]` | a digit |
| `\w` | `[A-Za-z0-9_]` | a word character |
| `\s` | `[[:space:]]` | whitespace |

After saving, reopen the setting and confirm it reads back exactly as you typed it.

## Trusted customers

A screen that keeps holding a customer you know is genuine — a dropshipper, a regular who always ships to clients — is a screen that ends up switched off. Two settings exempt them. A trusted customer's order is still scored and logged (`passed (trusted: ...)`), but never held.

- **Trusted Customers**: customer ID numbers, comma-separated. The ID is the number in **Customers → Customers**, not an email address.
- **Trust After This Many Completed Orders**, with **Statuses That Count As Completed**: trusts a customer automatically once they have that many earlier orders in those statuses (3 is Delivered on a standard install). 0 turns it off, which is the default.

> **Only signed-in customers can be trusted.** At guest checkout the email address is whatever was typed, so trusting an address would let anyone skip the screen by typing a good customer's. Guest checkouts are never trusted, and that includes One Page Checkout's shared guest account, whose order history would otherwise make every guest look like a long-standing customer. Don't include your hold status among the completed statuses.

## Installing

1. Copy the `zc_plugins/FraudScreen` directory from the download into your store's `zc_plugins/` directory, so that it lands at `<store root>/zc_plugins/FraudScreen/v1.1.1/`. Nothing goes anywhere else.
2. In your admin, go to **Modules → Plugin Manager**, select **Fraud Screen** and click **Install**.

It installs switched **off**, so nothing is screened until you have set it up as described below.

Once it's installed, a superuser can open this guide from the **ReadMe** button in Plugin Manager.

To upgrade, put the new version's folder beside the old one in your store's `zc_plugins/FraudScreen/` directory and click **Upgrade** in Plugin Manager. Your settings and order history are kept.

## Setting it up

1. Install it as above. **It installs switched off.**
2. Fill in the rules under **Admin → Configuration → Fraud Screen**. The plugin's **Settings** button in Plugin Manager opens the same page.
3. Create a dedicated order status (Admin → Localization → Orders Status), such as `Fraud Review`, and select it as the hold status. Held orders are then trivial to find.
4. Set **Log Every Screening Decision** to `true` and leave the screen switched **off** for a few days. It writes what *would* have happened to `logs/fraud_screen.log`: each order's line ends `WOULD HOLD (dry run)` or `passed (dry run)`, with its score and reasons, and nothing is held.
5. Read that log. If it would have held orders you recognize as genuine, adjust the points or the threshold before going live.
6. Only then set **Enable Fraud Screen** to `true`.
7. Optional: put an address in **Notify This Address When An Order Is Held**. Every held order then sends it an email with the score, the reasons, the customer's name and email, the order total and the payment method. It's empty by default, so nothing is sent until you fill it in, and the store owner address isn't used automatically.

Step 4 matters. On the store this was written for, a dry run over 3,300 orders caught all eight known fraudulent orders and held nothing else — but that was only true after tuning. The first attempt held a legitimate Canadian customer shipping a gift into Vermont, because the velocity rule was penalizing her for having ordered before. That is what produced the returning-customer exemption described above.

## Suggested starting point

Leave the threshold at 100 and start with only the phone list populated, since a watched number scores 100 on its own. Add the email patterns and watched products once you have seen a few days of log output. Zero the country rule if international shipping is normal for you.

## It does not change how you take payment

A common question: if the store is set to capture funds immediately, does this plugin quietly turn sales into authorizations? **No.** It never touches your payment configuration, and it cannot — by the time it runs, the card has already been processed.

The sequence is fixed, in the same order on every release from 1.5.8 through 3.0.0 (line numbers from Zen Cart 2.2.2):

| Where | What happens |
| --- | --- |
| `includes/modules/checkout_process.php` line 122 | `$payment_modules->before_process()` — the card is charged or authorized |
| line 133 | the order record is created |
| line 138 | the order's products are written |
| `includes/modules/pages/checkout_process/header_php.php` line 16 | `$payment_modules->after_process()` — the payment module records its result, and may set the order's status |
| line 18 | `NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET` — **this plugin runs here** |

It runs after the payment module has finished on purpose. A module that sets the order's status in `after_process()` (the Authorize.Net Accept.js module does) would otherwise put a held order straight back to its paid status.

So the payment outcome is already settled. All this plugin does afterwards is change the order's status and write a comment.

### What that means for you

Your payment module's transaction mode decides what a held order actually costs you:

| Transaction mode | A held order means | Undoing it |
| --- | --- | --- |
| Purchase / Final Sale / capture | The money has already been taken. | You must issue a **refund** to the cardholder. Processing fees are often not returned, and the transaction still exists on the card. |
| Authorize | Funds are held against the card but not taken. | You can **void** the authorization. No money ever moves and there is nothing to charge back. |

> **A held order costs you least on a store set to Authorize.** Either way, the hold does the same job: it stops you shipping goods to a stolen card, which is where fraud does its real damage (the goods, then a chargeback and its fee). What differs is undoing the payment. On Authorize you void the authorization, so the charge never completes. On a capture store the payment is already in your account, so you refund it to the card it came from, which belongs to the real cardholder; depending on your gateway, you may not get the processing fee back.

Switching to Authorize is not free of consequence. Every legitimate order then needs capturing by hand, and authorizations expire — Square cancels an uncaptured online payment automatically after seven days (the square_webPay module's own setting says six, which is a safe habit to keep), and other gateways have their own windows. If nobody is going to review orders promptly, capture is the safer setting. Held orders still wait for review either way; on a capture store a fraudulent one is refunded to the cardholder rather than voided.

If you do run Authorize, set the hold status here to something your order-processing routine treats as "do not capture yet", so a held order is not swept up and captured by habit.

## Do this at your payment gateway first

This plugin catches a fraudulent order *after* the card has already been charged. Your payment gateway can refuse it before that happens, which is strictly better. If you take payments through Square, the tool is **Risk Manager** and it costs nothing to switch on.

In the Square Dashboard, go to **Payments & invoices → Risk Manager**, then **Rules → Create rule**. Give the rule a name, choose an action, pick the payment types and locations it applies to, add conditions, and click **Activate rule**. You will need to be the account owner or a team member granted Risk Manager permissions.

A rule can take one of three actions. Where several rules match, 3D Secure wins over Decline, and Decline wins over Alert.

| Action | Effect |
| --- | --- |
| 3D Secure | Challenges the shopper for extra verification, such as an SMS code or biometric. Shifts liability for the transaction. |
| Decline | Refuses the payment outright. |
| Alert | Lets the payment through but raises a risk notification. |

The conditions available include AVS mismatch or unavailable, invalid CVV, Square's own risk evaluation, payment amount thresholds, payment velocity within 24 hours, prepaid cards, international cards, buyer IP address outside your operating country, and shipping ZIP code mismatch.

### Which conditions actually help

> **Do not rely on AVS and CVV alone.** In the run of fraud this plugin was written for, one attempt came back from Square with `CVV_ACCEPTED` and `AVS_ACCEPTED` — the card details were entirely genuine, because they had been stolen along with the card. Only the issuing bank refused it. An AVS/CVV rule would have waved that one straight through.

Against that particular pattern, these were the conditions that would have mattered:

1. **Buyer IP address outside your operating country.** Every order in the group came from a foreign IP, several Brazilian, while the billing and delivery addresses were all domestic. Start this as *Alert*, check a week of genuine traffic for traveling customers and VPN users, then consider promoting it to 3D Secure.
2. **Shipping ZIP code mismatch.** Seven of the eight orders billed to one state and shipped to another. Legitimate for gifts, so pair it with something else or use Alert rather than Decline.
3. **Payment velocity.** Repeated attempts within 24 hours, which is exactly what card testing looks like.
4. **AVS mismatch and invalid CVV.** Worth having, but as a backstop rather than the main defense, for the reason above.

Square also provides a **Block List**, which bans a specific card from all future transactions, and an **Allow List** for trusted cards that should bypass checks. The Block List is far more durable than blocking an email address: an attacker generates a fresh mailbox in seconds, but a stolen card number is a finite resource.

To see what your rules have caught, go to **Orders & payments → Risk Manager → Blocked payments**. Risk Manager does not apply to Afterpay transactions.

Start every new rule on **Alert**. Watch it for a week against real orders before letting it decline anything — the cost of a wrong Decline is a lost sale from a real customer who will not try again.

## What it will not catch

A first offense from a new attacker with no history on your store scores nothing. This plugin recognizes patterns, so it earns its keep on the second and subsequent orders from the same group, not the first.

It is also no substitute for controls at your payment gateway. If your processor can decline on a failed address or security-code check, that stops the charge before it ever becomes an order, which is better than catching it afterwards. Use both.

## For developers: add-on notifiers

From v1.1.0 another plugin (or a store's own observer) can add rules and decide holds without touching Fraud Screen's files. It raises three notifiers of its own; attach to them as to any core notifier. The first parameter is an array, passed by value; the others are passed by reference.

| Notifier | When | Parameters |
| --- | --- | --- |
| `NOTIFY_FRAUD_SCREEN_SCORING` | After the built-in rules, before the threshold test. | `['orders_id', 'order']`; `&$score` (int); `&$reasons` (array of strings) |
| `NOTIFY_FRAUD_SCREEN_BEFORE_HOLD` | The score has reached the threshold, before anything is held. | `['orders_id', 'order', 'score', 'threshold', 'reasons', 'trusted', 'dry_run']`; `&$hold` (bool: set `false` to release); `&$note` (string added to the history comment) |
| `NOTIFY_FRAUD_SCREEN_HELD` | After the order has been held, its history written and the alert sent. | `['orders_id', 'score', 'threshold', 'reasons', 'status_id']` |

- Write reasons as the built-in ones are, `'what was found (+N)'`, so the log, the history and the alert read the same way.
- `trusted` is why the customer is trusted, or an empty string. A trusted order arrives with `$hold` already `false`.
- In the dry run (screen off, logging on) `$hold` arrives `false` and stays false whatever an add-on sets: the dry run never holds.
- An add-on that throws is caught and logged to `logs/fraud_screen.log` (when logging is on), and screening carries on without it, so a broken add-on can't stop a hold.

## Uninstalling

Uninstall through Plugin Manager. It removes its configuration keys and its configuration group. It does not alter any order it previously held; those keep the status and comments it applied.

## Support

Source, issues and releases are on GitHub (https://github.com/dbltoe/fraud_screen). Questions and problem reports go to the Fraud Screen support thread on the Zen Cart forum (https://www.zen-cart.com/threads/207393?page=1#post-1347418), which is also linked from the plugin's Plugin Manager panel.

## Version history

### v1.1.1 (2026-11-11)

- License: GNU General Public License, version 3 (was version 2).
- readme.html is replaced by this guide, README.md. The **ReadMe** button in Plugin Manager opens it inside the admin, for superusers only, in its own window; it is no longer a public file under `zc_plugins/`. The same README.md is at the root of the download.
- No screening or settings changes. Upgrade in Plugin Manager; your settings and order history are kept.

### v1.1.0 (2026-10-29)

- New rule, off by default: billing and delivery names differ. Set its points under **Configuration → Fraud Screen** if you want it.
- Trusted customers: list customer IDs whose orders are never held, or trust customers automatically after a number of completed orders. Signed-in accounts only; guest checkouts are never trusted. See *Trusted customers* above.
- The held-order alert email now names the customer and gives their email, the order total and the payment method.
- Three notifiers for add-ons to add rules and decide holds (see *For developers*).
- The log now says why a scored order wasn't held: `passed (trusted: ...)` or `passed (released by an add-on)`.
- Upgrade in Plugin Manager: the four new settings are added switched off, and every setting you already have keeps its value.

### v1.0.4 (2026-10-27)

- Plugin Manager only: once the plugin is installed, its panel has a **Settings** button that opens **Configuration → Fraud Screen**. It isn't shown before Install or after Uninstall. The installer saves the panel again at Install, Upgrade and Uninstall, because Zen Cart 2.1 otherwise keeps the panel it saw first.
- The buttons are one row: ReadMe | Settings | Forum Thread | GitHub. “Read Me” is now “ReadMe” and “Forum Support Thread” is now “Forum Thread”, a little narrower so all four fit on one line.
- No screening or settings changes. Upgrade in Plugin Manager; your settings and order history are kept.

### v1.0.3 (2026-10-06)

- Plugin ID is now 2464, the ID the Plugins Library assigned on 6 October 2026. Earlier releases carried a placeholder the Library couldn't update, so Plugin Manager had no way to tell you about updates.
- No screening, code or settings changes. Upgrading is optional and only matters for update notices.
- Upgrading: put `v1.0.3` beside `v1.0.2` in `zc_plugins/FraudScreen/` and click **Upgrade** in Plugin Manager; your settings and order history are kept. Zen Cart 2.2.0 and later pick up the new ID by themselves. Zen Cart 2.1.x keeps the ID it stored at first install, so on 2.1.x also run `UPDATE plugin_control SET zc_contrib_id = 2464 WHERE unique_key = 'FraudScreen';` **Admin → Tools → Install SQL Patches** adds your table prefix itself; in phpMyAdmin, add it to the table name (for example `zen_plugin_control`).

### v1.0.2

- Fixed: the dry run did nothing. With the screen switched off and **Log Every Screening Decision** on, as the setup steps say, no order was scored or logged. It now logs every order with what would have happened (`WOULD HOLD (dry run)` or `passed (dry run)`) and holds nothing. Switched on, nothing changes.
- A **Forum Support Thread** button beside Read Me and GitHub in Plugin Manager.
- Upgrade in Plugin Manager; no settings change.

### v1.0.1

- Fixed: a held order could be put straight back to its paid status. The hold was applied before the payment module's `after_process()`, and a module that sets the order's status there (the Authorize.Net Accept.js module does) overwrote it. Screening now runs at `NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET`, after the payment module has finished.
- Upgrade in Plugin Manager; no settings change.

### v1.0.0

- Initial release.
- Scores orders after creation against watched telephone numbers, email patterns, billing and delivery state and country mismatches, watched product models, and repeat use of a phone number or delivery address by a different customer.
- Holds orders reaching the threshold in a configurable order status and records the score and reasons in the order history.
- Optional alert email and optional per-order decision logging.
- Screening never interrupts checkout; all processing is wrapped so that any unexpected condition leaves the order exactly as it was.

## Changelog

See `changelog.txt` in the plugin folder. Version 1.1.1 moves the plugin to the GNU General Public License, version 3, and replaces readme.html with this guide, opened from the ReadMe button in Plugin Manager.

## License

Fraud Screen is free software under the GNU General Public License, version 3 (https://www.gnu.org/licenses/gpl-3.0.html); the full text is in `LICENSE`. Copyright © 2026 My Zen Cart Host (dbltoe).
