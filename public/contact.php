<?php
// Public contact page. Messages are saved (Admin → Messages) and emailed to the
// address set in Admin → Settings. Link with ?subject=… to fill in the subject.
require __DIR__ . '/../app/bootstrap.php';

const CVG_CONTACT_PER_HOUR = 5;   // per visitor, to keep spam bots from flooding the inbox

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = header_safe(clip((string)($_POST['name'] ?? ''), 120));
    $email = trim(clip((string)($_POST['email'] ?? ''), 200));
    $subject = header_safe(clip((string)($_POST['subject'] ?? ''), 150));
    $message = trim(clip((string)($_POST['message'] ?? ''), 5000));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $back = '/contact.php' . ($subject !== '' ? '?subject=' . rawurlencode($subject) : '') . '#message';

    // Bots fill in the hidden "website" box; pretend it worked and drop it.
    if (trim((string)($_POST['website'] ?? '')) !== '') redirect('/contact.php?sent=1');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
        start_session();
        $_SESSION['contact_draft'] = compact('name', 'email', 'subject', 'message');
        flash('Please fill in your name, a valid email, and a message.', 'error');
        redirect($back);
    }

    $pdo = db();
    $recent = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE ip = ? AND created_at > ?');
    $recent->execute([$ip, date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$recent->fetchColumn() >= CVG_CONTACT_PER_HOUR) {
        flash('You’ve sent several messages in the last hour. Please try again a bit later.', 'error');
        redirect($back);
    }

    $pdo->prepare('INSERT INTO messages (name, email, subject, message, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$name, $email, $subject, $message, $ip, now()]);
    $id = (int)$pdo->lastInsertId();

    $body = "New message from the cvilleglass.org contact form.\n\n"
        . "From: $name <$email>\n"
        . ($subject !== '' ? "Subject: $subject\n" : '')
        . "\n$message\n\n"
        . "— Reply to this email to answer $name directly.\n"
        . 'All messages: ' . site_url('/admin/messages.php') . "\n";
    try {
        send_mail(contact_to(), 'Website message' . ($subject !== '' ? ': ' . $subject : ' from ' . $name), $body, $email, $name);
        $pdo->prepare('UPDATE messages SET emailed = 1 WHERE id = ?')->execute([$id]);
    } catch (RuntimeException $e) {
        // The message is still saved and shows up under Admin → Messages.
        error_log('cvilleglass contact: ' . $e->getMessage());
        $pdo->prepare('UPDATE messages SET email_error = ? WHERE id = ?')->execute([$e->getMessage(), $id]);
    }
    redirect('/contact.php?sent=1');
}

start_session();
$draft = $_SESSION['contact_draft'] ?? [];
unset($_SESSION['contact_draft']);
$subject = $draft['subject'] ?? clip((string)($_GET['subject'] ?? ''), 150);

public_header('Contact');
?>
<section class="page-hero band--tight">
  <div class="panes" aria-hidden="true"><span class="pane p1"></span><span class="pane p2"></span><span class="pane p3"></span></div>
  <div class="wrap">
    <p class="eyebrow">Contact</p>
    <h1>Come find us.</h1>
    <p class="lead">Questions, ideas, partnerships, or you just want to try glass — we read every message and we like hearing from people.</p>
  </div>
</section>

<section class="band band--tight" id="message">
  <div class="wrap pay-list">
    <?php flash_messages() ?>
    <?php if (isset($_GET['sent'])): ?>
      <div class="pay-card">
        <div class="pay-card-info">
          <h2>Thanks — your message is on its way.</h2>
          <p>We’ll write back to the email you gave us, usually within a few days.</p>
        </div>
        <div class="btn-row" style="align-self:center"><a class="btn btn--primary" href="/">Back to the site</a><a class="btn btn--ghost" href="/contact.php">Send another</a></div>
      </div>
    <?php else: ?>
      <article class="pay-card">
        <div class="pay-card-info">
          <h2>Send us a message</h2>
          <p>Programs, partnerships, volunteering, press, giving, or a visit — it all comes to real people, and we’ll route it to the right one.</p>
          <p>Need step-free access or a quieter time to visit? Just say so, and we’ll plan around it.</p>
        </div>
        <form class="pay-form" method="post" action="/contact.php">
          <label class="field">Your name
            <input name="name" required autocomplete="name" maxlength="120" value="<?= h($draft['name'] ?? '') ?>">
          </label>
          <label class="field">Your email
            <input name="email" type="email" required autocomplete="email" maxlength="200" value="<?= h($draft['email'] ?? '') ?>">
          </label>
          <label class="field">Subject <span class="fine">(optional)</span>
            <input name="subject" maxlength="150" value="<?= h($subject) ?>">
          </label>
          <label class="field">Message
            <textarea name="message" rows="6" required maxlength="5000"><?= h($draft['message'] ?? '') ?></textarea>
          </label>
          <label class="hp" aria-hidden="true">Leave this empty <input name="website" tabindex="-1" autocomplete="off"></label>
          <button class="btn btn--amber" type="submit">Send message</button>
        </form>
      </article>
    <?php endif ?>
  </div>
</section>

<section class="band">
  <div class="wrap">
    <div class="locard reveal">
      <div class="loc-body">
        <dl>
          <div class="loc-detail">
            <dt>Studio</dt>
            <dd>Charlottesville Glass<br>at the McGuffey Art Center<br>201 2nd St NW<br>Charlottesville, VA 22902</dd>
          </div>
          <div class="loc-detail">
            <dt>Visiting</dt>
            <dd>By appointment while we get established. Send us a message and we’ll set a time — and point you to parking and the right entrance.</dd>
          </div>
        </dl>
        <div class="btn-row">
          <a class="btn btn--ghost" href="https://www.google.com/maps/search/?api=1&amp;query=McGuffey%20Art%20Center%20201%202nd%20St%20NW%20Charlottesville%20VA%2022902" target="_blank" rel="noopener">Get directions</a>
        </div>
      </div>
      <div class="loc-map">
        <iframe title="Map showing the McGuffey Art Center at 201 2nd Street NW, Charlottesville" loading="lazy"
          src="https://www.openstreetmap.org/export/embed.html?bbox=-78.4862%2C38.0292%2C-78.4742%2C38.0360&amp;layer=mapnik&amp;marker=38.0326%2C-78.4802"></iframe>
      </div>
    </div>
  </div>
</section>

<section class="band band--paper2">
  <div class="wrap split">
    <div class="reveal">
      <p class="eyebrow">Getting here</p>
      <h2 class="section-head">A block off the Downtown Mall.</h2>
      <p>McGuffey sits just north of Charlottesville’s Downtown Mall in a historic 1915 schoolhouse. There’s nearby street and garage parking, and the Mall’s shops and cafés are a short walk away — easy to make a visit into an afternoon.</p>
    </div>
    <div class="reveal">
      <figure class="photo-figure">
        <img src="/assets/mcguffey.jpg" width="900" height="900" alt="The McGuffey Art Center — a two-story brick 1915 schoolhouse with a columned entrance and sculptures on the front lawn." loading="lazy" decoding="async">
        <figcaption>Find us at the McGuffey Art Center, 201 2nd St NW.</figcaption>
      </figure>
    </div>
  </div>
</section>
<?php
public_footer();
