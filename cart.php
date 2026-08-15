<?php
$pageTitle = 'Reservation Cart';
require_once __DIR__ . '/includes/config.php';
requireLogin();

$db = getDB();
$userId = (int)$_SESSION['user_id'];
purgeExpiredCarts($db);
if (isset($_GET['expired'])) {
    flash('info', 'Your reservation timer expired. Unpaid seats and add-ons were released automatically.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        $db->begin_transaction();
        $now = reservationNow();
        $cartStmt = $db->prepare(
            "SELECT id, expires_at, extension_count
             FROM carts
             WHERE user_id = ? AND status = 'active' AND expires_at > ?
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $cartStmt->bind_param('is', $userId, $now);
        $cartStmt->execute();
        $lockedCart = $cartStmt->get_result()->fetch_assoc();
        $cartStmt->close();
        if (!$lockedCart) {
            throw new RuntimeException('Your reservation has expired. Please select seats again.');
        }
        $cartId = (int)$lockedCart['id'];

        if ($action === 'remove') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $itemStmt = $db->prepare("SELECT showtime_id, seat_id FROM cart_items WHERE id = ? AND cart_id = ? LIMIT 1 FOR UPDATE");
            $itemStmt->bind_param('ii', $itemId, $cartId);
            $itemStmt->execute();
            $item = $itemStmt->get_result()->fetch_assoc();
            $itemStmt->close();
            if (!$item) {
                throw new RuntimeException('Cart item was not found.');
            }
            $deleteHold = $db->prepare("DELETE FROM seat_holds WHERE cart_id = ? AND showtime_id = ? AND seat_id = ?");
            $showtimeId = (int)$item['showtime_id'];
            $seatId = (int)$item['seat_id'];
            $deleteHold->bind_param('iii', $cartId, $showtimeId, $seatId);
            $deleteHold->execute();
            $deleteHold->close();
            $deleteItem = $db->prepare("DELETE FROM cart_items WHERE id = ? AND cart_id = ?");
            $deleteItem->bind_param('ii', $itemId, $cartId);
            $deleteItem->execute();
            $deleteItem->close();
            $remaining = (int)$db->query("SELECT COUNT(*) AS c FROM cart_items WHERE cart_id = {$cartId}")->fetch_assoc()['c'];
            if ($remaining === 0) {
                $deleteCart = $db->prepare("DELETE FROM carts WHERE id = ? AND user_id = ?");
                $deleteCart->bind_param('ii', $cartId, $userId);
                $deleteCart->execute();
                $deleteCart->close();
            }
            $db->commit();
            flash('success', 'Seat removed and released for other customers.');
            redirect('cart.php');
        }

        if ($action === 'clear') {
            $deleteCart = $db->prepare("DELETE FROM carts WHERE id = ? AND user_id = ?");
            $deleteCart->bind_param('ii', $cartId, $userId);
            $deleteCart->execute();
            $deleteCart->close();
            $db->commit();
            flash('success', 'Reservation cart cleared and all seats released.');
            redirect('cart.php');
        }

        if ($action === 'extend') {
            if ((int)$lockedCart['extension_count'] >= 1) {
                throw new RuntimeException('The one-time reservation extension has already been used.');
            }
            $minutes = max(1, min(15, (int)settingValue($db, 'reservation_extension_minutes', 5)));
            $baseTime = max(time(), strtotime((string)$lockedCart['expires_at']) ?: time());
            $newExpiry = date('Y-m-d H:i:s', $baseTime + ($minutes * 60));
            $extend = $db->prepare("UPDATE carts SET expires_at = ?, extension_count = extension_count + 1 WHERE id = ? AND user_id = ?");
            $extend->bind_param('sii', $newExpiry, $cartId, $userId);
            $extend->execute();
            $extend->close();
            $holds = $db->prepare("UPDATE seat_holds SET expires_at = ? WHERE cart_id = ?");
            $holds->bind_param('si', $newExpiry, $cartId);
            $holds->execute();
            $holds->close();
            $db->commit();
            flash('success', "Reservation extended by {$minutes} minutes. This extension can only be used once.");
            redirect('cart.php');
        }

        if ($action === 'apply_coupon') {
            $code = strtoupper(trim((string)($_POST['promo_code'] ?? '')));
            if ($code === '') {
                throw new RuntimeException('Enter a promo code.');
            }
            $pricing = cartPricing($db, $cartId, $userId);
            $coupon = validateCoupon($db, $code, (float)$pricing['gross_subtotal'], true);
            if (!$coupon) {
                throw new RuntimeException('Promo code is invalid, expired, fully used, or the minimum order is not met.');
            }
            $set = $db->prepare("UPDATE carts SET promo_code = ? WHERE id = ? AND user_id = ?");
            $set->bind_param('sii', $code, $cartId, $userId);
            $set->execute();
            $set->close();
            $db->commit();
            flash('success', 'Promo code ' . $code . ' applied.');
            redirect('cart.php');
        }

        if ($action === 'remove_coupon') {
            $remove = $db->prepare("UPDATE carts SET promo_code = NULL WHERE id = ? AND user_id = ?");
            $remove->bind_param('ii', $cartId, $userId);
            $remove->execute();
            $remove->close();
            $db->commit();
            flash('success', 'Promo code removed.');
            redirect('cart.php');
        }

        if ($action === 'update_addons') {
            $quantities = $_POST['addon_qty'] ?? [];
            if (!is_array($quantities)) {
                $quantities = [];
            }
            $db->query("DELETE FROM cart_addons WHERE cart_id = {$cartId}");
            $select = $db->prepare("SELECT id, price, stock_qty FROM concessions WHERE id = ? AND is_active = 1 LIMIT 1 FOR UPDATE");
            $insert = $db->prepare("INSERT INTO cart_addons (cart_id, concession_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
            foreach ($quantities as $productIdRaw => $qtyRaw) {
                $productId = (int)$productIdRaw;
                $qty = max(0, min(10, (int)$qtyRaw));
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }
                $select->bind_param('i', $productId);
                $select->execute();
                $product = $select->get_result()->fetch_assoc();
                if (!$product || (int)$product['stock_qty'] < $qty) {
                    throw new RuntimeException('One selected snack is out of stock or has insufficient quantity.');
                }
                $price = (float)$product['price'];
                $insert->bind_param('iiid', $cartId, $productId, $qty, $price);
                $insert->execute();
            }
            $select->close();
            $insert->close();
            $db->commit();
            flash('success', 'Cinema snacks and add-ons updated.');
            redirect('cart.php');
        }

        throw new RuntimeException('Invalid cart action.');
    } catch (Throwable $error) {
        try { $db->rollback(); } catch (Throwable) {}
        flash('error', $error->getMessage());
        redirect('cart.php');
    }
}

$cart = getActiveCart($db, $userId, false);
$items = [];
$groups = [];
if ($cart) {
    $cartId = (int)$cart['id'];
    $now = reservationNow();
    $itemStmt = $db->prepare(
        "SELECT ci.id, ci.showtime_id, ci.seat_id, ci.ticket_type, ci.unit_price,
                st.show_date, st.show_time, m.title, m.poster, m.genre,
                c.name AS cinema_name, c.city,
                s.seat_number, s.row_label, s.seat_number_int, s.seat_class,
                sh.expires_at AS hold_expires_at
         FROM cart_items ci
         JOIN showtimes st ON st.id = ci.showtime_id
         JOIN movies m ON m.id = st.movie_id
         JOIN cinemas c ON c.id = st.cinema_id
         JOIN seats s ON s.id = ci.seat_id
         JOIN seat_holds sh ON sh.cart_id = ci.cart_id AND sh.showtime_id = ci.showtime_id AND sh.seat_id = ci.seat_id
         WHERE ci.cart_id = ? AND sh.expires_at > ?
         ORDER BY st.show_date, st.show_time, m.title, s.row_label, s.seat_number_int"
    );
    $itemStmt->bind_param('is', $cartId, $now);
    $itemStmt->execute();
    $result = $itemStmt->get_result();
    while ($item = $result->fetch_assoc()) {
        $items[] = $item;
        $showtimeId = (int)$item['showtime_id'];
        if (!isset($groups[$showtimeId])) {
            $groups[$showtimeId] = ['show' => $item, 'items' => []];
        }
        $groups[$showtimeId]['items'][] = $item;
    }
    $itemStmt->close();
}

if ($cart && !$items) {
    $cartId = (int)$cart['id'];
    $deleteEmpty = $db->prepare("DELETE FROM carts WHERE id = ? AND user_id = ? AND status = 'active'");
    $deleteEmpty->bind_param('ii', $cartId, $userId);
    $deleteEmpty->execute();
    $deleteEmpty->close();
    $cart = null;
}

$concessions = [];
$addonQuantities = [];
if ($cart && tableExists($db, 'concessions')) {
    $cartId = (int)$cart['id'];
    $addonResult = $db->query("SELECT concession_id, quantity FROM cart_addons WHERE cart_id = {$cartId}");
    while ($row = $addonResult->fetch_assoc()) {
        $addonQuantities[(int)$row['concession_id']] = (int)$row['quantity'];
    }
    $productResult = $db->query("SELECT * FROM concessions WHERE is_active = 1 AND stock_qty > 0 ORDER BY category, name");
    while ($product = $productResult->fetch_assoc()) {
        $concessions[] = $product;
    }
}

$pricing = $cart ? cartPricing($db, (int)$cart['id'], $userId) : [
    'tickets_subtotal'=>0,'addons_subtotal'=>0,'gross_subtotal'=>0,'promo_code'=>'','discount_amount'=>0,
    'booking_fee_percent'=>2,'booking_fee'=>0,'tax_percent'=>5,'tax_amount'=>0,'points_balance'=>0,'total'=>0
];
$expiryTimestamp = cartExpiryTimestamp($cart['expires_at'] ?? null);
$extensionUsed = (int)($cart['extension_count'] ?? 0) > 0;

require_once __DIR__ . '/includes/header.php';
?>
<section class="page-hero cart-hero"><div class="container"><div class="section-tag">Smart reservation cart</div><h1>Your <span class="accent">Cinema Order</span></h1><p>Seats, snacks, discounts and loyalty rewards in one secure checkout.</p></div></section>

<section class="section"><div class="container">
<?php if (!$items): ?>
    <div class="empty-state"><strong>Your reservation cart is empty</strong><p>Select a movie, showtime and seats to begin.</p><a class="btn btn-primary" href="<?= url('movies.php?status=now_showing') ?>" style="margin-top:15px">Browse Movies</a></div>
<?php else: ?>
<div class="cart-layout">
    <div class="cart-groups">
        <div class="reservation-timer-card reveal-cinema">
            <div><span>Database seat hold</span><strong data-reservation-timer data-expires="<?= $expiryTimestamp ?>" data-expired-url="<?= url('cart.php?expired=1') ?>">--:--</strong></div>
            <div class="timer-actions">
                <p>Seats release automatically when time ends.</p>
                <?php if (!$extensionUsed): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="extend"><button class="btn btn-secondary btn-sm" type="submit">Extend Once</button></form><?php else: ?><span class="used-pill">Extension used</span><?php endif; ?>
            </div>
        </div>

        <?php foreach ($groups as $group): $showInfo = $group['show']; ?>
        <article class="cart-show-card reveal-cinema">
            <div class="cart-show-head"><img src="<?= posterUrl($showInfo['poster']) ?>" alt="<?= e($showInfo['title']) ?>"><div><div class="section-tag"><?= e($showInfo['genre']) ?></div><h2><?= e($showInfo['title']) ?></h2><p><?= e($showInfo['cinema_name']) ?> · <?= e($showInfo['city']) ?></p><p><?= e(formatShowDate($showInfo['show_date'])) ?> at <?= date('h:i A', strtotime($showInfo['show_time'])) ?></p></div><a class="btn btn-secondary btn-sm" href="<?= url('booking.php?showtime_id=' . (int)$showInfo['showtime_id']) ?>">Add Seats</a></div>
            <div class="cart-seat-list">
            <?php foreach ($group['items'] as $item): ?><div class="cart-seat-item"><div><strong>Seat <?= e($item['seat_number']) ?></strong><span><?= e(ucfirst($item['seat_class'])) ?> · <?= e(ucfirst($item['ticket_type'])) ?> ticket</span></div><strong><?= money($item['unit_price']) ?></strong><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><button class="cart-remove" type="submit" data-confirm="Release this reserved seat?">Remove</button></form></div><?php endforeach; ?>
            </div>
        </article>
        <?php endforeach; ?>

        <?php if ($concessions): ?>
        <form method="post" class="panel addon-panel reveal-cinema">
            <?= csrfField() ?><input type="hidden" name="action" value="update_addons">
            <div class="addon-head"><div><div class="section-tag">Pre-order refreshments</div><h2>Snacks &amp; Combos</h2><p>Collect from the cinema counter with your e-ticket.</p></div><button class="btn btn-secondary" type="submit">Update Add-ons</button></div>
            <div class="addon-grid">
            <?php foreach ($concessions as $product): ?>
                <article class="addon-card"><img src="<?= posterUrl($product['image_path']) ?>" alt="<?= e($product['name']) ?>"><div><span><?= e(ucfirst($product['category'])) ?></span><h3><?= e($product['name']) ?></h3><p><?= e($product['description']) ?></p><strong><?= money($product['price']) ?></strong></div><label>Qty<input class="form-control addon-qty" type="number" min="0" max="10" name="addon_qty[<?= (int)$product['id'] ?>]" value="<?= (int)($addonQuantities[(int)$product['id']] ?? 0) ?>"></label></article>
            <?php endforeach; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <aside class="panel cart-summary-card reveal-cinema">
        <h2>Order Summary</h2>
        <div class="summary-line"><span>Reserved seats</span><strong><?= count($items) ?></strong></div>
        <div class="summary-line"><span>Tickets</span><strong><?= money($pricing['tickets_subtotal']) ?></strong></div>
        <?php if ($pricing['addons_subtotal'] > 0): ?><div class="summary-line"><span>Snacks &amp; add-ons</span><strong><?= money($pricing['addons_subtotal']) ?></strong></div><?php endif; ?>
        <?php if ($pricing['discount_amount'] > 0): ?><div class="summary-line discount"><span>Promo <?= e($pricing['promo_code']) ?></span><strong>−<?= money($pricing['discount_amount']) ?></strong></div><?php endif; ?>
        <div class="summary-line"><span>Booking fee (<?= e($pricing['booking_fee_percent']) ?>%)</span><strong><?= money($pricing['booking_fee']) ?></strong></div>
        <div class="summary-line"><span>Tax (<?= e($pricing['tax_percent']) ?>%)</span><strong><?= money($pricing['tax_amount']) ?></strong></div>
        <div class="summary-line total"><span>Total</span><strong><?= money($pricing['total']) ?></strong></div>

        <?php if ($pricing['promo_code']): ?>
            <form method="post" class="coupon-applied"><?= csrfField() ?><input type="hidden" name="action" value="remove_coupon"><span>✓ <?= e($pricing['promo_code']) ?> applied</span><button type="submit">Remove</button></form>
        <?php else: ?>
            <form method="post" class="coupon-form"><?= csrfField() ?><input type="hidden" name="action" value="apply_coupon"><input class="form-control" type="text" name="promo_code" placeholder="Promo code"><button class="btn btn-secondary" type="submit">Apply</button></form>
            <p class="helper">Try WELCOME10, MOVIE250 or FAMILY15.</p>
        <?php endif; ?>
        <div class="loyalty-preview"><span>Loyalty balance</span><strong><?= (int)$pricing['points_balance'] ?> points</strong><small>Redeem eligible points at checkout.</small></div>
        <a class="btn btn-primary btn-block" href="<?= url('checkout.php') ?>">Secure Checkout</a>
        <a class="btn btn-secondary btn-block" href="<?= url('movies.php') ?>" style="margin-top:10px">Continue Browsing</a>
        <form method="post" style="margin-top:10px"><?= csrfField() ?><input type="hidden" name="action" value="clear"><button class="btn btn-outline btn-block" type="submit" data-confirm="Clear cart and release all reserved seats?">Clear Reservation Cart</button></form>
        <p class="helper" style="margin-top:14px">Final availability and prices are revalidated inside a database transaction.</p>
    </aside>
</div>
<?php endif; ?>
</div></section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
