# Fraud Screen v1.0.2: Plugins Library submission

Form: https://www.zen-cart.com/plugins/submit

**SUBMITTED 2026-10-05** by Claude in Chrome (signed in as dbltoe) at John's request: "Your plugin and initial release have been submitted and are pending review." Support thread given as a pasted link. On acceptance the Library writes the real pluginId into its served copy of the zip; read the Plugin ID off the listing page.

| Field | Value |
|---|---|
| Name | Fraud Screen |
| Type | Other Modules |
| GitHub Repository URL | https://github.com/dbltoe/fraud_screen |
| Forum Support Thread | https://www.zen-cart.com/threads/207393 (link existing) |
| Release Version | v1.0.2 |
| ZIP | fraud_screen_v1.0.2.zip (the GitHub release v1.0.2 asset, swapped 2026-10-05 for the manifest that lists 2.3.0; sha256 fb2241ad...) |
| Encapsulated? | Yes |
| Compatible Zen Cart versions | 2.1.0, 2.2.0, 2.2.1, 2.2.2, 2.3.0, 3.0.0 (as the manifest declares) |

## Description

Scores each new order against fraud signals you choose and, when the score reaches your threshold, moves the order to a review status and writes the reasons into the order's history. It never blocks a shopper, never shows a message and never emails the customer, so a false positive delays an order instead of losing a sale.

Written after a live store took a run of stolen-card orders: the attacker changed email and IP on every order but reused phone numbers, targeted one easily resold product, and billed to one state while shipping to another. The rules score exactly that:

- **Watched telephone numbers**, compared on digits only (the most reliable signal).
- **Email patterns** you supply, for machine-generated addresses.
- **Billing and delivery in different states or countries.**
- **Watched product models**, matched as a prefix.
- **A phone number or delivery address reused by a different customer** within a look-back window. Returning customers don't count against themselves.

Each rule adds points you set; an order is held when its total reaches the threshold. It runs after the payment module has finished, so it never changes how you take payment, and a hold can't be undone by a payment module that sets the order's status.

Installs switched off. Run it as a dry run first: with logging on, it writes what it would have held without holding anything. An optional alert email goes to whoever reviews orders.

Encapsulated; no core or template file is changed. Full documentation (readme.html) inside the plugin, including how to set up Square's Risk Manager, which can refuse a card before it's charged.

## Release Changelog

First release on the Plugins Library. Includes the fixes made since the plugin was first written: holds are applied after the payment module's after_process(), so a module that sets the order's status there (Authorize.Net Accept.js does) no longer puts a held order back to its paid status (v1.0.1), and the dry run now logs what would have been held while the screen is switched off (v1.0.2). Adds a Forum Support Thread button in Plugin Manager.
