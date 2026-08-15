<?php
$pageTitle = 'Order Confirmed';
require_once __DIR__ . '/includes/config.php';
requireLogin();
$db = getDB();
$userId = (int)$_SESSION['user_id'];
$checkout = $_SESSION['last_checkout'] ?? null;
$orderId = (int)($checkout['order_id'] ?? 0);
if (!$checkout || $orderId <= 0) { flash('error', 'No recent checkout was found.'); redirect('my_bookings.php'); }

$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param('ii', $orderId, $userId); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$order) { flash('error', 'Confirmed order could not be loaded.'); redirect('my_bookings.php'); }
$stmt = $db->prepare("SELECT b.id,b.booking_ref,b.total_amount,b.payment_method,s.show_date,s.show_time,m.title,m.poster,c.name AS cinema_name FROM bookings b JOIN showtimes s ON s.id=b.showtime_id JOIN movies m ON m.id=s.movie_id JOIN cinemas c ON c.id=s.cinema_id WHERE b.order_id=? AND b.user_id=? ORDER BY b.id");
$stmt->bind_param('ii',$orderId,$userId); $stmt->execute(); $result=$stmt->get_result(); $bookings=[]; while($row=$result->fetch_assoc()){$bookings[]=$row;} $stmt->close();
require_once __DIR__ . '/includes/header.php';
?>
<section class="success-hero">
    <div class="container">
        <div class="success-check" aria-hidden="true">✓</div>
        <div class="section-tag">Order complete</div>
        <h1>Your cinema experience is confirmed.</h1>
        <p>Thank you, <?= e($order['customer_name']) ?>. The lights are dimming, your seats are waiting and your tickets are ready.</p>
        <div class="order-total-pill"><?= e($order['order_ref']) ?> · <?= money($order['total_amount']) ?></div>
        <div class="reward-earned">+<?= (int)$order['loyalty_points_earned'] ?> CinePoints earned</div>
    </div>
</section>
<section class="section success-ticket-section">
    <div class="container">
        <div class="section-head reveal-cinema">
            <div><div class="section-tag">Your admission passes</div><h2 class="section-title">Tickets have <span class="accent">arrived</span></h2></div>
            <p class="section-copy">Open any ticket to view the full QR pass, booking reference and print layout.</p>
        </div>
        <div class="success-bookings">
        <?php foreach($bookings as $booking): ?>
            <article class="success-booking-card">
                <img src="<?= posterUrl($booking['poster']) ?>" alt="<?= e($booking['title']) ?> poster" loading="lazy" decoding="async">
                <div>
                    <div class="section-tag"><?= e($booking['booking_ref']) ?></div>
                    <h2><?= e($booking['title']) ?></h2>
                    <p><?= e($booking['cinema_name']) ?></p>
                    <p><?= date('D, d M Y',strtotime($booking['show_date'])) ?> at <?= date('h:i A',strtotime($booking['show_time'])) ?></p>
                    <p><?= e(ucfirst($booking['payment_method'])) ?> · <?= money($booking['total_amount']) ?></p>
                </div>
                <div class="success-ticket-actions">
                    <div class="success-mini-qr" data-qr-payload="MOVIEPRO|<?= e($booking['booking_ref']) ?>|<?= e($booking['show_date']) ?>" data-qr-size="92"></div>
                    <a class="btn btn-primary magnetic" href="<?= url('booking_confirm.php?ref='.urlencode($booking['booking_ref'])) ?>">Open E‑Ticket</a>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
        <div class="hero-actions reveal-cinema" style="justify-content:center">
            <a class="btn btn-primary magnetic" href="<?= url('invoice.php?order='.urlencode($order['order_ref'])) ?>">View &amp; Print Invoice</a>
            <a class="btn btn-secondary magnetic" href="<?= url('my_bookings.php') ?>">My Bookings</a>
            <a class="btn btn-outline magnetic" href="<?= url('movies.php') ?>">Book Another Movie</a>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
