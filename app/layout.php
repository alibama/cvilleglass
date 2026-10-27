<?php
// Page chrome for the PHP pages. The public header/footer mirror the static
// pages in public/*.html — if the site nav changes there, change it here too.

const CVG_BRAND_MARK = '<svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="14" fill="#0e3e39"/><g style="mix-blend-mode:screen"><rect x="14" y="16" width="30" height="22" rx="5" fill="#a6c8b8" opacity="0.9"/><rect x="24" y="24" width="26" height="24" rx="5" fill="#c9ded4" opacity="0.75"/><rect x="18" y="30" width="22" height="18" rx="5" fill="#dd8226" opacity="0.85"/></g></svg>';

function page_head(string $title, bool $noindex = false): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <script>document.documentElement.classList.add("js");</script>
  <title><?= h($title) ?> — Charlottesville Glass</title>
  <?php if ($noindex): ?><meta name="robots" content="noindex" /><?php endif ?>
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;1,9..144,400&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="/assets/styles.css" />
  <link rel="stylesheet" href="/assets/pay.css" />
</head>
<?php
}

function flash_messages(): void
{
    foreach (take_flashes() as [$type, $message]) {
        echo '<p class="flash flash--' . h($type) . '" role="status">' . h($message) . '</p>';
    }
}

function public_header(string $title): void
{
    page_head($title);
    ?>
<body>
  <a class="skip" href="#main">Skip to content</a>
  <header class="site-header">
    <nav class="wrap nav" aria-label="Primary">
      <a class="brand" href="/" aria-label="Charlottesville Glass — home"><?= CVG_BRAND_MARK ?><span>Charlottesville&nbsp;<b>Glass</b></span></a>
      <button class="nav-toggle" aria-expanded="false" aria-controls="nav-links">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        Menu
      </button>
      <ul class="nav-links" id="nav-links">
        <li><a href="/about.html">About</a></li>
        <li><a href="/programs.html">Programs</a></li>
        <li><a href="/experiences.html">Experiences</a></li>
        <li><a href="/get-involved.html">Get involved</a></li>
        <li><a href="/contact.html">Contact</a></li>
        <li class="nav-cta"><a class="btn btn--amber" href="/get-involved.html#support">Support us</a></li>
      </ul>
    </nav>
  </header>
  <main id="main">
<?php
}

function public_footer(): void
{
    ?>
  </main>
  <footer class="site-footer">
    <div class="wrap footer-grid">
      <div>
        <a class="brand" href="/" aria-label="Charlottesville Glass — home"><?= CVG_BRAND_MARK ?><span>Charlottesville&nbsp;<b>Glass</b></span></a>
        <p style="margin-top:1rem;max-width:34ch;color:#c9ded4;">A nonprofit glassblowing studio — creation, communication, resilience, and emotional regulation, through glass.</p>
        <p style="color:#a6c8b8;">201 2nd St NW · Charlottesville, VA 22902</p>
      </div>
      <div>
        <h4>Explore</h4>
        <ul class="footer-links">
          <li><a href="/about.html">About</a></li>
          <li><a href="/programs.html">Programs</a></li>
          <li><a href="/experiences.html">Experiences</a></li>
          <li><a href="/get-involved.html">Get involved</a></li>
          <li><a href="/contact.html">Contact</a></li>
        </ul>
      </div>
      <div>
        <h4>Connect</h4>
        <ul class="footer-links">
          <li><a href="mailto:<?= h(contact_email()) ?>"><?= h(contact_email()) ?></a></li>
          <li><a href="/get-involved.html#support">Support &amp; donate</a></li>
          <li><a href="https://www.mcguffeyartcenter.com" target="_blank" rel="noopener">McGuffey Art Center &rarr;</a></li>
        </ul>
      </div>
    </div>
    <div class="wrap footer-note">
      <p>&copy; <span id="year"><?= date('Y') ?></span> Charlottesville Glass · cvilleglass.org</p>
    </div>
  </footer>
  <script src="/assets/main.js"></script>
  <script src="/assets/pay.js"></script>
</body>
</html>
<?php
}

function admin_header(string $title, string $active = ''): void
{
    page_head($title, true);
    $links = [
        'payments' => ['/admin/', 'Payments'],
        'classes' => ['/admin/classes.php', 'Classes'],
        'admins' => ['/admin/admins.php', 'Admins'],
        'settings' => ['/admin/settings.php', 'Settings & keys'],
        'integrations' => ['/admin/integrations.php', 'Integrations'],
    ];
    ?>
<body class="admin">
  <header class="admin-bar">
    <div class="wrap admin-bar-inner">
      <a class="brand" href="/admin/"><?= CVG_BRAND_MARK ?><span>Studio&nbsp;<b>admin</b></span></a>
      <?php if (current_admin()): ?>
        <nav class="admin-nav" aria-label="Admin">
          <?php foreach ($links as $key => [$href, $label]): ?>
            <a href="<?= $href ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $label ?></a>
          <?php endforeach ?>
          <a href="/pay/">Pay page ↗</a>
        </nav>
        <form class="admin-who" method="post" action="/admin/logout.php">
          <?= csrf_field() ?>
          <span><?= h(current_admin()) ?></span>
          <button class="linkish" type="submit">Sign out</button>
        </form>
      <?php endif ?>
    </div>
  </header>
  <main id="main" class="wrap admin-main">
    <?php if (current_admin() && using_default_password()): ?>
      <p class="flash flash--warn">The backup login is still <b>admin / password</b>. <a href="/admin/settings.php#password">Change it in Settings</a> before sharing the site.</p>
    <?php endif ?>
    <?php flash_messages() ?>
<?php
}

function admin_footer(): void
{
    ?>
  </main>
  <script src="/assets/pay.js"></script>
</body>
</html>
<?php
}
