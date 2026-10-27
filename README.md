# cvilleglass.org

The Charlottesville Glass website, plus class payments (Stripe) and a small admin area.

- **`public/`** — the website. The static pages (`index.html`, `about.html`, …) plus:
  - `/pay/` — the class payment page people use
  - `/admin/` — who has paid, classes and prices, admins, keys
- **`app/`** — shared PHP code (not served to the web)
- **`data/`** — the database: payments, settings, keys. Created on the server, never in git.

It's plain PHP + SQLite with no other libraries, so it runs on the existing Apache server.

## Updating the site

On the server:

```bash
cd /var/www/cvilleglass
bash update.sh
```

That gets the latest from GitHub. Payments and settings in `data/` are never touched by an update.

## First-time server setup (once)

```bash
sudo mkdir -p /var/www/cvilleglass && sudo chown $USER /var/www/cvilleglass
git clone https://github.com/alibama/cvilleglass.git /var/www/cvilleglass
cd /var/www/cvilleglass
bash update.sh
```

The first run installs PHP if it's missing and offers to point Apache at `public/`. Say **y**. The old site folder stays where it was, and a backup of the Apache config is saved next to it.

## First sign-in checklist

Go to **cvilleglass.org/admin/** and sign in with **admin / password**, then:

1. **Settings & keys → Backup login**: change the password (and username if you like).
2. **Admins**: add your Google email.
3. **Settings & keys → Google sign-in**: add the Google keys (the steps are on that page). After that, anyone on the Admins list can sign in with Google.
4. **Settings & keys → Stripe**: paste the **test** secret key (`sk_test_…`) and set up the webhook (the exact URL and events are on that page).
5. **Classes**: check the names, prices, descriptions and dates. Everything starts as a **Draft**.
   - Monthly class $400 per month
   - Weekly class $100 per week
   - Special class $600 per person
6. While signed in, open **/pay/**. Drafts show up for you, so you can make a test payment with card `4242 4242 4242 4242` (any future date, any CVC). It should then appear under **Payments**.
7. Swap in the **live** Stripe key and live webhook secret, tick **Published** on each class, and share the link. Each class also has its own link on the Classes page.

In Stripe, turn on **Settings → Customer emails → Successful payments** so people get receipts. Money goes to the bank account linked in Stripe.

## Day to day

- **Payments** shows who has paid for each month. Use the arrows to move between months. Paying ahead shows up in future months, and weekly payments count toward the month the week starts in.
- **Record a cash or check payment** (on the Payments page) adds people who paid in person.
- **Download every payment as a spreadsheet** exports a CSV for the books.
- Refunds and disputes are handled in the Stripe dashboard. Each Stripe payment links straight to it.

## Handing it off

Add the new person's Google email under **Admins** and tell them the backup login. All keys are on the **Settings & keys** page, so nothing else needs to change hands. For the server itself, they only need `bash update.sh`.

To back up the payment records, copy `data/cvilleglass.sqlite`.

## For developers

- Preview on your own computer: `php -S localhost:8000 -t public`, then open http://localhost:8000/admin/. It makes its own `data/` folder.
- **Integrations** in the admin explains the read-only JSON API:
  - `GET /api/products.php` is public and lists published classes.
  - `GET /api/payments.php?month=YYYY-MM` needs `Authorization: Bearer <token>` and lists paid months and weeks.
- The same page has a spot for glassdatabase.org keys, saved now for the ornament time-slot sign-ups planned for later.
- The public pages' header and footer are copied in `app/layout.php`. If you change the nav in the `.html` pages, change it there too.
