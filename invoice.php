<?php
$pageTitle = 'Order Invoice';
require_once __DIR__ . '/includes/config.php';
requireLogin();
$db = getDB();
$userId = (int)$_SESSION['user_id'];
$orderRef = trim((string)($_GET['order'] ?? ''));
if ($orderRef === '') { redirect('my_bookings.php'); }

$sql = "SELECT o.*, u.username FROM orders o JOIN users u ON u.id = o.user_id WHERE o.order_ref = ?" . (isAdmin() ? '' : ' AND o.user_id = ?') . " LIMIT 1";
$stmt = $db->prepare($sql);
if (isAdmin()) { $stmt->bind_param('s', $orderRef); } else { $stmt->bind_param('si', $orderRef, $userId); }
$stmt->execute(); $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$order) { flash('error', 'Invoice was not found.'); redirect('my_bookings.php'); }

$stmt = $db->prepare("SELECT b.*, m.title, c.name AS cinema_name, s.show_date, s.show_time FROM bookings b JOIN showtimes s ON s.id=b.showtime_id JOIN movies m ON m.id=s.movie_id JOIN cinemas c ON c.id=s.cinema_id WHERE b.order_id=? ORDER BY b.id");
$orderId = (int)$order['id']; $stmt->bind_param('i', $orderId); $stmt->execute(); $result = $stmt->get_result(); $bookings=[]; while($row=$result->fetch_assoc()){$bookings[]=$row;} $stmt->close();
$addons=[]; $stmt=$db->prepare("SELECT * FROM order_addons WHERE order_id=? ORDER BY item_name"); $stmt->bind_param('i',$orderId); $stmt->execute(); $r=$stmt->get_result(); while($row=$r->fetch_assoc()){$addons[]=$row;} $stmt->close();
$payment=null; $stmt=$db->prepare("SELECT * FROM order_payments WHERE order_id=? ORDER BY id DESC LIMIT 1"); $stmt->bind_param('i',$orderId); $stmt->execute(); $payment=$stmt->get_result()->fetch_assoc(); $stmt->close();
require_once __DIR__ . '/includes/header.php';
?>
<section class="section invoice-section"><div class="container"><div class="invoice-sheet">
<div class="invoice-header"><div><a class="brand" href="<?= url('index.php') ?>"><span class="brand-mark">M</span><span><strong>MoviePro</strong><small>Advanced Movie Booking</small></span></a><p>Online Movie Booking System<br>Pakistan</p></div><div class="invoice-title"><span>ORDER INVOICE</span><h1><?= e($order['order_ref']) ?></h1><p><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></p></div></div>
<div class="invoice-meta"><div><span>Billed to</span><strong><?= e($order['customer_name']) ?></strong><p><?= e($order['customer_email']) ?><br><?= e($order['customer_phone']) ?></p></div><div><span>Payment</span><strong><?= e(ucfirst($order['payment_method'])) ?></strong><p>Status: <?= e(ucfirst($order['payment_status'])) ?><br><?= e($payment['transaction_ref'] ?? 'N/A') ?></p></div><div><span>Order status</span><strong><?= e(ucfirst(str_replace('_',' ',$order['status']))) ?></strong><p><?= count($bookings) ?> booking(s)</p></div></div>
<table class="invoice-table"><thead><tr><th>Description</th><th>Details</th><th>Amount</th></tr></thead><tbody>
<?php foreach($bookings as $booking): ?><tr><td><strong><?= e($booking['title']) ?></strong><br><small><?= e($booking['booking_ref']) ?></small></td><td><?= e($booking['cinema_name']) ?><br><?= date('d M Y, h:i A',strtotime($booking['show_date'].' '.$booking['show_time'])) ?><br>Seats: <?= e($booking['seat_summary']) ?></td><td><?= money($booking['subtotal']) ?></td></tr><?php endforeach; ?>
<?php foreach($addons as $addon): ?><tr><td><strong><?= e($addon['item_name']) ?></strong></td><td>Quantity <?= (int)$addon['quantity'] ?> × <?= money($addon['unit_price']) ?></td><td><?= money($addon['total_price']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<div class="invoice-bottom"><div class="invoice-barcode"><div></div><span><?= e($order['order_ref']) ?></span></div><div class="invoice-totals"><p><span>Gross subtotal</span><strong><?= money($order['gross_subtotal']) ?></strong></p><?php if((float)$order['discount_amount']>0):?><p class="discount"><span>Promo discount</span><strong>−<?= money($order['discount_amount']) ?></strong></p><?php endif;?><?php if((float)$order['loyalty_discount']>0):?><p class="discount"><span>Loyalty discount</span><strong>−<?= money($order['loyalty_discount']) ?></strong></p><?php endif;?><p><span>Booking fee</span><strong><?= money($order['booking_fee']) ?></strong></p><p><span>Tax</span><strong><?= money($order['tax_amount']) ?></strong></p><p class="grand"><span>Total</span><strong><?= money($order['total_amount']) ?></strong></p></div></div>
<div class="invoice-footer"><p>Thank you for booking with MoviePro. Present your e-ticket at cinema entry.</p><div class="no-print"><button class="btn btn-primary" onclick="window.print()">Print Invoice</button><a class="btn btn-secondary" href="<?= url('my_bookings.php') ?>">My Bookings</a></div></div>
</div></div></section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
