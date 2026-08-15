<?php
$pageTitle='Booking Confirmed';
require_once __DIR__.'/includes/config.php';
requireLogin();
$db=getDB();
$ref=trim((string)($_GET['ref']??'')); $uid=(int)$_SESSION['user_id'];
$stmt=$db->prepare("SELECT b.*,o.order_ref,s.show_date,s.show_time,m.title movie_title,m.genre,m.poster,c.name cinema_name,c.city,u.full_name,u.email FROM bookings b LEFT JOIN orders o ON o.id=b.order_id JOIN showtimes s ON s.id=b.showtime_id JOIN movies m ON m.id=s.movie_id JOIN cinemas c ON c.id=s.cinema_id JOIN users u ON u.id=b.user_id WHERE b.booking_ref=? AND b.user_id=? LIMIT 1");
$stmt->bind_param('si',$ref,$uid); $stmt->execute(); $booking=$stmt->get_result()->fetch_assoc();
if(!$booking){ flash('error','Booking not found.'); redirect('my_bookings.php'); }
$seats=$db->prepare("SELECT st.seat_number,st.seat_class,bs.ticket_type,bs.price_paid FROM booking_seats bs JOIN seats st ON st.id=bs.seat_id WHERE bs.booking_id=? ORDER BY st.row_label,st.seat_number_int");
$bookingId=(int)$booking['id']; $seats->bind_param('i',$bookingId); $seats->execute(); $seatResult=$seats->get_result(); $seatList=[]; while($s=$seatResult->fetch_assoc())$seatList[]=$s;
$seatDisplay=$seatList?implode(', ',array_column($seatList,'seat_number')):(string)$booking['seat_summary'];
require_once __DIR__.'/includes/header.php';
?>
<section class="cinema-ticket-stage">
    <div class="ticket-spotlight" aria-hidden="true"></div>
    <div class="container">
        <div class="ticket-stage-copy reveal-cinema">
            <div class="section-tag">Your admission to the story</div>
            <h1>Digital Cinema Ticket</h1>
            <p>Present this QR-enabled ticket at the cinema. Keep the booking reference available as a fallback.</p>
        </div>

        <article class="premium-ticket reveal-cinema" data-tilt-card data-tilt-strength="5">
            <div class="premium-ticket-poster" style="background-image:url('<?= posterUrl($booking['poster']) ?>')">
                <div class="premium-ticket-poster-overlay">
                    <div class="section-tag">Booking confirmed</div>
                    <h2><?= e($booking['movie_title']) ?></h2>
                    <p><?= e($booking['genre']) ?></p>
                </div>
            </div>

            <div class="premium-ticket-main">
                <div class="ticket-perforation" aria-hidden="true"></div>
                <div class="ticket-grid">
                    <div class="ticket-item"><span>Date</span><strong><?= date('D, d M Y',strtotime($booking['show_date'])) ?></strong></div>
                    <div class="ticket-item"><span>Time</span><strong><?= date('h:i A',strtotime($booking['show_time'])) ?></strong></div>
                    <div class="ticket-item"><span>Cinema</span><strong><?= e($booking['cinema_name']) ?></strong></div>
                    <div class="ticket-item"><span>City</span><strong><?= e($booking['city']) ?></strong></div>
                    <div class="ticket-item"><span>Seats</span><strong><?= e($seatDisplay) ?></strong></div>
                    <div class="ticket-item"><span>Tickets</span><strong><?= (int)$booking['adult_count'] + (int)$booking['kids_count'] ?> · <?= (int)$booking['kids_count'] ?> child</strong></div>
                    <div class="ticket-item"><span>Status</span><strong><?= e(ucfirst($booking['status'])) ?></strong></div>
                    <div class="ticket-item"><span>Total</span><strong><?= money($booking['total_amount']) ?></strong></div>
                </div>
                <div class="ticket-reference-block">
                    <span>Booking reference</span>
                    <strong><?= e($booking['booking_ref']) ?></strong>
                    <?php if(!empty($booking['order_ref'])): ?><small>Order <?= e($booking['order_ref']) ?></small><?php endif; ?>
                </div>
            </div>

            <aside class="premium-ticket-stub">
                <div class="ticket-qr" data-qr-payload="MOVIEPRO|<?= e($booking['booking_ref']) ?>|<?= e($booking['show_date']) ?>|<?= e($seatDisplay) ?>" data-qr-size="150"></div>
                <span>Scan at entrance</span>
                <strong><?= e($booking['booking_ref']) ?></strong>
                <small><?= e(ucfirst($booking['payment_method'])) ?> · <?= money($booking['total_amount']) ?></small>
            </aside>
        </article>

        <div class="hero-actions no-print reveal-cinema" style="justify-content:center">
            <button class="btn btn-primary magnetic" type="button" data-print-ticket>Print Ticket</button>
            <?php if(!empty($booking['order_ref'])): ?><a class="btn btn-secondary magnetic" href="<?= url('invoice.php?order='.urlencode($booking['order_ref'])) ?>">Order Invoice</a><?php endif; ?>
            <a class="btn btn-outline magnetic" href="<?= url('my_bookings.php') ?>">My Bookings</a>
        </div>
    </div>
</section>
<script>document.querySelector('[data-print-ticket]')?.addEventListener('click',()=>window.print());</script>
<?php require_once __DIR__.'/includes/footer.php'; ?>
