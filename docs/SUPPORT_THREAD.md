**Support thread:** https://www.zen-cart.com/threads/207393?page=1#post-1347418 ("Fraud Screen [Support Thread]", All Other Contributions/Addons), posted by John 2026-10-05. Opening post below, as drafted; rendering checked logged out.

---

**Fraud Screen** scores each new order against fraud signals you choose and, when the score reaches your threshold, moves the order to a review status and writes the reasons into the order's history. It never blocks a shopper, never shows a message and never emails the customer, so a false positive delays an order instead of losing a sale.

Free and encapsulated (zc_plugins), for Zen Cart 2.1.0 and later. Download and source: https://github.com/dbltoe/fraud_screen/releases/latest

**Why it exists**

We wrote it after a live store took a run of stolen-card orders over a month. The attacker changed the email address and IP address on every order, so blocking either was useless, but reused the same telephone numbers, went after one low-priced, easily resold product, and always billed to one state while shipping to another. That's the pattern it's built to catch.

**The rules**

Each rule adds points, and an order is held when its total reaches the threshold (100 by default). Only the phone list is meant to hold an order on its own.

**Watched telephone numbers** (100 points): compared on digits only, so formatting doesn't matter. This is the most reliable signal, because fraud rings change email and IP but reuse phone numbers.

**Email patterns** (60): regular expressions you supply, for machine-generated addresses.

**Billing and delivery states differ** (30): normal for gifts, so it's kept well below the threshold.

**Billing and delivery countries differ** (50): set it to 0 if you ship internationally as a matter of course.

**Watched product models** (40): a prefix match, for the items being targeted.

**Repeat phone or delivery address** (35): a phone number used on an earlier order under a different email address, or a delivery address used under a different surname, within the look-back window (30 days). Your returning customers don't count against themselves.

**Setting it up**

It installs switched off. Fill in the rules under Configuration > Fraud Screen, create a dedicated order status such as "Fraud Review" (Localization > Orders Status) and select it as the hold status, then set Enable Fraud Screen to true. We suggest starting with the threshold at 100 and only the phone list filled in, with Log Every Screening Decision set to true: every order's score and reasons go to logs/fraud_screen.log, so you can see how your real orders score before you add the other rules.

Don't use a backslash in an email pattern. Zen Cart strips backslashes from every configuration value when it's saved, so the pattern quietly matches more than you meant. Use character classes instead: `[.]` for a dot and `[0-9]` for a digit.

**When it runs, and what it doesn't change**

It screens the order at `NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET`, after the payment module has finished, so the card has already been charged or authorized and your payment settings are never touched. Either way, the hold stops you shipping goods to a stolen card, which is where fraud does its real damage. On a store set to Authorize you void a held order's authorization; on a capture store you refund the charge to the card it came from, which belongs to the real cardholder.

Version 1.0.1 moved it to that point. Version 1.0.0 ran earlier, and a payment module that sets the order's status in `after_process()` (our Authorize.Net Accept.js module does) put a held order straight back to its paid status. If you have 1.0.0, please upgrade.

**Your payment gateway comes first**

Fraud Screen catches an order after it's been charged. Your gateway can refuse it before that, which is better. On Square that's Risk Manager, and the readme walks through the rules that would have stopped the run of fraud this plugin was written for. Use both.

**What it won't catch**

A first order from a new attacker scores nothing. It recognizes patterns, so it earns its keep on the second and later orders from the same group.

Questions, reports and suggestions are welcome here.
