<?php
$pageTitle = 'Select Seats';
require_once __DIR__ . '/includes/config.php';
requireLogin();

$db = getDB();
$userId = (int)$_SESSION['user_id'];
purgeExpiredCarts($db);

$showtimeId = (int)($_GET['showtime_id'] ?? $_POST['showtime_id'] ?? 0);
$showStmt = $db->prepare(
    "SELECT s.*, m.title, m.poster, m.duration_min, m.language,
            c.name AS cinema_name, c.city, c.address, c.total_rows, c.seats_per_row
     FROM showtimes s
     JOIN movies m ON m.id = s.movie_id
     JOIN cinemas c ON c.id = s.cinema_id
     WHERE s.id = ? AND s.is_active = 1 AND c.is_active = 1
     LIMIT 1"
);
$showStmt->bind_param('i', $showtimeId);
$showStmt->execute();
$show = $showStmt->get_result()->fetch_assoc();
$showStmt->close();

if (!$show || strtotime($show['show_date'] . ' ' . $show['show_time']) < time()) {
    flash('error', 'This show is no longer available.');
    redirect('movies.php');
}

$autoCart = getActiveCart($db, $userId, false);
$autoCartId = (int)($autoCart['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $selectionMode = (string)($_POST['selection_mode'] ?? 'manual');
    if ($selectionMode === 'auto') {
        $autoCount = max(1, min(10, (int)($_POST['auto_count'] ?? 2)));
        $preferredClass = (string)($_POST['preferred_class'] ?? 'any');
        $seatIds = findBestAvailableSeats($db, $showtimeId, (int)$show['cinema_id'], $autoCount, $preferredClass, $autoCartId);
        if (count($seatIds) !== $autoCount) {
            flash('error', 'A contiguous group of ' . $autoCount . ' seats is not currently available. Try another class or fewer seats.');
            redirect('booking.php?showtime_id=' . $showtimeId);
        }
    } else {
        $seatIds = array_values(array_unique(array_filter(
            array_map('intval', $_POST['seat_ids'] ?? []),
            static fn(int $seatId): bool => $seatId > 0
        )));
    }
    $kidsCount = max(0, (int)($_POST['kids_count'] ?? 0));

    if (!$seatIds) {
        flash('error', 'Select at least one available seat.');
        redirect('booking.php?showtime_id=' . $showtimeId);
    }
    if (count($seatIds) > 10) {
        flash('error', 'Maximum 10 seats are allowed in one cart.');
        redirect('booking.php?showtime_id=' . $showtimeId);
    }
    if ($kidsCount > count($seatIds)) {
        flash('error', 'Child tickets cannot exceed selected seats.');
        redirect('booking.php?showtime_id=' . $showtimeId);
    }

    $idList = implode(',', $seatIds);
    $expiresAt = reservationExpiry();

    try {
        purgeExpiredCarts($db);
        $db->begin_transaction();

        $cartLock = $db->prepare(
            "SELECT id, expires_at
             FROM carts
             WHERE user_id = ? AND status = 'active'
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $cartLock->bind_param('i', $userId);
        $cartLock->execute();
        $cart = $cartLock->get_result()->fetch_assoc();
        $cartLock->close();

        if ($cart) {
            $cartId = (int)$cart['id'];
        } else {
            $newCart = $db->prepare("INSERT INTO carts (user_id, status, expires_at) VALUES (?, 'active', ?)");
            $newCart->bind_param('is', $userId, $expiresAt);
            $newCart->execute();
            $cartId = (int)$db->insert_id;
            $newCart->close();
        }

        $showLock = $db->prepare(
            "SELECT id, cinema_id, show_date, show_time, is_active
             FROM showtimes
             WHERE id = ?
             FOR UPDATE"
        );
        $showLock->bind_param('i', $showtimeId);
        $showLock->execute();
        $lockedShow = $showLock->get_result()->fetch_assoc();
        $showLock->close();

        if (!$lockedShow || !(int)$lockedShow['is_active'] || strtotime($lockedShow['show_date'] . ' ' . $lockedShow['show_time']) < time()) {
            throw new RuntimeException('This show is no longer available.');
        }

        $countStmt = $db->prepare("SELECT id FROM cart_items WHERE cart_id = ? FOR UPDATE");
        $countStmt->bind_param('i', $cartId);
        $countStmt->execute();
        $currentItemCount = $countStmt->get_result()->num_rows;
        $countStmt->close();

        $existingResult = $db->query(
            "SELECT seat_id
             FROM cart_items
             WHERE cart_id = $cartId
               AND showtime_id = $showtimeId
               AND seat_id IN ($idList)
             FOR UPDATE"
        );
        $existingSeatIds = [];
        while ($existing = $existingResult->fetch_assoc()) {
            $existingSeatIds[(int)$existing['seat_id']] = true;
        }

        $newSeatCount = count(array_filter(
            $seatIds,
            static fn(int $seatId): bool => !isset($existingSeatIds[$seatId])
        ));
        if (($currentItemCount + $newSeatCount) > 10) {
            throw new RuntimeException('Your reservation cart can hold a maximum of 10 seats.');
        }

        $seatResult = $db->query(
            "SELECT *
             FROM seats
             WHERE cinema_id = " . (int)$show['cinema_id'] . "
               AND id IN ($idList)
             ORDER BY row_label, seat_number_int
             FOR UPDATE"
        );
        $selectedSeats = [];
        while ($seat = $seatResult->fetch_assoc()) {
            $selectedSeats[] = $seat;
        }
        if (count($selectedSeats) !== count($seatIds)) {
            throw new RuntimeException('One or more selected seats are invalid.');
        }

        $booked = $db->query(
            "SELECT bs.seat_id
             FROM booking_seats bs
             JOIN bookings b ON b.id = bs.booking_id
             WHERE bs.showtime_id = $showtimeId
               AND bs.seat_id IN ($idList)
               AND b.status IN ('pending','confirmed')
             LIMIT 1
             FOR UPDATE"
        );
        if ($booked->num_rows > 0) {
            throw new RuntimeException('One selected seat was just booked. Please choose another seat.');
        }

        $holdResult = $db->query(
            "SELECT seat_id, cart_id
             FROM seat_holds
             WHERE showtime_id = $showtimeId
               AND seat_id IN ($idList)
             FOR UPDATE"
        );
        $existingHolds = [];
        while ($hold = $holdResult->fetch_assoc()) {
            $existingHolds[(int)$hold['seat_id']] = (int)$hold['cart_id'];
            if ((int)$hold['cart_id'] !== $cartId) {
                throw new RuntimeException('One selected seat is temporarily reserved by another customer.');
            }
        }

        $upsertItem = $db->prepare(
            "INSERT INTO cart_items (cart_id, showtime_id, seat_id, ticket_type, unit_price)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ticket_type = VALUES(ticket_type), unit_price = VALUES(unit_price)"
        );
        $insertHold = $db->prepare(
            "INSERT INTO seat_holds (cart_id, user_id, showtime_id, seat_id, expires_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $updateHold = $db->prepare(
            "UPDATE seat_holds
             SET expires_at = ?
             WHERE cart_id = ? AND showtime_id = ? AND seat_id = ?"
        );

        foreach ($selectedSeats as $index => $seat) {
            $seatId = (int)$seat['id'];
            $seatClass = (string)$seat['seat_class'];
            $basePrice = (float)$show[$seatClass . '_price'];
            $ticketType = $index < $kidsCount ? 'child' : 'adult';
            $unitPrice = $ticketType === 'child' ? round($basePrice * 0.5, 2) : $basePrice;

            $upsertItem->bind_param('iiisd', $cartId, $showtimeId, $seatId, $ticketType, $unitPrice);
            $upsertItem->execute();

            if (isset($existingHolds[$seatId])) {
                $updateHold->bind_param('siii', $expiresAt, $cartId, $showtimeId, $seatId);
                $updateHold->execute();
            } else {
                $insertHold->bind_param('iiiis', $cartId, $userId, $showtimeId, $seatId, $expiresAt);
                $insertHold->execute();
            }
        }
        $upsertItem->close();
        $insertHold->close();
        $updateHold->close();

        $extendCart = $db->prepare("UPDATE carts SET expires_at = ? WHERE id = ? AND user_id = ? AND status = 'active'");
        $extendCart->bind_param('sii', $expiresAt, $cartId, $userId);
        $extendCart->execute();
        $extendCart->close();

        $extendHolds = $db->prepare("UPDATE seat_holds SET expires_at = ? WHERE cart_id = ?");
        $extendHolds->bind_param('si', $expiresAt, $cartId);
        $extendHolds->execute();
        $extendHolds->close();

        $db->commit();
        flash('success', 'Seats reserved for ' . RESERVATION_MINUTES . ' minutes and added to your cart.');
        redirect('cart.php');
    } catch (Throwable $error) {
        try {
            $db->rollback();
        } catch (Throwable) {
        }

        if ($error instanceof mysqli_sql_exception && $error->getCode() === 1146) {
            flash('error', 'Cart tables are missing. Import database/upgrade_cart_reservations.sql first.');
        } else {
            flash('error', $error->getMessage());
        }
        redirect('booking.php?showtime_id=' . $showtimeId);
    }
}

$activeCart = getActiveCart($db, $userId, false);
$activeCartId = (int)($activeCart['id'] ?? 0);
$now = reservationNow();

$seatStmt = $db->prepare(
    "SELECT s.*,
            EXISTS(
                SELECT 1
                FROM booking_seats bs
                JOIN bookings b ON b.id = bs.booking_id
                WHERE bs.seat_id = s.id
                  AND bs.showtime_id = ?
                  AND b.status IN ('pending','confirmed')
            ) AS booked,
            EXISTS(
                SELECT 1
                FROM seat_holds sh
                WHERE sh.seat_id = s.id
                  AND sh.showtime_id = ?
                  AND sh.expires_at > ?
                  AND sh.cart_id <> ?
            ) AS held_by_other,
            EXISTS(
                SELECT 1
                FROM seat_holds sh2
                WHERE sh2.seat_id = s.id
                  AND sh2.showtime_id = ?
                  AND sh2.expires_at > ?
                  AND sh2.cart_id = ?
            ) AS in_cart
     FROM seats s
     WHERE s.cinema_id = ?
     ORDER BY s.row_label, s.seat_number_int"
);
$showCinemaId = (int)$show['cinema_id'];
$seatStmt->bind_param(
    'iisiisii',
    $showtimeId,
    $showtimeId,
    $now,
    $activeCartId,
    $showtimeId,
    $now,
    $activeCartId,
    $showCinemaId
);
$seatStmt->execute();
$seatResult = $seatStmt->get_result();
$rows = [];
while ($seat = $seatResult->fetch_assoc()) {
    $rows[$seat['row_label']][] = $seat;
}
$seatStmt->close();

require_once __DIR__ . '/includes/header.php';
?>
<section class="page-hero booking-hero">
    <div class="container">
        <div class="section-tag">Seat selection</div>
        <h1><?= e($show['title']) ?></h1>
        <p><?= e($show['cinema_name']) ?> · <?= e(formatShowDate($show['show_date'])) ?> · <?= date('h:i A', strtotime($show['show_time'])) ?></p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="reservation-notice reveal-cinema">
            <strong>Automatic reservation:</strong>
            seats are held for <?= RESERVATION_MINUTES ?> minutes after they are added to your cart. Unpaid reservations are released automatically.
        </div>

        <form method="post" class="booking-layout" data-seat-form data-max-seats="10">
            <?= csrfField() ?>
            <input type="hidden" name="showtime_id" value="<?= $showtimeId ?>">

            <div class="panel theatre-panel reveal-cinema">
                <div class="screen"></div>
                <div class="seat-map">
                    <?php foreach ($rows as $label => $seats): ?>
                        <div class="seat-row">
                            <span class="row-label"><?= e($label) ?></span>
                            <?php foreach ($seats as $seat):
                                $price = (float)$show[$seat['seat_class'] . '_price'];
                                $unavailable = (int)$seat['booked'] || (int)$seat['held_by_other'] || (int)$seat['in_cart'];
                                $stateClass = (int)$seat['in_cart'] ? 'in-cart' : ((int)$seat['held_by_other'] ? 'held' : '');
                                $seatTitle = ucfirst($seat['seat_class']) . ' · ' . money($price);
                                if ((int)$seat['in_cart']) {
                                    $seatTitle .= ' · Already in your cart';
                                } elseif ((int)$seat['held_by_other']) {
                                    $seatTitle .= ' · Temporarily reserved';
                                } elseif ((int)$seat['booked']) {
                                    $seatTitle .= ' · Booked';
                                }
                            ?>
                                <span class="seat <?= e($seat['seat_class']) ?> <?= e($stateClass) ?>">
                                    <input id="seat-<?= (int)$seat['id'] ?>" type="checkbox" name="seat_ids[]"
                                           value="<?= (int)$seat['id'] ?>" data-price="<?= $price ?>" data-seat-row="<?= e($seat['row_label']) ?>" data-seat-number="<?= (int)$seat['seat_number_int'] ?>" data-seat-class="<?= e($seat['seat_class']) ?>"
                                           <?= $unavailable ? 'disabled' : '' ?>>
                                    <label for="seat-<?= (int)$seat['id'] ?>" title="<?= e($seatTitle) ?>">
                                        <?= e($seat['seat_number']) ?>
                                    </label>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="legend">
                    <span><i style="background:#262a36"></i>Available</span>
                    <span><i style="background:var(--red)"></i>Selected</span>
                    <span><i style="background:#0d0f14"></i>Booked</span>
                    <span><i style="background:#9b6d12"></i>Reserved</span>
                    <span><i style="background:#174d3b"></i>In your cart</span>
                    <span>Gold <?= money($show['gold_price']) ?></span>
                    <span>Platinum <?= money($show['platinum_price']) ?></span>
                    <span>Box <?= money($show['box_price']) ?></span>
                </div>
            </div>

            <aside class="panel booking-summary reveal-cinema">
                <img src="<?= posterUrl($show['poster']) ?>" alt="<?= e($show['title']) ?>"
                     style="height:170px;width:100%;object-fit:cover;border-radius:14px;margin-bottom:16px">
                <h3><?= e($show['title']) ?></h3>
                <p class="helper"><?= e($show['cinema_name']) ?><br><?= e(formatShowDate($show['show_date'])) ?> at <?= date('h:i A', strtotime($show['show_time'])) ?></p>

                <div class="auto-seat-box">
                    <div class="auto-seat-head">
                        <div><strong>Smart Auto Reservation</strong><span>Best centered, contiguous seats selected server-side.</span></div>
                        <span class="ai-pill">SMART</span>
                    </div>
                    <div class="auto-seat-fields">
                        <label>Seats
                            <input class="form-control" type="number" name="auto_count" min="1" max="10" value="2">
                        </label>
                        <label>Preferred class
                            <select class="form-control" name="preferred_class">
                                <option value="any">Best available</option>
                                <option value="gold">Gold</option>
                                <option value="platinum">Platinum</option>
                                <option value="box">Box</option>
                            </select>
                        </label>
                    </div>
                    <button class="btn btn-secondary btn-block" type="submit" name="selection_mode" value="auto">Auto-Select &amp; Reserve</button>
                </div>

                <div class="manual-divider"><span>or choose manually</span></div>
                <div class="summary-line"><span>Selected seats</span><strong data-seat-count>0</strong></div>
                <div class="form-group" style="margin-top:14px">
                    <label class="form-label">Children aged 3–12 (50% off)</label>
                    <input class="form-control" type="number" name="kids_count" min="0" value="0">
                </div>
                <div class="summary-line"><span>Estimated subtotal</span><strong data-seat-subtotal>PKR 0</strong></div>
                <p class="helper">Configurable booking fee and tax are calculated at checkout. Maximum 10 reserved seats per cart.</p>
                <button class="btn btn-primary btn-block" type="submit" name="selection_mode" value="manual">Reserve Selected Seats</button>
                <?php if ($activeCartId > 0): ?>
                    <a class="btn btn-secondary btn-block" href="<?= url('cart.php') ?>" style="margin-top:10px">View Reservation Cart</a>
                <?php endif; ?>
            </aside>
        </form>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
