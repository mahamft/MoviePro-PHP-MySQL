<?php
$pageTitle = 'My Bookings';
require_once __DIR__ . '/includes/config.php';
requireLogin();
$db = getDB();
$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    verifyCsrf();
    $bookingId = (int)$_POST['cancel_id'];
    try {
        $db->begin_transaction();
        $stmt = $db->prepare(
            "SELECT b.id,b.order_id,b.showtime_id,b.status,s.show_date,s.show_time,
                    o.status AS order_status,o.loyalty_points_used,o.loyalty_points_earned
             FROM bookings b
             JOIN showtimes s ON s.id=b.showtime_id
             LEFT JOIN orders o ON o.id=b.order_id
             WHERE b.id=? AND b.user_id=? LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('ii', $bookingId, $uid);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking || $booking['status'] !== 'confirmed') {
            throw new RuntimeException('Booking cannot be cancelled.');
        }
        if (strtotime($booking['show_date'] . ' ' . $booking['show_time']) <= time()) {
            throw new RuntimeException('Past shows cannot be cancelled.');
        }

        $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM booking_seats WHERE booking_id=?");
        $countStmt->bind_param('i', $bookingId);
        $countStmt->execute();
        $seatCount = (int)$countStmt->get_result()->fetch_assoc()['c'];
        $countStmt->close();

        $updateBooking = $db->prepare("UPDATE bookings SET status='cancelled',cancelled_at=NOW() WHERE id=? AND user_id=?");
        $updateBooking->bind_param('ii', $bookingId, $uid);
        $updateBooking->execute();
        $updateBooking->close();

        $deleteSeats = $db->prepare("DELETE FROM booking_seats WHERE booking_id=?");
        $deleteSeats->bind_param('i', $bookingId);
        $deleteSeats->execute();
        $deleteSeats->close();

        $showtimeId = (int)$booking['showtime_id'];
        $restoreSeats = $db->prepare("UPDATE showtimes SET available_seats=available_seats+? WHERE id=?");
        $restoreSeats->bind_param('ii', $seatCount, $showtimeId);
        $restoreSeats->execute();
        $restoreSeats->close();

        $payment = $db->prepare("UPDATE payments SET status=CASE WHEN status='success' THEN 'refunded' ELSE status END WHERE booking_id=?");
        $payment->bind_param('i', $bookingId);
        $payment->execute();
        $payment->close();

        $orderId = (int)($booking['order_id'] ?? 0);
        if ($orderId > 0) {
            $remainingStmt = $db->prepare("SELECT COUNT(*) AS c FROM bookings WHERE order_id=? AND status='confirmed'");
            $remainingStmt->bind_param('i', $orderId);
            $remainingStmt->execute();
            $remaining = (int)$remainingStmt->get_result()->fetch_assoc()['c'];
            $remainingStmt->close();

            $newOrderStatus = $remaining === 0 ? 'cancelled' : 'partially_cancelled';
            if ($remaining === 0 && ($booking['order_status'] ?? '') !== 'cancelled') {
                $restorePoints = (int)($booking['loyalty_points_used'] ?? 0);
                $reverseEarned = (int)($booking['loyalty_points_earned'] ?? 0);
                $points = $db->prepare("UPDATE users SET loyalty_points=GREATEST(0,loyalty_points+?-?) WHERE id=?");
                $points->bind_param('iii', $restorePoints, $reverseEarned, $uid);
                $points->execute();
                $points->close();

                $orderPayment = $db->prepare("UPDATE order_payments SET status=CASE WHEN status='success' THEN 'refunded' ELSE status END WHERE order_id=?");
                $orderPayment->bind_param('i', $orderId);
                $orderPayment->execute();
                $orderPayment->close();
            }

            $orderUpdate = $db->prepare("UPDATE orders SET status=? WHERE id=? AND user_id=?");
            $orderUpdate->bind_param('sii', $newOrderStatus, $orderId, $uid);
            $orderUpdate->execute();
            $orderUpdate->close();
        }

        createNotification($db, $uid, 'Booking cancelled', 'Your booking was cancelled and its seats were released.', 'my_bookings.php');
        $db->commit();
        flash('success', 'Booking cancelled successfully. Demo payment and rewards were adjusted where applicable.');
    } catch (Throwable $error) {
        try { $db->rollback(); } catch (Throwable) {}
        flash('error', $error->getMessage());
    }
    redirect('my_bookings.php');
}

$stmt = $db->prepare(
    "SELECT b.*,o.order_ref,m.title AS movie_title,m.poster,c.name AS cinema_name,s.show_date,s.show_time,
            COALESCE(GROUP_CONCAT(st.seat_number ORDER BY st.row_label,st.seat_number_int SEPARATOR ', '),b.seat_summary) AS seats
     FROM bookings b
     LEFT JOIN orders o ON o.id=b.order_id
     JOIN showtimes s ON s.id=b.showtime_id
     JOIN movies m ON m.id=s.movie_id
     JOIN cinemas c ON c.id=s.cinema_id
     LEFT JOIN booking_seats bs ON bs.booking_id=b.id
     LEFT JOIN seats st ON st.id=bs.seat_id
     WHERE b.user_id=?
     GROUP BY b.id,o.order_ref,m.title,m.poster,c.name,s.show_date,s.show_time
     ORDER BY b.booked_at DESC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$bookings = $stmt->get_result();
require_once __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <div class="container">
        <div class="section-tag">Your private cinema archive</div>
        <h1>My <span class="accent">Bookings</span></h1>
        <p>View animated e-tickets, grouped-order invoices and manage upcoming reservations from one premium timeline.</p>
    </div>
</section>
<section class="section">
    <div class="container">
    <?php if (!$bookings->num_rows): ?>
        <div class="empty-state reveal-cinema"><strong>No bookings yet</strong><p>Your cinema history begins with the next story you choose.</p><a class="btn btn-primary magnetic" href="<?= url('movies.php') ?>" style="margin-top:15px">Browse Movies</a></div>
    <?php else: ?>
        <div class="booking-card-list">
        <?php while ($booking = $bookings->fetch_assoc()):
            $future = strtotime($booking['show_date'].' '.$booking['show_time']) > time();
            $statusLabel = ucfirst(str_replace('_',' ',(string)$booking['status']));
        ?>
            <article class="premium-booking-card reveal-cinema">
                <div class="premium-booking-poster">
                    <img src="<?= posterUrl($booking['poster']) ?>" alt="<?= e($booking['movie_title']) ?> poster" loading="lazy" decoding="async">
                </div>
                <div class="premium-booking-content">
                    <div class="section-tag"><?= e($booking['booking_ref']) ?></div>
                    <h2><?= e($booking['movie_title']) ?></h2>
                    <div class="booking-timeline">
                        <span><?= e($booking['cinema_name']) ?></span>
                        <span><?= date('D, d M Y',strtotime($booking['show_date'])) ?></span>
                        <span><?= date('h:i A',strtotime($booking['show_time'])) ?></span>
                        <span>Seats <?= e($booking['seats'] ?: '—') ?></span>
                    </div>
                    <div class="summary-line"><span>Order</span><strong><?= e($booking['order_ref'] ?: 'Individual booking') ?></strong></div>
                    <div class="summary-line"><span>Total</span><strong><?= money($booking['total_amount']) ?></strong></div>
                    <div class="summary-line"><span>Status</span><strong><span class="status status-<?= e($booking['status']) ?>"><?= e($statusLabel) ?></span></strong></div>
                </div>
                <div class="booking-card-actions">
                    <div class="qr-preview" data-qr-payload="MOVIEPRO|<?= e($booking['booking_ref']) ?>|<?= e($booking['show_date']) ?>|<?= e($booking['seats'] ?: 'SEATS') ?>" data-qr-size="72" aria-label="QR ticket preview"></div>
                    <a class="btn btn-primary btn-sm magnetic" href="<?= url('booking_confirm.php?ref='.urlencode($booking['booking_ref'])) ?>">Open Ticket</a>
                    <?php if($booking['order_ref']): ?><a class="btn btn-secondary btn-sm magnetic" href="<?= url('invoice.php?order='.urlencode($booking['order_ref'])) ?>">Invoice</a><?php endif; ?>
                    <?php if($booking['status']==='confirmed'&&$future): ?>
                        <form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="cancel_id" value="<?= (int)$booking['id'] ?>"><button class="btn btn-danger btn-sm magnetic" data-confirm="Cancel this booking?">Cancel Booking</button></form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endwhile; ?>
        </div>
    <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
