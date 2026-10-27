<?php
// Charlottesville Glass — shared setup for every PHP page.
// Keys, passwords and prices live in the database (edit them under /admin/),
// so nothing secret is ever stored in this repository.

declare(strict_types=1);

date_default_timezone_set('America/New_York');

const CVG_MONTHS_AHEAD = 6;        // how many months a student can pay for at once
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
            kind        TEXT NOT NULL,          -- monthly | weekly | once
            price_cents INTEGER NOT NULL,
            class_date  TEXT NOT NULL DEFAULT '', -- YYYY-MM-DD, one-time classes only
            visible     INTEGER NOT NULL DEFAULT 0,
            sort        INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS payments (
            id            INTEGER PRIMARY KEY,
            product_id    INTEGER REFERENCES products(id),
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
            period       TEXT NOT NULL,   -- YYYY-MM (monthly), Monday YYYY-MM-DD (weekly), class date (once)
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

    // Starter classes, hidden until someone checks the details and publishes them.
    if (!$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()) {
        $seed = $pdo->prepare('INSERT INTO products (name, description, kind, price_cents, sort) VALUES (?, ?, ?, ?, ?)');
        $seed->execute(['Monthly class', 'Weekly studio sessions, paid by the month.', 'monthly', 40000, 1]);
        $seed->execute(['Weekly class', 'One studio session, paid by the week.', 'weekly', 10000, 2]);
        $seed->execute(['Special class', 'Add the date, time and what you will make.', 'once', 60000, 3]);
    }
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

// ---------- classes and the months/weeks they cover ----------

const CVG_KINDS = [
    'monthly' => ['label' => 'Monthly', 'per' => 'per month', 'help' => 'Students pick which months they are paying for'],
    'weekly'  => ['label' => 'Weekly', 'per' => 'per week', 'help' => 'Students pick which weeks they are paying for'],
    'once'    => ['label' => 'One-time class', 'per' => 'per person', 'help' => 'Students pick how many people'],
];

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

/** Months/weeks a student can pay for, as [period => label]. $back reaches into the past (for cash entries). */
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

/** [period => month] for a one-time class: counts toward the class month, or this month if no date is set. */
function once_period(array $product): array
{
    $date = $product['class_date'];
    return $date !== '' ? [$date => substr($date, 0, 7)] : ['class-' . date('Y-m') => date('Y-m')];
}

/** Short text like "Weekly class — Nov 2, Nov 9" for a payment's covered periods. */
function covers_text(array $payment, array $periods): string
{
    if ($payment['kind'] === 'monthly') {
        return implode(', ', array_map(fn($p) => date('M Y', strtotime($p . '-01')), $periods));
    }
    if ($payment['kind'] === 'weekly') {
        return 'Weeks of ' . implode(', ', array_map(fn($p) => date('M j', strtotime($p)), $periods));
    }
    $label = period_label('once', $periods[0] ?? '');
    return ($payment['quantity'] > 1 ? $payment['quantity'] . ' people · ' : '') . $label;
}
