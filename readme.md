# Zen Cart: Fraud Screen v1.0.0

Scores each incoming order against configurable fraud signals and, when the score reaches your
threshold, moves the order to a review status and records why.

Screening happens **after** the order is created rather than during checkout, so the shopper never
sees an error and a false positive delays an order instead of losing a sale.

Compatible with Zen Cart v2.1.0 through the current v3.0.0-track `master`, packaged as an
encapsulated plugin (`zc_plugins/`). See `zc_plugins/FraudScreen/v1.0.0/readme.html` for full
installation instructions, tuning guidance and version history.

## Why it exists

Written after a live store took a run of stolen-card orders across a month. The attacker changed the
email address and the IP address on every single order — so blocking either was useless — but reused
the same telephone numbers, targeted one specific low-value, easily-resold product, and always billed
to one state while shipping to another.

Blocking used email addresses is the obvious response and it does not work; the store owner had
already blocked two of the addresses and the orders kept arriving under new ones.

## Signals

| Rule | Default points |
|---|---|
| Watched telephone numbers | 100 |
| Email pattern match | 60 |
| Billing/delivery countries differ | 50 |
| Watched product model | 40 |
| Repeat phone or delivery address by a *different* customer | 35 |
| Billing/delivery states differ | 30 |

An order is held once the total reaches the threshold (default 100).

The velocity rule deliberately ignores returning customers: a phone number only counts if it appears
on an earlier order under a different email address, and a delivery address only if used under a
different surname.

## Measured against real data

Dry-run over 3,300 orders on the store it was written for:

- all 8 known fraudulent orders caught
- 0 false positives

That was only true after tuning. The first attempt flagged a legitimate Canadian customer shipping a
gift into Vermont, because the velocity rule penalised her for having ordered before — which is what
produced the returning-customer exemption.

## Installing

Copy the `zc_plugins/FraudScreen` directory into your store's `zc_plugins/`, then install through
**Admin → Modules → Plugin Manager**.

It installs switched **off**. Configure the rules, run in log-only mode for a few days to see what
would have been held, then enable it.

## Licence

GNU GPL v2.0, consistent with Zen Cart.
