<?php
$pageTitle = 'MoviePro Experience';
require_once __DIR__ . '/includes/config.php';
$db = getDB();
$aboutStats = [
    'movies' => (int)$db->query("SELECT COUNT(*) AS c FROM movies")->fetch_assoc()['c'],
    'cinemas' => (int)$db->query("SELECT COUNT(*) AS c FROM cinemas WHERE is_active = 1")->fetch_assoc()['c'],
    'bookings' => (int)$db->query("SELECT COUNT(*) AS c FROM bookings WHERE status = 'confirmed'")->fetch_assoc()['c'],
    'members' => (int)$db->query("SELECT COUNT(*) AS c FROM users WHERE role = 'user' AND is_active = 1")->fetch_assoc()['c'],
];
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-hero content-hero about-hero">
    <div class="container content-hero-grid">
        <div class="reveal-cinema">
            <div class="section-tag">The MoviePro experience</div>
            <h1>Movie night begins <span class="accent">before the screen.</span></h1>
            <p>MoviePro connects discovery, live cinema schedules, intelligent seat selection, group carts, refreshments, rewards and digital tickets through one premium PHP/MySQL platform.</p>
            <div class="hero-actions"><a class="btn btn-primary magnetic" href="<?= url('movies.php') ?>">Explore movies</a><a class="btn btn-outline magnetic" href="#journey">See how it works</a></div>
        </div>
        <div class="about-orbit reveal-cinema" data-tilt-card data-tilt-strength="4" aria-hidden="true">
            <div class="about-orbit-core">C</div><i></i><i></i><i></i><span>DISCOVER</span><span>RESERVE</span><span>ENJOY</span>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="content-stat-grid">
            <article class="content-stat-card reveal-cinema"><span>Movie catalogue</span><strong><?= number_format($aboutStats['movies']) ?>+</strong><small>Now showing and future releases</small></article>
            <article class="content-stat-card reveal-cinema"><span>Active cinemas</span><strong><?= number_format($aboutStats['cinemas']) ?>+</strong><small>Premium screens and showtimes</small></article>
            <article class="content-stat-card reveal-cinema"><span>Confirmed bookings</span><strong><?= number_format($aboutStats['bookings']) ?>+</strong><small>Successful cinema reservations</small></article>
            <article class="content-stat-card reveal-cinema"><span>Movie members</span><strong><?= number_format($aboutStats['members']) ?>+</strong><small>Profiles, rewards and preferences</small></article>
        </div>
    </div>
</section>

<section class="section section-alt" id="journey">
    <div class="container">
        <div class="section-head reveal-cinema"><div><div class="section-tag">One connected journey</div><h2 class="section-title">From trailer to <span class="accent">theatre</span></h2></div><p class="section-copy">Every step is designed to reduce friction while keeping availability, pricing and account data controlled by the server.</p></div>
        <div class="journey-grid">
            <article class="journey-card reveal-cinema" data-tilt-card data-tilt-strength="3"><span>01</span><div class="journey-icon">▶</div><h3>Discover the story</h3><p>Browse genres, trailers, cast, directors, age ratings and verified audience reviews.</p></article>
            <article class="journey-card reveal-cinema" data-tilt-card data-tilt-strength="3"><span>02</span><div class="journey-icon">⌁</div><h3>Compare live shows</h3><p>Choose a date, cinema and showtime with real pricing for Gold, Platinum and Box classes.</p></article>
            <article class="journey-card reveal-cinema" data-tilt-card data-tilt-strength="3"><span>03</span><div class="journey-icon">◫</div><h3>Claim the best view</h3><p>Select seats manually or let smart seat intelligence find centered, contiguous places for your group.</p></article>
            <article class="journey-card reveal-cinema" data-tilt-card data-tilt-strength="3"><span>04</span><div class="journey-icon">✓</div><h3>Enter with confidence</h3><p>Checkout securely, earn CinePoints and receive printable QR-enabled tickets instantly.</p></article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="content-bento">
            <article class="content-bento-card content-bento-large reveal-cinema"><div class="section-tag">Smart reservation engine</div><h2>Seats remain yours while you decide.</h2><p>Database-backed holds protect selected seats for a limited time. Checkout revalidates ownership, availability, pricing, coupons, loyalty and concession stock before confirming an order.</p><div class="content-chip-row"><span>Timed holds</span><span>Double-booking protection</span><span>Automatic expiry</span><span>Best-seat logic</span></div></article>
            <article class="content-bento-card reveal-cinema"><div class="content-bento-icon">%</div><h3>Family pricing</h3><p>Child ticket types receive the configured concession while adult pricing remains class-specific.</p></article>
            <article class="content-bento-card reveal-cinema"><div class="content-bento-icon">★</div><h3>CinePoints rewards</h3><p>Members earn and redeem loyalty points through server-calculated order totals.</p></article>
            <article class="content-bento-card reveal-cinema"><div class="content-bento-icon">♨</div><h3>Snacks & combos</h3><p>Pre-order cinema refreshments with live stock validation during checkout.</p></article>
            <article class="content-bento-card reveal-cinema"><div class="content-bento-icon">QR</div><h3>Digital access</h3><p>Each confirmed booking produces a unique reference, printable ticket and offline QR code.</p></article>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head reveal-cinema"><div><div class="section-tag">Designed for everyone</div><h2 class="section-title">One platform, <span class="accent">two experiences</span></h2></div></div>
        <div class="role-card-grid">
            <article class="role-card reveal-cinema"><span class="role-label">Moviegoers</span><h3>A personal cinema companion</h3><ul><li>Secure registration and member profile</li><li>Favorite genres and language preferences</li><li>Live seats, carts, checkout and notifications</li><li>Booking history, QR tickets and loyalty points</li></ul><a class="btn btn-primary" href="<?= isLoggedIn() ? url('profile.php') : url('register.php') ?>"><?= isLoggedIn() ? 'Open my profile' : 'Create an account' ?></a></article>
            <article class="role-card reveal-cinema"><span class="role-label">Cinema operations</span><h3>Complete administrative control</h3><ul><li>Movies, cinemas, shows and pricing</li><li>Orders, bookings and reservation monitoring</li><li>Coupons, concessions and stock controls</li><li>Users, reviews, messages and revenue analytics</li></ul><a class="btn btn-secondary" href="<?= isAdmin() ? url('admin/index.php') : url('login.php') ?>"><?= isAdmin() ? 'Open admin dashboard' : 'Administrator login' ?></a></article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container"><div class="content-cta reveal-cinema"><div><div class="section-tag">Ready for the next premiere?</div><h2>Choose your movie and let MoviePro handle the rest.</h2><p>Find a show, reserve premium seats, add refreshments and receive your digital ticket in one connected journey.</p></div><div class="content-cta-actions"><a class="btn btn-primary magnetic" href="<?= url('movies.php?status=now_showing') ?>">Book now showing</a><a class="btn btn-outline magnetic" href="<?= url('faq.php') ?>">Read FAQs</a></div></div></div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
