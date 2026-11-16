<?php
// Residency award pages that each resident edits themselves.
// A resident signs in with a private edit link (made in Admin → Residents), then edits
// their own page: words (Quill rich text, cleaned here before saving), a headshot,
// work photos, a CV (PDF), their glassdatabase.org profile, and their glass lineage.
// Uploaded files live in data/uploads/ (outside the web root) and are served by /residency/file.php.

const CVG_GDB = 'https://glassdatabase.org';
const CVG_IMAGE_TYPES = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_WEBP => ['webp', 'image/webp']];
const CVG_MAX_IMAGE = 12 * 1024 * 1024;
const CVG_MAX_CV = 15 * 1024 * 1024;
const CVG_MAX_PHOTOS = 24;

/**
 * Lineage relationships, from the resident's side. Each maps onto glassdatabase.org's
 * lineage form: [label, who is the student (resident|other), glassdatabase "relation"].
 */
const CVG_RELATIONS = [
    'studied_under'  => ['I studied under them', 'resident', 'Studied under'],
    'apprenticed_to' => ['I apprenticed to them', 'resident', 'Apprenticed to'],
    'assisted'       => ['I assisted them', 'resident', 'Assisted'],
    'class_from'     => ['I took a class or workshop from them', 'resident', 'Took a class / workshop from'],
    'assisted_me'    => ['They assisted me', 'other', 'Assisted'],
    'mentored'       => ['They studied under me', 'other', 'Studied under'],
    'collaborated'   => ['We collaborated', 'resident', 'Collaborated with'],
];

/** How a lineage row reads on the public page, e.g. "Assisted by Chip Hall". */
function relation_phrase(string $key): string
{
    return [
        'studied_under' => 'Studied under', 'apprenticed_to' => 'Apprenticed to', 'assisted' => 'Assisted',
        'class_from' => 'Took a class from', 'assisted_me' => 'Assisted by', 'mentored' => 'Mentored',
        'collaborated' => 'Collaborated with',
    ][$key] ?? 'Connected to';
}

function migrate_residents(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS residents (
            id            INTEGER PRIMARY KEY,
            slug          TEXT NOT NULL UNIQUE,
            name          TEXT NOT NULL,
            pronouns      TEXT NOT NULL DEFAULT '',
            year          TEXT NOT NULL DEFAULT '',
            project_title TEXT NOT NULL DEFAULT '',
            summary       TEXT NOT NULL DEFAULT '',   -- one or two plain sentences for the residency page
            bio_html      TEXT NOT NULL DEFAULT '',   -- cleaned rich text
            project_html  TEXT NOT NULL DEFAULT '',
            website       TEXT NOT NULL DEFAULT '',
            instagram     TEXT NOT NULL DEFAULT '',
            headshot      TEXT NOT NULL DEFAULT '',   -- file name in data/uploads
            headshot_alt  TEXT NOT NULL DEFAULT '',
            cv_file       TEXT NOT NULL DEFAULT '',
            cv_name       TEXT NOT NULL DEFAULT '',
            gdb_gid       TEXT NOT NULL DEFAULT '',   -- glassdatabase.org maker id, e.g. gdb:profiles:…
            gdb_name      TEXT NOT NULL DEFAULT '',
            email         TEXT NOT NULL DEFAULT '',   -- where the edit link is sent; never shown
            edit_token    TEXT NOT NULL DEFAULT '',   -- sha256 of the private edit link's secret
            visible       INTEGER NOT NULL DEFAULT 0,
            sort          INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT NOT NULL,
            updated_at    TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS resident_photos (
            id          INTEGER PRIMARY KEY,
            resident_id INTEGER NOT NULL REFERENCES residents(id) ON DELETE CASCADE,
            file        TEXT NOT NULL,
            width       INTEGER NOT NULL DEFAULT 0,
            height      INTEGER NOT NULL DEFAULT 0,
            caption     TEXT NOT NULL DEFAULT '',
            alt         TEXT NOT NULL DEFAULT '',
            sort        INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS resident_lineage (
            id          INTEGER PRIMARY KEY,
            resident_id INTEGER NOT NULL REFERENCES residents(id) ON DELETE CASCADE,
            person      TEXT NOT NULL,
            relation    TEXT NOT NULL,      -- a CVG_RELATIONS key
            place       TEXT NOT NULL DEFAULT '',
            years       TEXT NOT NULL DEFAULT '',
            note        TEXT NOT NULL DEFAULT '',
            gdb_gid     TEXT NOT NULL DEFAULT '',
            created_at  TEXT NOT NULL
        );
    SQL);
}

// ---------- looking residents up ----------

function residents(bool $include_hidden = false): array
{
    return db()->query('SELECT * FROM residents' . ($include_hidden ? '' : ' WHERE visible = 1')
        . " ORDER BY sort, year DESC, lower(name)")->fetchAll();
}

function resident(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM residents WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function resident_by_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM residents WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function resident_photos(int $id): array
{
    $st = db()->prepare('SELECT * FROM resident_photos WHERE resident_id = ? ORDER BY sort, id');
    $st->execute([$id]);
    return $st->fetchAll();
}

function resident_lineage(int $id): array
{
    $st = db()->prepare('SELECT * FROM resident_lineage WHERE resident_id = ? ORDER BY id');
    $st->execute([$id]);
    return $st->fetchAll();
}

function resident_url(array $r): string
{
    return '/residency/artist.php?r=' . rawurlencode($r['slug']);
}

function slugify(string $s): string
{
    // Some iconv builds spell é as 'e or e', so drop those marks after transliterating.
    if (function_exists('iconv')) $s = preg_replace('/[\'"`^~]/', '', @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s !== '' ? $s : 'resident';
}

function unique_slug(string $name, int $except = 0): string
{
    $base = slugify($name);
    $slug = $base;
    $st = db()->prepare('SELECT COUNT(*) FROM residents WHERE slug = ? AND id != ?');
    for ($i = 2; $st->execute([$slug, $except]) && $st->fetchColumn(); $i++) $slug = "$base-$i";
    return $slug;
}

// ---------- private edit links ----------

/** Make a new edit link for a resident (any old one stops working) and return it. */
function new_edit_link(int $id): string
{
    $secret = bin2hex(random_bytes(20));
    db()->prepare('UPDATE residents SET edit_token = ? WHERE id = ?')->execute([hash('sha256', $secret), $id]);
    return site_url('/residency/edit.php?t=' . $id . '.' . $secret);
}

/** The resident from a "?t=ID.secret" link, or null. */
function resident_from_token(string $t): ?array
{
    if (!preg_match('/^(\d+)\.([a-f0-9]{40})$/', $t, $m)) return null;
    $r = resident((int)$m[1]);
    return $r && $r['edit_token'] !== '' && hash_equals($r['edit_token'], hash('sha256', $m[2])) ? $r : null;
}

/** The resident signed in through their edit link, or the one an admin opened. */
function editing_resident(): ?array
{
    start_session();
    $id = (int)($_SESSION['resident_id'] ?? 0);
    if (!$id) return null;
    $r = resident($id);
    // A reset link signs out anyone still using the old one (admins are exempt).
    if (!$r || (empty($_SESSION['resident_admin']) && ($_SESSION['resident_token'] ?? '') !== $r['edit_token'])) {
        unset($_SESSION['resident_id'], $_SESSION['resident_token'], $_SESSION['resident_admin']);
        return null;
    }
    return $r;
}

// ---------- rich text: keep a small, safe set of tags ----------

/**
 * Clean HTML from the editor (or pasted from Word / Google Docs) down to paragraphs,
 * headings, bold/italic, lists, quotes and links. Everything else is unwrapped or dropped.
 */
function clean_html(string $html): string
{
    $html = trim(str_replace("\u{00a0}", ' ', $html));
    if ($html === '' || $html === '<p><br></p>') return '';
    if (!class_exists('DOMDocument')) return '<p>' . nl2br(h(strip_tags($html))) . '</p>';

    $allowed = ['p' => 1, 'br' => 1, 'strong' => 1, 'em' => 1, 'u' => 1, 'h2' => 1, 'h3' => 1,
                'ul' => 1, 'ol' => 1, 'li' => 1, 'blockquote' => 1, 'a' => 1];
    $rename = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'div' => 'p'];
    $drop = ['script' => 1, 'style' => 1, 'iframe' => 1, 'object' => 1, 'embed' => 1, 'form' => 1,
             'input' => 1, 'button' => 1, 'select' => 1, 'textarea' => 1, 'svg' => 1, 'math' => 1,
             'head' => 1, 'title' => 1, 'meta' => 1, 'link' => 1, 'template' => 1, 'img' => 1, 'video' => 1, 'audio' => 1];

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('root');
    if (!$root) return '';

    $walk = function (DOMNode $node) use (&$walk, $doc, $allowed, $rename, $drop): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) continue;
            if (!$child instanceof DOMElement) { $node->removeChild($child); continue; }
            $tag = strtolower($child->tagName);
            if (isset($drop[$tag])) { $node->removeChild($child); continue; }
            $walk($child);
            if (isset($rename[$tag])) {
                $new = $doc->createElement($rename[$tag]);
                while ($child->firstChild) $new->appendChild($child->firstChild);
                $node->replaceChild($new, $child);
                $child = $new;
                $tag = $rename[$tag];
            }
            if (!isset($allowed[$tag])) {           // unknown tag (span, font, table…): keep its text
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) $child->removeAttribute($attr->name);
            if ($tag === 'a') {
                if (!preg_match('~^(https?://|mailto:)~i', $href)) {
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                $child->setAttribute('href', $href);
                $child->setAttribute('rel', 'noopener nofollow');
                $child->setAttribute('target', '_blank');
            }
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    // Quill 2.0.3 saves every space as &nbsp;, which stops long lines from wrapping.
    $out = str_replace(["\u{00a0}", '&nbsp;'], ' ', $out);
    $out = preg_replace('~<p>(\s|\x{00a0}|<br>)*</p>~u', '', $out);
    return clip(trim($out), 60000);
}

// ---------- uploads ----------

function upload_dir(): string
{
    $dir = CVG_DATA . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir;
}

/** Why an upload failed, in plain words, or '' if it arrived. */
function upload_problem(?array $f, int $max): string
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Pick a file first.';
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > $max) {
        return 'That file is too big. The limit is ' . round($max / 1048576) . ' MB.';
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return 'The upload didn’t come through. Please try again.';
    return '';
}

/** Save an uploaded photo. Returns [file name, width, height]; throws RuntimeException with a reason. */
function store_image(?array $f): array
{
    if ($why = upload_problem($f, CVG_MAX_IMAGE)) throw new RuntimeException($why);
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !isset(CVG_IMAGE_TYPES[$info[2]])) throw new RuntimeException('Photos need to be JPEG, PNG or WebP.');
    $name = bin2hex(random_bytes(12)) . '.' . CVG_IMAGE_TYPES[$info[2]][0];
    if (!move_uploaded_file($f['tmp_name'], upload_dir() . '/' . $name)) throw new RuntimeException('The photo couldn’t be saved on the server.');
    return [$name, (int)$info[0], (int)$info[1]];
}

/** Save an uploaded CV. Returns the file name; throws RuntimeException with a reason. */
function store_pdf(?array $f): string
{
    if ($why = upload_problem($f, CVG_MAX_CV)) throw new RuntimeException($why);
    $fh = fopen($f['tmp_name'], 'rb');
    $magic = $fh ? fread($fh, 5) : '';
    if ($fh) fclose($fh);
    if ($magic !== '%PDF-') throw new RuntimeException('Please upload your CV as a PDF. (In Word or Google Docs: File → Download / Save as → PDF.)');
    $name = bin2hex(random_bytes(12)) . '.pdf';
    if (!move_uploaded_file($f['tmp_name'], upload_dir() . '/' . $name)) throw new RuntimeException('The CV couldn’t be saved on the server.');
    return $name;
}

function delete_upload(string $name): void
{
    if (preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|pdf)$/', $name)) @unlink(upload_dir() . '/' . $name);
}

function upload_url(string $name): string
{
    return '/residency/file.php?f=' . rawurlencode($name);
}

// ---------- glassdatabase.org ----------

function gdb_maker_url(string $gid): string
{
    return CVG_GDB . '/maker.html?m=' . rawurlencode($gid);
}

/** Search glassdatabase.org makers by name. Returns [[gid, name, description], …]. */
function gdb_search(string $q): array
{
    $q = trim($q);
    if (strlen($q) < 2) return [];
    try {
        [$status, $data] = http_call('GET', CVG_GDB . '/api/makers?' . http_build_query(['q' => $q, 'limit' => 8]), [], [CURLOPT_TIMEOUT => 8]);
    } catch (RuntimeException $e) {
        return [];
    }
    if ($status !== 200) return [];
    return array_map(fn($m) => ['gid' => (string)$m['gid'], 'name' => (string)$m['name'], 'description' => (string)($m['description'] ?? '')],
        array_values(array_filter($data['makers'] ?? [], fn($m) => isset($m['gid'], $m['name']))));
}

function valid_gid(string $gid): bool
{
    return (bool)preg_match('/^gdb:[a-z_]+:[A-Za-z0-9._-]{1,120}$/', $gid);
}

/** Hanna's page, moved from the old static residency.html so nothing is lost. */
function seed_first_resident(PDO $pdo): void
{
    if ($pdo->query('SELECT COUNT(*) FROM residents')->fetchColumn()) return;
    $photo = '';
    $src = CVG_ROOT . '/public/assets/residency/hanna-taubenberger.jpg';
    if (is_file($src)) {
        $photo = bin2hex(random_bytes(12)) . '.jpg';
        if (!@copy($src, upload_dir() . '/' . $photo)) $photo = '';
    }
    $bio = '<p>Hanna Taubenberger (she/her) is a Baltimore-based multidisciplinary artist focused on sculpture and installation. She graduated with her BFA in Sculpture and Extended Media from Virginia Commonwealth University in 2020. Hanna completed an artist residency program at McGuffey in 2021 and gave an artist talk in 2022 on the work she created during her time in the space, which focused on themes of sanctuary in juxtaposition with toxicity.</p>'
        . '<p>Hanna’s work relates to imagery of childhood homes, and the nuance of rendered objects such as chandeliers, dollhouses, and tables. Her work focuses on evoking senses of memory through the recreational processes of family heirlooms in her practice and capturing the essence of loved ones through documentation and rendering. Many of her pieces explore elements of decay — candles, flowers, dust, mold, ivy, and flames — to create an atmosphere of ephemerality. Scent is also explored through her work as a way to trigger memories for people and places regarding site-specificity.</p>'
        . '<p>Hanna explores the blurred lines between private versus public memories, and nuances in her studio practice. The process of documenting cherished relationships and the act of memorializing people and their core essence is intertwined with Hanna’s own personal essence, studio practice, and process of creating.</p>';
    $project = '<p>For <em>Glass Houses</em>, Hanna fabricated steel forms for glass sculptures and brought them into the hot shop.</p>'
        . '<p>The residency took place over three days at <a href="https://ragingbuffaloglass.com" rel="noopener nofollow" target="_blank">Raging Buffalo Glass Studio</a>, with mentorship and hands-on assistance from <strong>Anson Parker</strong> and <strong>Chip Hall</strong>, who oversaw the creation of the <em>Glass Houses</em> series from first gather to the annealer.</p>';
    $now = now();
    $pdo->prepare('INSERT INTO residents (slug, name, pronouns, year, project_title, summary, bio_html, project_html, instagram,
                   headshot, headshot_alt, visible, sort, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)')
        ->execute(['hanna-taubenberger', 'Hanna Taubenberger', 'she/her', '2026', 'Glass Houses',
                   'A Baltimore-based sculptor and installation artist who built steel forms for glass. Three days in the hot shop, two mentors at the bench, and a new series of work.',
                   $bio, $project, 'hannacanvas', $photo, 'Hanna Taubenberger, smiling, in a winter coat and scarf outdoors.', $now, $now]);
    $id = (int)$pdo->lastInsertId();
    $add = $pdo->prepare('INSERT INTO resident_lineage (resident_id, person, relation, place, years, gdb_gid, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $add->execute([$id, 'Anson Parker', 'assisted_me', 'Raging Buffalo Glass Studio', '2026', 'gdb:profiles:821c590e17cf275d', $now]);
    $add->execute([$id, 'Chip Hall', 'assisted_me', 'Raging Buffalo Glass Studio', '2026', '', $now]);
}
