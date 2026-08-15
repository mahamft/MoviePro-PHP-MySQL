<?php
declare(strict_types=1);

$pageTitle = 'Contact MoviePro';
require_once __DIR__ . '/includes/config.php';
$error = '';
$old = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if (isLoggedIn()) {
    $db = getDB();
    $stmt = $db->prepare('SELECT full_name, username, email FROM users WHERE id = ? LIMIT 1');
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $contactUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($contactUser) {
        $old['name'] = (string)($contactUser['full_name'] ?: $contactUser['username']);
        $old['email'] = (string)$contactUser['email'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $old['name'] = trim((string)($_POST['name'] ?? ''));
    $old['email'] = trim((string)($_POST['email'] ?? ''));
    $old['subject'] = trim((string)($_POST['subject'] ?? ''));
    $old['message'] = trim((string)($_POST['message'] ?? ''));

    if ($old['name'] === '' || strlen($old['name']) > 120) {
        $error = 'Enter a valid name.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif ($old['message'] === '' || strlen($old['message']) > 5000) {
        $error = 'Enter a message under 5,000 characters.';
    } else {
        $db = $db ?? getDB();
        $stmt = $db->prepare('INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $old['name'], $old['email'], $old['subject'], $old['message']);
        $stmt->execute();
        $stmt->close();
        flash('success', 'Your message has been received. Our cinema support team will review it.');
        redirect('contact.php');
    }
}
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-hero content-hero contact-hero">
    <div class="container content-hero-grid">
        <div class="reveal-cinema"><div class="section-tag">Cinema concierge</div><h1>How can we <span class="accent">help?</span></h1><p>Ask about a booking, report a payment issue, request cinema information or share feedback with the MoviePro team.</p></div>
        <div class="support-signal reveal-cinema" aria-hidden="true"><span></span><span></span><span></span><b>SUPPORT ONLINE</b></div>
    </div>
</section>

<section class="section" style="padding-bottom:34px">
    <div class="container"><div class="support-card-grid">
        <article class="support-card reveal-cinema"><span>01</span><h3>Booking assistance</h3><p>Seat holds, tickets, cancellations and booking history.</p></article>
        <article class="support-card reveal-cinema"><span>02</span><h3>Payment support</h3><p>Card, JazzCash, Easypaisa and pay-at-cinema questions.</p></article>
        <article class="support-card reveal-cinema"><span>03</span><h3>Cinema listings</h3><p>Movie schedules, venue details and show availability.</p></article>
        <article class="support-card reveal-cinema"><span>04</span><h3>Account & profile</h3><p>Login, profile picture, preferences and CinePoints.</p></article>
    </div></div>
</section>

<section class="section">
    <div class="container contact-layout">
        <form class="contact-form-card reveal-cinema" method="post">
            <?= csrfField() ?>
            <div class="profile-panel-head"><div><div class="section-tag">Send a message</div><h2>Tell us what happened</h2></div><span class="profile-panel-badge">Secure form</span></div>
            <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
            <div class="form-row"><div class="form-group"><label class="form-label" for="contactName">Name *</label><input id="contactName" class="form-control" name="name" value="<?= e($old['name']) ?>" maxlength="120" required></div><div class="form-group"><label class="form-label" for="contactEmail">Email *</label><input id="contactEmail" class="form-control" type="email" name="email" value="<?= e($old['email']) ?>" maxlength="150" required></div></div>
            <div class="form-group"><label class="form-label" for="contactSubject">Subject</label><input id="contactSubject" class="form-control" name="subject" value="<?= e($old['subject']) ?>" maxlength="180" placeholder="Booking, payment, cinema listing…"></div>
            <div class="form-group"><label class="form-label" for="contactMessage">Message *</label><textarea id="contactMessage" class="form-control" name="message" maxlength="5000" required placeholder="Include a booking reference when your question is about an order."><?= e($old['message']) ?></textarea></div>
            <button class="btn btn-primary magnetic" type="submit">Send to MoviePro support</button>
        </form>

        <aside class="contact-side-stack">
            <article class="contact-info-card reveal-cinema"><div class="section-tag">Direct contact</div><h2>Support details</h2><div class="contact-info-list"><div><span>Email</span><strong>support@moviepro.local</strong></div><div><span>Phone</span><strong>+92 300 0000000</strong></div><div><span>Hours</span><strong>10:00 AM – 10:00 PM</strong></div><div><span>Response target</span><strong>Within one business day</strong></div></div></article>
            <article class="contact-tip-card reveal-cinema"><span>Tip</span><h3>Need your ticket quickly?</h3><p>Open My Bookings and select the booking reference to view or print the QR ticket immediately.</p><a class="btn btn-secondary btn-block" href="<?= isLoggedIn() ? url('my_bookings.php') : url('login.php') ?>"><?= isLoggedIn() ? 'Open my bookings' : 'Log in to bookings' ?></a></article>
        </aside>
    </div>
</section>

<section class="section section-alt"><div class="container"><div class="section-head reveal-cinema"><div><div class="section-tag">Common answers</div><h2 class="section-title">Before you <span class="accent">message us</span></h2></div><a class="btn btn-secondary" href="<?= url('faq.php') ?>">View all FAQs</a></div><div class="mini-faq-grid"><article><h3>How long are seats held?</h3><p>Your active cart shows the exact reservation countdown. Expired seats return to availability automatically.</p></article><article><h3>Where is my QR ticket?</h3><p>Open My Bookings, choose a confirmed reservation and use the printable ticket page.</p></article><article><h3>Can I update my account?</h3><p>Open Profile to change your picture, contact details, preferences and password.</p></article></div></div></section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
