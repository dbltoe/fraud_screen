**Fraud Prevention forum (forum 8), new thread:** NOT POSTED YET. Post after v1.0.2 is released (the dry-run sentence is only true from v1.0.2). Suggested title: A free plugin that holds suspicious orders for review

---

We've released **Fraud Screen**, a free plugin for Zen Cart 2.1.0 and later. It scores each new order against signals you choose and moves the ones that reach your threshold to a review status, with the reasons written into the order's history. The shopper never sees anything, so a false positive costs a delay instead of a sale.

It came out of a live store's run of stolen-card orders. The attacker changed the email address and IP address on every order, but reused the same phone numbers, went after one easily resold product, and billed to one state while shipping to another. Fraud Screen scores exactly those things: watched phone numbers, email patterns, billing and delivery in different states or countries, watched products, and a phone number or delivery address reused by a different customer. Your returning customers don't count against themselves.

It installs switched off, and you can run it as a dry run first: with logging on, it writes down what it would have held without holding anything.

Two things that apply whatever you use. Your payment gateway should be the first line, because it can refuse a card before it's charged; on Square that's Risk Manager, and the plugin's readme walks through the rules that would have caught this run. And don't rely on AVS and CVV checks alone: one of these orders passed both, because the card details were genuine, stolen along with the card.

Download, setup and support are in the support thread: https://www.zen-cart.com/threads/207393?page=1#post-1347418
