<?php
// Charlottesville Glass — shared setup for every PHP page.
// Keys, passwords and prices live in the database (edit them under /admin/),
// so nothing secret is ever stored in this repository.

declare(strict_types=1);

date_default_timezone_set('America/New_York');

const CVG_MONTHS_AHEAD = 6;        // how many months someone can pay for at once
const CVG_WEEKS_AHEAD = 8;         // ...and how many weeks
const CVG_DEFAULT_USER = 'admin';  // backup login until changed in Settings
const CVG_DEFAULT_PASSWORD = 'password';

define('CVG_ROOT', dirname(__DIR__));
define('CVG_DATA', getenv('CVG_DATA_DIR') ?: CVG_ROOT . '/data');

require __DIR__ . '/stripe.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';

// ---------- database ----------

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        if (!is_dir(CVG_DATA)) @mkdir(CVG_DATA, 0770, true);
        $pdo = new PDO('sqlite:' . CVG_DATA . '/cvilleglass.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
        migrate($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        exit('The site database could not be opened. On the server, run: bash update.sh');
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS products (
            id          INTEGER PRIMARY KEY,
            name        TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            kind        TEXT NOT NULL,          -- monthly | weekly | hourly | once
            price_cents INTEGER NOT NULL,
            class_date  TEXT NOT NULL DEFAULT '', -- YYYY-MM-DD, one-time classes only
            visible     INTEGER NOT NULL DEFAULT 0,
            sort        INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS payments (
            id            INTEGER PRIMARY KEY,
            product_id    INTEGER REFERENCES products(id), -- NULL for donations
            product_name  TEXT NOT NULL,        -- copied so renaming a class keeps history
            kind          TEXT NOT NULL,
            student_name  TEXT NOT NULL,
            email         TEXT NOT NULL,
            quantity      INTEGER NOT NULL DEFAULT 1,
            amount_cents  INTEGER NOT NULL,
            status        TEXT NOT NULL DEFAULT 'pending', -- pending | paid | expired | failed
            method        TEXT NOT NULL DEFAULT 'stripe',  -- stripe | manual
            stripe_session_id     TEXT UNIQUE,
            stripe_payment_intent TEXT,
            note          TEXT NOT NULL DEFAULT '',
            created_at    TEXT NOT NULL,
            paid_at       TEXT
        );
        CREATE TABLE IF NOT EXISTS payment_periods (
            payment_id   INTEGER NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
            period       TEXT NOT NULL,   -- YYYY-MM (monthly), Monday YYYY-MM-DD (weekly), class date (once),
                                          -- hours-YYYY-MM (hourly), donation-YYYY-MM (donations)
            month        TEXT NOT NULL,   -- YYYY-MM this payment counts toward
            amount_cents INTEGER NOT NULL,
            PRIMARY KEY (payment_id, period)
        );
        CREATE INDEX IF NOT EXISTS payment_periods_month ON payment_periods(month);
        CREATE TABLE IF NOT EXISTS admins (
            email      TEXT PRIMARY KEY,
            name       TEXT NOT NULL DEFAULT '',
            added_at   TEXT NOT NULL,
            last_login TEXT
        );
        CREATE TABLE IF NOT EXISTS login_failures (
            ip TEXT NOT NULL,
            at INTEGER NOT NULL
        );
    SQL);

    // Starter items, hidden until someone checks the details and publishes them.
    // Monthly, weekly and hourly items are shop rental; one-time items are classes.
    $version = fn() => (int)$pdo->query("SELECT value FROM settings WHERE key = 'data_version'")->fetchColumn();
    if ($version() >= 3) return;
    $pdo->exec('BEGIN IMMEDIATE');
    if ($version() < 2) {
        $seed = $pdo->prepare('INSERT INTO products (name, description, kind, price_cents, sort) VALUES (?, ?, ?, ?, ?)');
        if (!$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()) {
            $seed->execute(['Monthly shop rental', 'Time in the hot shop, paid by the month.', 'monthly', 40000, 1]);
            $seed->execute(['Weekly shop rental', 'Time in the hot shop, paid by the week.', 'weekly', 10000, 2]);
            $seed->execute(['Special class', 'Add the date, time and what you will make.', 'once', 60000, 3]);
        } else {
            // Sites set up before Nov 2026 called the rentals "classes". Rename them unless someone already has.
            $pdo->exec("UPDATE products SET name = 'Monthly shop rental' WHERE name = 'Monthly class' AND kind = 'monthly'");
            $pdo->exec("UPDATE products SET name = 'Weekly shop rental' WHERE name = 'Weekly class' AND kind = 'weekly'");
            $pdo->exec("UPDATE products SET description = 'Time in the hot shop, paid by the month.' WHERE description = 'Weekly studio sessions, paid by the month.'");
            $pdo->exec("UPDATE products SET description = 'Time in the hot shop, paid by the week.' WHERE description = 'One studio session, paid by the week.'");
        }
        $seed->execute(['Intro class', 'A first taste of glassblowing. No experience needed.', 'once', 15000, 4]);
    }
    if ($version() < 3) {
        // Nov 2026: hourly shop time for members, published straight away.
        $pdo->exec("INSERT INTO products (name, description, kind, price_cents, visible, sort)
            VALUES ('Member hourly shop rental', 'Hot shop time by the hour, for Charlottesville Glass members.', 'hourly', 8500, 1, 3)");
    }
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('data_version', '3')");
    $pdo->exec('COMMIT');
}

// ---------- settings (stored in the database, edited in Admin → Settings) ----------

function setting(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['cvg_settings'])) {
        $GLOBALS['cvg_settings'] = db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $value = $GLOBALS['cvg_settings'][$key] ?? '';
    return $value !== '' ? $value : $default;
}

function save_setting(string $key, string $value): void
{
    db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')->execute([$key, $value]);
    $GLOBALS['cvg_settings'][$key] = $value;
}

// ---------- small helpers ----------

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money(int $cents): string
{
    return '$' . number_format($cents / 100, $cents % 100 ? 2 : 0);
}

/** /assets/ link with the file's modified time, so browsers fetch the new copy after an update. */
function asset(string $file): string
{
    $mtime = @filemtime(CVG_ROOT . '/public/assets/' . $file);
    return '/assets/' . $file . ($mtime ? '?v=' . $mtime : '');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function nice_date(?string $ts): string
{
    return $ts ? date('M j, Y', strtotime($ts)) : '';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function site_url(string $path = ''): string
{
    $base = rtrim(setting('site_url'), '/');
    if ($base === '') {
        $base = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $base . $path;
}

function contact_email(): string
{
    return setting('contact_email', 'hello@cvilleglass.org');
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('cvg');
    session_set_cookie_params(['path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function flash(string $message, string $type = 'ok'): void
{
    start_session();
    $_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE['cvg'])) return [];
    start_session();
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_field(): string
{
    start_session();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function check_csrf(): void
{
    start_session();
    if (!hash_equals($_SESSION['csrf'] ?? 'x', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Your session timed out. Go back, reload the page and try again.');
    }
}

// ---------- shop rental, classes, and the months/weeks they cover ----------

const CVG_KINDS = [
    'monthly' => ['label' => 'Shop rental, monthly', 'per' => 'per month', 'help' => 'People pick which months they’re paying for'],
    'weekly'  => ['label' => 'Shop rental, weekly', 'per' => 'per week', 'help' => 'People pick which weeks they’re paying for'],
    'hourly'  => ['label' => 'Shop rental, hourly', 'per' => 'per hour', 'help' => 'People pick how many hours they’re paying for'],
    'once'    => ['label' => 'Class', 'per' => 'per person', 'help' => 'People pick how many are coming; add a date if it has one'],
];

function is_rental(array $product): bool
{
    return $product['kind'] !== 'once';
}

/** Priced per person (classes) or per hour, rather than per month/week picked from a list. */
function is_counted(string $kind): bool
{
    return $kind === 'once' || $kind === 'hourly';
}

const CVG_DONATION_MIN = 100;       // $1
const CVG_DONATION_MAX = 2500000;   // $25,000 — bigger gifts are better by check

/** What a donation is recorded against, in place of a product. */
function donation_product(): array
{
    return ['id' => null, 'name' => 'Donation', 'kind' => 'donation', 'price_cents' => 0, 'class_date' => ''];
}

function products(bool $include_hidden = false): array
{
    $sql = 'SELECT * FROM products' . ($include_hidden ? '' : ' WHERE visible = 1') . ' ORDER BY sort, id';
    return db()->query($sql)->fetchAll();
}

function product(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Months/weeks someone can pay for, as [period => label]. $back reaches into the past (for cash entries). */
function period_options(string $kind, int $back = 0): array
{
    $out = [];
    if ($kind === 'monthly') {
        $start = new DateTimeImmutable('first day of this month');
        for ($i = -$back; $i < CVG_MONTHS_AHEAD; $i++) {
            $d = $start->modify("$i month");
            $out[$d->format('Y-m')] = $d->format('F Y');
        }
    } elseif ($kind === 'weekly') {
        $start = new DateTimeImmutable('monday this week');
        for ($i = -$back; $i < CVG_WEEKS_AHEAD; $i++) {
            $d = $start->modify("$i week");
            $out[$d->format('Y-m-d')] = 'Week of ' . $d->format('M j');
        }
    }
    return $out;
}

function period_label(string $kind, string $period): string
{
    if ($kind === 'monthly') return date('F Y', strtotime($period . '-01'));
    if ($kind === 'weekly') return 'Week of ' . date('M j', strtotime($period));
    if ($kind === 'hourly') return 'Hourly shop time';
    if ($kind === 'donation') return 'Donation';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $period) ? date('D, M j, Y', strtotime($period)) : 'Class';
}

function month_label(string $month): string
{
    return date('F Y', strtotime($month . '-01'));
}

/**
 * Save a payment and the months/weeks it covers. $periods is [period => month].
 * The amount is split evenly across the periods so each month's total adds up.
 */
function record_payment(array $product, string $student, string $email, array $periods, int $quantity,
                        int $amount_cents, string $method, string $note = ''): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO payments (product_id, product_name, kind, student_name, email, quantity, amount_cents,
                   status, method, note, created_at, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$product['id'], $product['name'], $product['kind'], $student, $email, $quantity, $amount_cents,
                   $method === 'manual' ? 'paid' : 'pending', $method, $note, now(), $method === 'manual' ? now() : null]);
    $id = (int)$pdo->lastInsertId();

    $count = count($periods);
    $share = intdiv($amount_cents, $count);
    $i = 0;
    $add = $pdo->prepare('INSERT INTO payment_periods (payment_id, period, month, amount_cents) VALUES (?, ?, ?, ?)');
    foreach ($periods as $period => $month) {
        $i++;
        $add->execute([$id, $period, $month, $i === $count ? $amount_cents - $share * ($count - 1) : $share]);
    }
    $pdo->commit();
    return $id;
}

/**
 * [period => month] for anything paid by count rather than by picked months/weeks.
 * A class counts toward its class month (or this month if no date is set); hourly time and donations count toward this month.
 */
function once_period(array $product): array
{
    if ($product['kind'] === 'hourly') return ['hours-' . date('Y-m') => date('Y-m')];
    if ($product['kind'] === 'donation') return ['donation-' . date('Y-m') => date('Y-m')];
    $date = $product['class_date'];
    return $date !== '' ? [$date => substr($date, 0, 7)] : ['class-' . date('Y-m') => date('Y-m')];
}

/** Short text like "Weeks of Nov 2, Nov 9" for a payment's covered periods. */
function covers_text(array $payment, array $periods): string
{
    if ($payment['kind'] === 'monthly') {
        return implode(', ', array_map(fn($p) => date('M Y', strtotime($p . '-01')), $periods));
    }
    if ($payment['kind'] === 'weekly') {
        return 'Weeks of ' . implode(', ', array_map(fn($p) => date('M j', strtotime($p)), $periods));
    }
    if ($payment['kind'] === 'hourly') {
        return $payment['quantity'] . ($payment['quantity'] == 1 ? ' hour' : ' hours');
    }
    if ($payment['kind'] === 'donation') return 'Donation';
    $label = period_label('once', $periods[0] ?? '');
    return ($payment['quantity'] > 1 ? $payment['quantity'] . ' people · ' : '') . $label;
}
