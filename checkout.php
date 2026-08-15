<?php
$pageTitle = 'Advanced Checkout';
require_once __DIR__ . '/includes/config.php';
requireLogin();

$db = getDB();
$userId = (int)$_SESSION['user_id'];
purgeExpiredCarts($db);
$cart = getActiveCart($db, $userId, false);
if (!$cart) {
    flash('error', 'Your reservation cart is empty or has expired.');
    redirect('cart.php');
}

$allowedMethods = ['card', 'jazzcash', 'easypaisa', 'cash'];
if (empty($_SESSION['checkout_nonce'])) {
    $_SESSION['checkout_nonce'] = bin2hex(random_bytes(24));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $nonce = (string)($_POST['checkout_nonce'] ?? '');
    if ($nonce === '' || !hash_equals((string)($_SESSION['checkout_nonce'] ?? ''), $nonce)) {
        flash('error', 'This checkout request was already used or expired.');
        redirect('cart.php');
    }

    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    $customerEmail = trim((string)($_POST['customer_email'] ?? ''));
    $customerPhone = trim((string)($_POST['customer_phone'] ?? ''));
    $paymentMethod = (string)($_POST['payment_method'] ?? 'cash');
    $paymentReference = trim((string)($_POST['payment_reference'] ?? ''));
    $requestedPoints = max(0, (int)($_POST['redeem_points'] ?? 0));
    $termsAccepted = isset($_POST['terms']);

    if ($customerName === '' || mb_strlen($customerName) > 150) {
        flash('error', 'Enter a valid customer name.'); redirect('checkout.php');
    }
    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid email address.'); redirect('checkout.php');
    }
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        $paymentMethod = 'cash';
    }
    if ($paymentMethod !== 'cash' && $paymentReference === '') {
        flash('error', 'Enter demo payment details for the selected method.'); redirect('checkout.php');
    }
    if (!$termsAccepted) {
        flash('error', 'Accept the booking terms to continue.'); redirect('checkout.php');
    }

    try {
        $db->begin_transaction();
        $cartId = (int)$cart['id'];
        $now = reservationNow();

        $cartLock = $db->prepare("SELECT * FROM carts WHERE id = ? AND user_id = ? AND status = 'active' LIMIT 1 FOR UPDATE");
        $cartLock->bind_param('ii', $cartId, $userId);
        $cartLock->execute();
        $lockedCart = $cartLock->get_result()->fetch_assoc();
        $cartLock->close();
        if (!$lockedCart || strtotime((string)$lockedCart['expires_at']) <= time()) {
            throw new RuntimeException('Your seat reservation expired before checkout. Please select seats again.');
        }

        $userLock = $db->prepare("SELECT loyalty_points FROM users WHERE id = ? AND is_active = 1 LIMIT 1 FOR UPDATE");
        $userLock->bind_param('i', $userId);
        $userLock->execute();
        $lockedUser = $userLock->get_result()->fetch_assoc();
        $userLock->close();
        if (!$lockedUser) {
            throw new RuntimeException('Your account is no longer active.');
        }

        $itemStmt = $db->prepare(
            "SELECT ci.id, ci.showtime_id, ci.seat_id, ci.ticket_type, ci.unit_price,
                    st.show_date, st.show_time, st.available_seats, st.is_active,
                    m.title, c.name AS cinema_name, s.seat_number, s.row_label, s.seat_number_int
             FROM cart_items ci
             JOIN showtimes st ON st.id = ci.showtime_id
             JOIN movies m ON m.id = st.movie_id
             JOIN cinemas c ON c.id = st.cinema_id
             JOIN seats s ON s.id = ci.seat_id
             WHERE ci.cart_id = ?
             ORDER BY ci.showtime_id, s.row_label, s.seat_number_int FOR UPDATE"
        );
        $itemStmt->bind_param('i', $cartId);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        $checkoutItems = [];
        while ($item = $itemResult->fetch_assoc()) { $checkoutItems[] = $item; }
        $itemStmt->close();
        if (!$checkoutItems) { throw new RuntimeException('Your reservation cart is empty.'); }

        $holdStmt = $db->prepare("SELECT id FROM seat_holds WHERE cart_id = ? AND user_id = ? AND expires_at > ? FOR UPDATE");
        $holdStmt->bind_param('iis', $cartId, $userId, $now);
        $holdStmt->execute();
        $validHoldCount = $holdStmt->get_result()->num_rows;
        $holdStmt->close();
        if ($validHoldCount !== count($checkoutItems)) {
            throw new RuntimeException('One or more reserved seats expired. Please rebuild your cart.');
        }

        $conflictStmt = $db->prepare(
            "SELECT bs.id FROM cart_items ci
             JOIN booking_seats bs ON bs.showtime_id = ci.showtime_id AND bs.seat_id = ci.seat_id
             JOIN bookings b ON b.id = bs.booking_id
             WHERE ci.cart_id = ? AND b.status IN ('pending','confirmed') LIMIT 1 FOR UPDATE"
        );
        $conflictStmt->bind_param('i', $cartId);
        $conflictStmt->execute();
        $hasConflict = (bool)$conflictStmt->get_result()->fetch_assoc();
        $conflictStmt->close();
        if ($hasConflict) { throw new RuntimeException('A reserved seat is no longer available. No payment was recorded.'); }

        $addonRows = [];
        if (tableExists($db, 'cart_addons')) {
            $addonStmt = $db->prepare(
                "SELECT ca.concession_id, ca.quantity, ca.unit_price, co.name, co.stock_qty, co.is_active
                 FROM cart_addons ca JOIN concessions co ON co.id = ca.concession_id
                 WHERE ca.cart_id = ? FOR UPDATE"
            );
            $addonStmt->bind_param('i', $cartId);
            $addonStmt->execute();
            $addonResult = $addonStmt->get_result();
            while ($addon = $addonResult->fetch_assoc()) {
                if (!(int)$addon['is_active'] || (int)$addon['stock_qty'] < (int)$addon['quantity']) {
                    throw new RuntimeException('A selected snack is no longer available in the requested quantity.');
                }
                $addonRows[] = $addon;
            }
            $addonStmt->close();
        }

        $promoCode = strtoupper(trim((string)($lockedCart['promo_code'] ?? '')));
        if ($promoCode !== '') {
            $preview = cartPricing($db, $cartId, $userId, 0);
            $coupon = validateCoupon($db, $promoCode, (float)$preview['gross_subtotal'], true);
            if (!$coupon) {
                throw new RuntimeException('The applied promo code expired or is no longer eligible.');
            }
        }
        $pricing = cartPricing($db, $cartId, $userId, $requestedPoints);
        if ($requestedPoints > 0 && (int)$pricing['points_used'] === 0) {
            throw new RuntimeException('The requested loyalty points cannot be redeemed on this order.');
        }

        $paymentStatus = $paymentMethod === 'cash' ? 'pending' : 'success';
        $orderStatus = 'confirmed';
        $orderRef = 'ORD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $insertOrder = $db->prepare(
            "INSERT INTO orders
             (user_id, order_ref, customer_name, customer_email, customer_phone,
              tickets_subtotal, addons_subtotal, gross_subtotal, discount_amount, loyalty_discount,
              booking_fee, tax_amount, total_amount, promo_code, loyalty_points_used, loyalty_points_earned,
              payment_method, payment_status, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $promoForOrder = $pricing['promo_code'] ?: null;
        $ticketsSubtotal = (float)$pricing['tickets_subtotal'];
        $addonsSubtotal = (float)$pricing['addons_subtotal'];
        $grossSubtotal = (float)$pricing['gross_subtotal'];
        $discountAmount = (float)$pricing['discount_amount'];
        $loyaltyDiscount = (float)$pricing['loyalty_discount'];
        $bookingFeeTotal = (float)$pricing['booking_fee'];
        $taxAmountTotal = (float)$pricing['tax_amount'];
        $orderTotal = (float)$pricing['total'];
        $orderPointsUsed = (int)$pricing['points_used'];
        $orderPointsEarned = (int)$pricing['points_earned'];
        $insertOrder->bind_param(
            'issssddddddddsiisss',
            $userId, $orderRef, $customerName, $customerEmail, $customerPhone,
            $ticketsSubtotal, $addonsSubtotal, $grossSubtotal,
            $discountAmount, $loyaltyDiscount, $bookingFeeTotal,
            $taxAmountTotal, $orderTotal, $promoForOrder, $orderPointsUsed,
            $orderPointsEarned, $paymentMethod, $paymentStatus, $orderStatus
        );
        $insertOrder->execute();
        $orderId = (int)$db->insert_id;
        $insertOrder->close();

        $groups = [];
        foreach ($checkoutItems as $item) { $groups[(int)$item['showtime_id']][] = $item; }
        $bookingIds = [];
        $bookingRefs = [];
        $grossBase = max(0.01, (float)$pricing['gross_subtotal']);
        $ticketBase = max(0.01, (float)$pricing['tickets_subtotal']);

        $insertBooking = $db->prepare(
            "INSERT INTO bookings
             (order_id, user_id, showtime_id, booking_ref, seat_summary, adult_count, kids_count,
              subtotal, booking_fee, discount_amount, tax_amount, total_amount, payment_method, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insertSeat = $db->prepare("INSERT INTO booking_seats (booking_id, seat_id, showtime_id, ticket_type, price_paid) VALUES (?, ?, ?, ?, ?)");
        $insertPayment = $db->prepare("INSERT INTO payments (booking_id, amount, method, transaction_ref, status) VALUES (?, ?, ?, ?, ?)");
        $updateAvailability = $db->prepare("UPDATE showtimes SET available_seats = GREATEST(available_seats - ?, 0) WHERE id = ?");
        $showLock = $db->prepare("SELECT id, show_date, show_time, available_seats, is_active FROM showtimes WHERE id = ? LIMIT 1 FOR UPDATE");

        foreach ($groups as $showtimeId => $groupItems) {
            $showLock->bind_param('i', $showtimeId);
            $showLock->execute();
            $lockedShow = $showLock->get_result()->fetch_assoc();
            if (!$lockedShow || !(int)$lockedShow['is_active']) { throw new RuntimeException('A selected show is no longer active.'); }
            if (strtotime($lockedShow['show_date'] . ' ' . $lockedShow['show_time']) <= time()) { throw new RuntimeException('A selected show has already started.'); }
            if ((int)$lockedShow['available_seats'] < count($groupItems)) { throw new RuntimeException('Not enough seats remain for a selected show.'); }

            $seatNumbers = [];
            $adultCount = 0; $kidsCount = 0; $groupSubtotal = 0.0;
            foreach ($groupItems as $item) {
                $seatNumbers[] = (string)$item['seat_number'];
                $groupSubtotal += (float)$item['unit_price'];
                $item['ticket_type'] === 'child' ? $kidsCount++ : $adultCount++;
            }
            $groupSubtotal = round($groupSubtotal, 2);
            $groupFee = round((float)$pricing['booking_fee'] * ($groupSubtotal / $ticketBase), 2);
            $groupDiscount = round(((float)$pricing['discount_amount'] + (float)$pricing['loyalty_discount']) * ($groupSubtotal / $grossBase), 2);
            $groupTax = round((float)$pricing['tax_amount'] * ($groupSubtotal / $grossBase), 2);
            $groupTotal = max(0, round($groupSubtotal + $groupFee + $groupTax - $groupDiscount, 2));
            $bookingRef = 'CB' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
            $seatSummary = implode(', ', $seatNumbers);
            $bookingStatus = 'confirmed';

            $insertBooking->bind_param(
                'iiissiidddddss',
                $orderId, $userId, $showtimeId, $bookingRef, $seatSummary, $adultCount, $kidsCount,
                $groupSubtotal, $groupFee, $groupDiscount, $groupTax, $groupTotal, $paymentMethod, $bookingStatus
            );
            $insertBooking->execute();
            $bookingId = (int)$db->insert_id;

            foreach ($groupItems as $item) {
                $seatId = (int)$item['seat_id']; $ticketType = (string)$item['ticket_type']; $pricePaid = (float)$item['unit_price'];
                $insertSeat->bind_param('iiisd', $bookingId, $seatId, $showtimeId, $ticketType, $pricePaid);
                $insertSeat->execute();
            }
            $transactionRef = $paymentMethod === 'cash' ? 'PAY-AT-CINEMA-' . $bookingRef : strtoupper($paymentMethod) . '-' . date('YmdHis') . '-' . random_int(1000, 9999);
            $insertPayment->bind_param('idsss', $bookingId, $groupTotal, $paymentMethod, $transactionRef, $paymentStatus);
            $insertPayment->execute();
            $seatCount = count($groupItems);
            $updateAvailability->bind_param('ii', $seatCount, $showtimeId);
            $updateAvailability->execute();
            $bookingIds[] = $bookingId; $bookingRefs[] = $bookingRef;
        }
        $showLock->close(); $insertBooking->close(); $insertSeat->close(); $insertPayment->close(); $updateAvailability->close();

        if ($addonRows) {
            $orderAddon = $db->prepare("INSERT INTO order_addons (order_id, concession_id, item_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
            $stockUpdate = $db->prepare("UPDATE concessions SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?");
            foreach ($addonRows as $addon) {
                $productId = (int)$addon['concession_id']; $qty = (int)$addon['quantity']; $unitPrice = (float)$addon['unit_price'];
                $lineTotal = round($qty * $unitPrice, 2); $name = (string)$addon['name'];
                $orderAddon->bind_param('iisidd', $orderId, $productId, $name, $qty, $unitPrice, $lineTotal);
                $orderAddon->execute();
                $stockUpdate->bind_param('iii', $qty, $productId, $qty);
                $stockUpdate->execute();
                if ($stockUpdate->affected_rows !== 1) { throw new RuntimeException('Snack stock changed during checkout. Please retry.'); }
            }
            $orderAddon->close(); $stockUpdate->close();
        }

        $orderPayment = $db->prepare("INSERT INTO order_payments (order_id, amount, method, transaction_ref, status) VALUES (?, ?, ?, ?, ?)");
        $orderTransaction = $paymentMethod === 'cash' ? 'PAY-AT-CINEMA-' . $orderRef : strtoupper($paymentMethod) . '-' . date('YmdHis') . '-' . random_int(10000, 99999);
        $orderPaymentAmount = (float)$pricing['total'];
        $orderPayment->bind_param('idsss', $orderId, $orderPaymentAmount, $paymentMethod, $orderTransaction, $paymentStatus);
        $orderPayment->execute();
        $orderPayment->close();

        if ($pricing['promo_code']) {
            $couponUse = $db->prepare("UPDATE coupons SET uses_count = uses_count + 1 WHERE code = ? AND is_active = 1");
            $usedPromoCode = (string)$pricing['promo_code'];
            $couponUse->bind_param('s', $usedPromoCode);
            $couponUse->execute();
            $couponUse->close();
        }

        $pointsUsed = (int)$pricing['points_used']; $pointsEarned = (int)$pricing['points_earned'];
        $updateUser = $db->prepare("UPDATE users SET full_name = ?, phone = ?, loyalty_points = loyalty_points - ? + ? WHERE id = ? AND loyalty_points >= ?");
        $updateUser->bind_param('ssiiii', $customerName, $customerPhone, $pointsUsed, $pointsEarned, $userId, $pointsUsed);
        $updateUser->execute();
        if ($updateUser->affected_rows !== 1) { throw new RuntimeException('Loyalty balance changed. Please retry checkout.'); }
        $updateUser->close();

        $deleteHolds = $db->prepare("DELETE FROM seat_holds WHERE cart_id = ?");
        $deleteHolds->bind_param('i', $cartId); $deleteHolds->execute(); $deleteHolds->close();
        $completeCart = $db->prepare("UPDATE carts SET status = 'checked_out' WHERE id = ? AND user_id = ?");
        $completeCart->bind_param('ii', $cartId, $userId); $completeCart->execute(); $completeCart->close();

        createNotification($db, $userId, 'Booking confirmed', "Order {$orderRef} is confirmed. Your e-tickets and invoice are ready.", 'invoice.php?order=' . urlencode($orderRef));
        $db->commit();
        unset($_SESSION['checkout_nonce']);
        $_SESSION['last_checkout'] = [
            'order_id' => $orderId, 'order_ref' => $orderRef, 'booking_ids' => $bookingIds,
            'booking_refs' => $bookingRefs, 'total' => (float)$pricing['total'],
            'payment_method' => $paymentMethod, 'customer_name' => $customerName,
            'points_earned' => $pointsEarned,
        ];
        flash('success', 'Order confirmed. Seats, add-ons, discount and loyalty points were processed successfully.');
        redirect('checkout_success.php');
    } catch (Throwable $error) {
        try { $db->rollback(); } catch (Throwable) {}
        flash('error', $error->getMessage());
        redirect('cart.php');
    }
}

$cartId = (int)$cart['id'];
$now = reservationNow();
$itemStmt = $db->prepare(
    "SELECT ci.id, ci.showtime_id, ci.ticket_type, ci.unit_price, st.show_date, st.show_time,
            m.title, m.poster, c.name AS cinema_name, s.seat_number, s.seat_class
     FROM cart_items ci
     JOIN showtimes st ON st.id = ci.showtime_id JOIN movies m ON m.id = st.movie_id
     JOIN cinemas c ON c.id = st.cinema_id JOIN seats s ON s.id = ci.seat_id
     JOIN seat_holds sh ON sh.cart_id = ci.cart_id AND sh.showtime_id = ci.showtime_id AND sh.seat_id = ci.seat_id
     WHERE ci.cart_id = ? AND sh.expires_at > ? ORDER BY st.show_date, st.show_time, m.title, s.row_label, s.seat_number_int"
);
$itemStmt->bind_param('is', $cartId, $now); $itemStmt->execute();
$items = []; $result = $itemStmt->get_result(); while ($item = $result->fetch_assoc()) { $items[] = $item; } $itemStmt->close();
if (!$items) { flash('error', 'Your reservation expired. Please select seats again.'); redirect('cart.php'); }

$addons = [];
if (tableExists($db, 'cart_addons')) {
    $addonStmt = $db->prepare("SELECT ca.quantity, ca.unit_price, co.name FROM cart_addons ca JOIN concessions co ON co.id = ca.concession_id WHERE ca.cart_id = ? ORDER BY co.name");
    $addonStmt->bind_param('i', $cartId); $addonStmt->execute();
    $addonResult = $addonStmt->get_result(); while ($addon = $addonResult->fetch_assoc()) { $addons[] = $addon; } $addonStmt->close();
}
$userStmt = $db->prepare("SELECT full_name, username, email, phone, loyalty_points FROM users WHERE id = ? LIMIT 1");
$userStmt->bind_param('i', $userId); $userStmt->execute(); $user = $userStmt->get_result()->fetch_assoc(); $userStmt->close();
$pricing = cartPricing($db, $cartId, $userId, 0);
$expiryTimestamp = cartExpiryTimestamp($cart['expires_at']);

require_once __DIR__ . '/includes/header.php';
?>
<section class="page-hero checkout-hero"><div class="container"><div class="section-tag">Idempotent secure checkout</div><h1>Complete Your <span class="accent">Cinema Order</span></h1><p>Every price, seat, coupon, stock item and loyalty point is verified again before confirmation.</p></div></section>
<section class="section"><div class="container">
<div class="checkout-steps reveal-cinema"><span class="done">1. Select Seats</span><span class="done">2. Reservation Cart</span><span class="active">3. Checkout</span><span>4. E-Ticket &amp; Invoice</span></div>
<div class="checkout-layout">
<form method="post" class="panel checkout-form reveal-cinema" data-checkout-form>
<?= csrfField() ?><input type="hidden" name="checkout_nonce" value="<?= e($_SESSION['checkout_nonce']) ?>">
<div class="checkout-section"><div class="checkout-section-head"><span>01</span><div><h2>Customer Details</h2><p>Used on the invoice and booking confirmation.</p></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Full Name *</label><input class="form-control" type="text" name="customer_name" maxlength="150" required value="<?= e($user['full_name'] ?: $user['username']) ?>"></div><div class="form-group"><label class="form-label">Email Address *</label><input class="form-control" type="email" name="customer_email" required value="<?= e($user['email']) ?>"></div></div>
<div class="form-group"><label class="form-label">Phone Number</label><input class="form-control" type="text" name="customer_phone" maxlength="40" value="<?= e($user['phone']) ?>" placeholder="+92 300 1234567"></div></div>

<div class="checkout-section"><div class="checkout-section-head"><span>02</span><div><h2>Loyalty Rewards</h2><p>You have <?= (int)$pricing['points_balance'] ?> points. Redeemable value is automatically capped for order safety.</p></div></div>
<div class="loyalty-redeem"><input class="form-control" type="number" name="redeem_points" min="0" max="<?= (int)$pricing['points_balance'] ?>" value="0"><div><strong>Redeem CinePoints</strong><span>1 point = <?= money(settingValue($db, 'loyalty_point_value', 1)) ?>. You will earn approximately <?= (int)$pricing['points_earned'] ?> new points.</span></div></div></div>

<div class="checkout-section"><div class="checkout-section-head"><span>03</span><div><h2>Payment Method</h2><p>Academic demo payment workflow; no real money is charged.</p></div></div>
<div class="payment-options">
<label class="payment-option active"><input type="radio" name="payment_method" value="cash" checked><strong>Pay at Cinema</strong><small>Order confirmed; payment remains pending.</small></label>
<label class="payment-option"><input type="radio" name="payment_method" value="card"><strong>Demo Card</strong><small>Simulated successful card payment.</small></label>
<label class="payment-option"><input type="radio" name="payment_method" value="jazzcash"><strong>JazzCash</strong><small>Simulated wallet payment.</small></label>
<label class="payment-option"><input type="radio" name="payment_method" value="easypaisa"><strong>Easypaisa</strong><small>Simulated wallet payment.</small></label>
</div>
<div class="form-group payment-reference-wrap" data-payment-reference-wrap hidden><label class="form-label" data-payment-reference-label>Demo payment details</label><input class="form-control" type="text" name="payment_reference" data-payment-reference placeholder="Enter demo reference"><p class="helper">Do not enter real financial information.</p></div></div>
<label class="terms-check"><input type="checkbox" name="terms" value="1" required><span>I confirm the showtimes, seats, child eligibility, refreshments and final pricing.</span></label>
<button class="btn btn-primary btn-block" type="submit">Place Order Securely</button>
</form>

<aside class="panel checkout-summary reveal-cinema">
<div class="reservation-timer-card compact"><div><span>Reservation expires in</span><strong data-reservation-timer data-expires="<?= $expiryTimestamp ?>" data-expired-url="<?= url('cart.php?expired=1') ?>">--:--</strong></div></div>
<h2>Booking Summary</h2><div class="checkout-items">
<?php foreach ($items as $item): ?><div class="checkout-item"><img src="<?= posterUrl($item['poster']) ?>" alt="<?= e($item['title']) ?>"><div><strong><?= e($item['title']) ?></strong><span><?= e($item['cinema_name']) ?></span><span><?= date('d M, h:i A', strtotime($item['show_date'].' '.$item['show_time'])) ?></span><span>Seat <?= e($item['seat_number']) ?> · <?= e(ucfirst($item['ticket_type'])) ?></span></div><strong><?= money($item['unit_price']) ?></strong></div><?php endforeach; ?>
<?php foreach ($addons as $addon): ?><div class="checkout-addon-line"><span><?= e($addon['name']) ?> × <?= (int)$addon['quantity'] ?></span><strong><?= money((float)$addon['unit_price'] * (int)$addon['quantity']) ?></strong></div><?php endforeach; ?>
</div>
<div class="summary-line"><span>Tickets</span><strong><?= money($pricing['tickets_subtotal']) ?></strong></div>
<?php if ($pricing['addons_subtotal'] > 0): ?><div class="summary-line"><span>Add-ons</span><strong><?= money($pricing['addons_subtotal']) ?></strong></div><?php endif; ?>
<?php if ($pricing['discount_amount'] > 0): ?><div class="summary-line discount"><span>Promo <?= e($pricing['promo_code']) ?></span><strong>−<?= money($pricing['discount_amount']) ?></strong></div><?php endif; ?>
<div class="summary-line"><span>Booking fee</span><strong><?= money($pricing['booking_fee']) ?></strong></div><div class="summary-line"><span>Tax</span><strong><?= money($pricing['tax_amount']) ?></strong></div>
<div class="summary-line total"><span>Current total</span><strong><?= money($pricing['total']) ?></strong></div><p class="helper">Total refreshes server-side after loyalty redemption.</p><a class="btn btn-secondary btn-block" href="<?= url('cart.php') ?>">Back to Cart</a>
</aside>
</div></div></section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
