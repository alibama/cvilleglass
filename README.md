# cvilleglass.org

The Charlottesville Glass website, plus payments for shop rental and classes (Stripe) and a small admin area.

- **`public/`** — the website. The static pages (`index.html`, `about.html`, …) plus:
  - `/pay/` — the payment page for shop rental and classes
  - `/admin/` — who has paid, rentals, classes and prices, admins, keys
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
5. **Rentals & classes**: check the names, prices, descriptions and dates. Everything starts as a **Draft**.
   - Monthly shop rental $400 per month
   - Weekly shop rental $100 per week
   - Special class $600 per person
   - Intro class $150 per person

   Monthly and weekly items appear under **Shop rental** on the pay page. Per-person items appear under a separate **Classes** section, which only shows once a class is published.
6. While signed in, open **/pay/**. Drafts show up for you, so you can make a test payment with card `4242 4242 4242 4242` (any future date, any CVC). It should then appear under **Payments**.
7. Swap in the **live** Stripe key and live webhook secret, tick **Published** on each item you want people to see, and share the link. Each item also has its own link on the Rentals & classes page.

8. **Settings & keys → Contact form**: messages from the contact page go to ansondparker@gmail.com (change it there). Add a Gmail address and an app password as the sending account, then click **Send a test email**. Without one, mail from the server usually doesn't arrive. Every message is also kept under **Messages** either way.

In Stripe, turn on **Settings → Customer emails → Successful payments** so people get receipts. Money goes to the bank account linked in Stripe.

## Day to day

- **Payments** shows who has paid for each month. Use the arrows to move between months. Paying ahead shows up in future months, and weekly payments count toward the month the week starts in.
- **Record a cash or check payment** (on the Payments page) adds people who paid in person.
- **Download every payment as a spreadsheet** exports a CSV for the books.
- **Messages** lists everything sent through the contact form. It marks any that couldn't be emailed.
- Refunds and disputes are handled in the Stripe dashboard. Each Stripe payment links straight to it.

## Handing it off

Add the new person's Google email under **Admins** and tell them the backup login. All keys are on the **Settings & keys** page, so nothing else needs to change hands. For the server itself, they only need `bash update.sh`.

To back up the payment records, copy `data/cvilleglass.sqlite`.

## For developers

- Preview on your own computer: `php -S localhost:8000 -t public`, then open http://localhost:8000/admin/. It makes its own `data/` folder.
- **Integrations** in the admin explains the read-only JSON API:
  - `GET /api/products.php` is public and lists published shop rental and classes.
  - `GET /api/payments.php?month=YYYY-MM` needs `Authorization: Bearer <token>` and lists paid months and weeks.
- The same page has a spot for glassdatabase.org keys, saved now for the ornament time-slot sign-ups planned for later.
- The public pages' header and footer are copied in `app/layout.php`. If you change the nav in the `.html` pages, change it there too.

## Residency pages

Each resident edits their own page: their bio and a write-up of the residency (a simple editor that handles text pasted from Word or Google Docs), a headshot, work photos with captions, a CV (PDF), a link to their glassdatabase.org profile, and their **glass lineage**, meaning who they studied under, assisted, or were assisted by.

1. **Admin → Residents → Add a resident** (name, email, year). Their page starts as a draft.
2. Click **Email them an edit link**, or **Make a new link to copy** and send it yourself. The link signs them in with no password. Making a new link turns off the old one.
3. Tick **Published** when the page is ready. After that, their changes go live straight away, and you get an email (at most once an hour) when someone updates a page.
4. **Edit page yourself** opens the same editor for you.

The pages live at `/residency/` (program and all residents) and `/residency/artist.php?r=name`. The old `residency.html` redirects there. Photos and CVs are stored in `data/uploads/`, so back that folder up along with the database. `update.sh` raises PHP's upload limit to 16 MB per file the first time it runs. Photos are also shrunk in the browser before uploading, which removes phone location data.

The lineage and glassdatabase.org links are published as JSON at `/api/residents.php`, in the same fields as glassdatabase.org's lineage form. Admin → Integrations has the details.

The rich-text editor is [Quill](https://quilljs.com) 2.0.3, loaded from jsDelivr with an integrity hash. Everything it sends is cleaned on the server (`clean_html()` in `app/residents.php`), so only paragraphs, headings, bold/italic, lists, quotes and links are kept.
