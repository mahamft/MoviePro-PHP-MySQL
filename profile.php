<?php
declare(strict_types=1);

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/config.php';
requireLogin();

$db = getDB();
$userId = (int)$_SESSION['user_id'];
$allowedGenres = ['Action', 'Adventure', 'Comedy', 'Drama', 'Horror', 'Romance', 'Sci-Fi', 'Thriller', 'Documentary', 'Family'];
$allowedLanguages = ['English', 'Urdu', 'Hindi', 'Punjabi', 'Sindhi'];
$allowedGenders = ['prefer_not', 'male', 'female', 'other'];
$errors = [];

function fetchProfile(mysqli $db, int $userId): array
{
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        session_destroy();
        redirect('login.php');
    }
    return $user;
}

function deleteProfileAvatar(?string $avatar): void
{
    $avatar = trim((string)$avatar);
    if ($avatar === '' || !preg_match('~^uploads/avatars/[A-Za-z0-9._-]+$~', $avatar)) {
        return;
    }
    $path = __DIR__ . '/' . $avatar;
    if (is_file($path)) {
        @unlink($path);
    }
}

try {
    $user = fetchProfile($db, $userId);
} catch (mysqli_sql_exception $error) {
    if (in_array($error->getCode(), [1054, 1146], true)) {
        http_response_code(500);
        exit('Profile database fields are missing. Import database/upgrade_profile_system.sql, then refresh this page.');
    }
    throw $error;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? 'update_profile');

    if ($action === 'update_profile') {
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $bio = trim((string)($_POST['bio'] ?? ''));
        $city = trim((string)($_POST['city'] ?? ''));
        $dateOfBirth = trim((string)($_POST['date_of_birth'] ?? ''));
        $gender = (string)($_POST['gender'] ?? 'prefer_not');
        $preferredLanguage = (string)($_POST['preferred_language'] ?? 'English');
        $selectedGenres = array_values(array_intersect($allowedGenres, array_map('strval', (array)($_POST['preferred_genres'] ?? []))));
        $preferredGenres = implode(', ', $selectedGenres);
        $marketingOptIn = isset($_POST['marketing_opt_in']) ? 1 : 0;

        if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            $errors[] = 'Username must contain 3–50 letters, numbers or underscores.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($fullName === '' || strlen($fullName) > 120) {
            $errors[] = 'Full name is required and must be under 120 characters.';
        }
        if (strlen($phone) > 30) {
            $errors[] = 'Phone number is too long.';
        }
        if (strlen($bio) > 600) {
            $errors[] = 'Bio must be 600 characters or fewer.';
        }
        if (strlen($city) > 100) {
            $errors[] = 'City must be 100 characters or fewer.';
        }
        if (!in_array($gender, $allowedGenders, true)) {
            $gender = 'prefer_not';
        }
        if (!in_array($preferredLanguage, $allowedLanguages, true)) {
            $preferredLanguage = 'English';
        }
        if ($dateOfBirth !== '') {
            $birth = DateTime::createFromFormat('Y-m-d', $dateOfBirth);
            if (!$birth || $birth->format('Y-m-d') !== $dateOfBirth || $birth > new DateTime('today')) {
                $errors[] = 'Enter a valid date of birth.';
            }
        } else {
            $dateOfBirth = null;
        }

        $check = $db->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
        $check->bind_param('ssi', $username, $email, $userId);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $errors[] = 'That username or email is already used by another account.';
        }
        $check->close();

        $avatarPath = (string)($user['avatar'] ?? '');
        if (!$errors && isset($_FILES['avatar']) && (int)$_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['avatar'];
            if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Avatar upload failed. Please select another image.';
            } elseif ((int)$upload['size'] > 3 * 1024 * 1024) {
                $errors[] = 'Avatar must be smaller than 3 MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file((string)$upload['tmp_name']);
                $extensions = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                if (!isset($extensions[$mime])) {
                    $errors[] = 'Avatar must be a JPG, PNG or WebP image.';
                } else {
                    $directory = __DIR__ . '/uploads/avatars';
                    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                        $errors[] = 'Avatar upload directory could not be created.';
                    } else {
                        $filename = 'user-' . $userId . '-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
                        $destination = $directory . '/' . $filename;
                        if (!move_uploaded_file((string)$upload['tmp_name'], $destination)) {
                            $errors[] = 'Avatar could not be saved.';
                        } else {
                            deleteProfileAvatar($avatarPath);
                            $avatarPath = 'uploads/avatars/' . $filename;
                        }
                    }
                }
            }
        }

        if (!$errors) {
            $stmt = $db->prepare(
                'UPDATE users
                 SET username = ?, email = ?, full_name = ?, phone = ?, bio = ?, city = ?, date_of_birth = ?,
                     gender = ?, preferred_language = ?, preferred_genres = ?, marketing_opt_in = ?, avatar = ?,
                     profile_completed = 1
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'ssssssssssisi',
                $username,
                $email,
                $fullName,
                $phone,
                $bio,
                $city,
                $dateOfBirth,
                $gender,
                $preferredLanguage,
                $preferredGenres,
                $marketingOptIn,
                $avatarPath,
                $userId
            );
            $stmt->execute();
            $stmt->close();

            $_SESSION['username'] = $username;
            $_SESSION['full_name'] = $fullName;
            $_SESSION['avatar'] = $avatarPath;
            flash('success', 'Your MoviePro profile has been updated.');
            redirect('profile.php');
        }
    } elseif ($action === 'remove_avatar') {
        deleteProfileAvatar((string)($user['avatar'] ?? ''));
        $stmt = $db->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['avatar'] = '';
        flash('success', 'Profile picture removed.');
        redirect('profile.php');
    } elseif ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, (string)$user['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt->close();
            session_regenerate_id(true);
            flash('success', 'Password changed successfully.');
            redirect('profile.php#security');
        }
    }

    $user = array_merge($user, [
        'username' => $username ?? $user['username'],
        'email' => $email ?? $user['email'],
        'full_name' => $fullName ?? $user['full_name'],
        'phone' => $phone ?? $user['phone'],
        'bio' => $bio ?? $user['bio'],
        'city' => $city ?? $user['city'],
        'date_of_birth' => $dateOfBirth ?? $user['date_of_birth'],
        'gender' => $gender ?? $user['gender'],
        'preferred_language' => $preferredLanguage ?? $user['preferred_language'],
        'preferred_genres' => $preferredGenres ?? $user['preferred_genres'],
        'marketing_opt_in' => $marketingOptIn ?? $user['marketing_opt_in'],
        'avatar' => $avatarPath ?? $user['avatar'],
    ]);
}

$stats = ['bookings' => 0, 'orders' => 0, 'reviews' => 0, 'spent' => 0.0];
$stmt = $db->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS spent FROM bookings WHERE user_id = ? AND status = 'confirmed'");
$stmt->bind_param('i', $userId);
$stmt->execute();
$bookingStats = $stmt->get_result()->fetch_assoc();
$stmt->close();
$stats['bookings'] = (int)($bookingStats['c'] ?? 0);
$stats['spent'] = (float)($bookingStats['spent'] ?? 0);

if (tableExists($db, 'orders')) {
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM orders WHERE user_id = ? AND status IN ('confirmed','partially_cancelled')");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stats['orders'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

$stmt = $db->prepare('SELECT COUNT(*) AS c FROM reviews WHERE user_id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$stats['reviews'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$recentStmt = $db->prepare(
    "SELECT b.booking_ref, b.status, b.booked_at, b.total_amount,
            m.title AS movie_title, m.poster, c.name AS cinema_name,
            s.show_date, s.show_time
     FROM bookings b
     JOIN showtimes s ON s.id = b.showtime_id
     JOIN movies m ON m.id = s.movie_id
     JOIN cinemas c ON c.id = s.cinema_id
     WHERE b.user_id = ?
     ORDER BY b.booked_at DESC
     LIMIT 4"
);
$recentStmt->bind_param('i', $userId);
$recentStmt->execute();
$recentBookings = $recentStmt->get_result();

$selectedGenres = array_filter(array_map('trim', explode(',', (string)($user['preferred_genres'] ?? ''))));
$completion = profileCompletionPercent($user);
$avatarUrl = profileAvatarUrl((string)($user['avatar'] ?? ''));
require_once __DIR__ . '/includes/header.php';
?>
<section class="profile-hero page-hero">
    <div class="container profile-hero-grid">
        <div class="profile-avatar-stage" data-tilt-card data-tilt-strength="5">
            <div class="profile-avatar-ring"></div>
            <div class="profile-avatar" data-profile-avatar>
                <?php if ($avatarUrl): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="<?= e($user['full_name'] ?: $user['username']) ?> profile picture">
                <?php else: ?>
                    <span><?= e(userInitials((string)($user['full_name'] ?: $user['username']))) ?></span>
                <?php endif; ?>
            </div>
            <span class="profile-status"><i></i> Active member</span>
        </div>
        <div class="profile-hero-copy reveal-cinema">
            <div class="section-tag">Your cinema identity</div>
            <h1><?= e($user['full_name'] ?: $user['username']) ?></h1>
            <p>@<?= e($user['username']) ?> · Member since <?= date('F Y', strtotime((string)$user['created_at'])) ?></p>
            <div class="profile-completion">
                <div><span>Profile completion</span><strong><?= $completion ?>%</strong></div>
                <i><b style="width:<?= $completion ?>%"></b></i>
            </div>
        </div>
        <div class="profile-loyalty-card reveal-cinema" data-tilt-card data-tilt-strength="4">
            <span>CinePoints balance</span>
            <strong><?= number_format((int)$user['loyalty_points']) ?></strong>
            <small>Redeem points during checkout</small>
        </div>
    </div>
</section>

<section class="section profile-page">
    <div class="container">
        <?php if ($errors): ?>
            <div class="alert alert-error" role="alert">
                <strong>Please fix the following:</strong>
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <div class="profile-stat-grid">
            <article class="profile-stat-card reveal-cinema"><span>Confirmed bookings</span><strong><?= number_format($stats['bookings']) ?></strong><small>Your completed cinema reservations</small></article>
            <article class="profile-stat-card reveal-cinema"><span>Grouped orders</span><strong><?= number_format($stats['orders']) ?></strong><small>Multi-movie checkout orders</small></article>
            <article class="profile-stat-card reveal-cinema"><span>Reviews shared</span><strong><?= number_format($stats['reviews']) ?></strong><small>Your movie ratings and feedback</small></article>
            <article class="profile-stat-card reveal-cinema"><span>Ticket value</span><strong><?= money($stats['spent']) ?></strong><small>Total confirmed booking value</small></article>
        </div>

        <div class="profile-layout">
            <div class="profile-main-column">
                <section class="profile-panel reveal-cinema" id="details">
                    <div class="profile-panel-head">
                        <div><div class="section-tag">Personal details</div><h2>Build your profile</h2></div>
                        <span class="profile-panel-badge">Private account</span>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="profile-form" data-profile-form>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div class="profile-avatar-editor">
                            <label class="avatar-upload-card" for="avatarUpload">
                                <span class="avatar-upload-preview" data-avatar-preview>
                                    <?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt="Current profile picture"><?php else: ?><b><?= e(userInitials((string)($user['full_name'] ?: $user['username']))) ?></b><?php endif; ?>
                                </span>
                                <span><strong>Change profile picture</strong><small>JPG, PNG or WebP · Maximum 3 MB</small></span>
                                <span class="btn btn-secondary btn-sm">Choose image</span>
                            </label>
                            <input id="avatarUpload" class="visually-hidden" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-avatar-input>
                            <?php if ($avatarUrl): ?>
                                <button class="btn btn-danger btn-sm" type="submit" name="action" value="remove_avatar" formnovalidate data-confirm="Remove your profile picture?">Remove picture</button>
                            <?php endif; ?>
                        </div>

                        <div class="form-row">
                            <div class="form-group"><label class="form-label" for="fullName">Full name *</label><input id="fullName" class="form-control" name="full_name" value="<?= e($user['full_name']) ?>" maxlength="120" required></div>
                            <div class="form-group"><label class="form-label" for="username">Username *</label><input id="username" class="form-control" name="username" value="<?= e($user['username']) ?>" maxlength="50" pattern="[A-Za-z0-9_]{3,50}" required></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label class="form-label" for="email">Email *</label><input id="email" class="form-control" type="email" name="email" value="<?= e($user['email']) ?>" maxlength="150" required></div>
                            <div class="form-group"><label class="form-label" for="phone">Phone</label><input id="phone" class="form-control" name="phone" value="<?= e($user['phone']) ?>" maxlength="30" placeholder="+92 300 1234567"></div>
                        </div>
                        <div class="form-group"><label class="form-label" for="bio">About you</label><textarea id="bio" class="form-control" name="bio" maxlength="600" placeholder="Tell MoviePro about your movie taste…"><?= e($user['bio']) ?></textarea><small class="helper"><span data-bio-count><?= strlen((string)$user['bio']) ?></span>/600 characters</small></div>
                        <div class="form-row">
                            <div class="form-group"><label class="form-label" for="city">City</label><input id="city" class="form-control" name="city" value="<?= e($user['city']) ?>" maxlength="100" placeholder="Karachi"></div>
                            <div class="form-group"><label class="form-label" for="dateOfBirth">Date of birth</label><input id="dateOfBirth" class="form-control" type="date" name="date_of_birth" value="<?= e($user['date_of_birth']) ?>" max="<?= date('Y-m-d') ?>"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label class="form-label" for="gender">Gender</label><select id="gender" class="form-control" name="gender"><option value="prefer_not" <?= $user['gender']==='prefer_not'?'selected':'' ?>>Prefer not to say</option><option value="male" <?= $user['gender']==='male'?'selected':'' ?>>Male</option><option value="female" <?= $user['gender']==='female'?'selected':'' ?>>Female</option><option value="other" <?= $user['gender']==='other'?'selected':'' ?>>Other</option></select></div>
                            <div class="form-group"><label class="form-label" for="preferredLanguage">Preferred language</label><select id="preferredLanguage" class="form-control" name="preferred_language"><?php foreach ($allowedLanguages as $language): ?><option value="<?= e($language) ?>" <?= $user['preferred_language']===$language?'selected':'' ?>><?= e($language) ?></option><?php endforeach; ?></select></div>
                        </div>

                        <fieldset class="genre-preference-fieldset">
                            <legend>Favorite genres</legend>
                            <div class="genre-preference-grid">
                                <?php foreach ($allowedGenres as $genre): ?>
                                    <label class="genre-preference-chip"><input type="checkbox" name="preferred_genres[]" value="<?= e($genre) ?>" <?= in_array($genre, $selectedGenres, true)?'checked':'' ?>><span><?= e($genre) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <label class="profile-consent"><input type="checkbox" name="marketing_opt_in" value="1" <?= (int)$user['marketing_opt_in']===1?'checked':'' ?>><span><strong>Movie updates and offers</strong><small>Receive release alerts, loyalty offers and cinema updates.</small></span></label>
                        <button class="btn btn-primary magnetic" type="submit">Save profile</button>
                    </form>
                </section>

                <section class="profile-panel reveal-cinema" id="security">
                    <div class="profile-panel-head"><div><div class="section-tag">Account security</div><h2>Change password</h2></div><span class="profile-panel-badge">Encrypted</span></div>
                    <form method="post" class="profile-form compact-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="change_password">
                        <div class="form-group"><label class="form-label" for="currentPassword">Current password</label><input id="currentPassword" class="form-control" type="password" name="current_password" autocomplete="current-password" required></div>
                        <div class="form-row"><div class="form-group"><label class="form-label" for="newPassword">New password</label><input id="newPassword" class="form-control" type="password" name="new_password" minlength="8" autocomplete="new-password" required></div><div class="form-group"><label class="form-label" for="confirmPassword">Confirm new password</label><input id="confirmPassword" class="form-control" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></div></div>
                        <button class="btn btn-secondary magnetic" type="submit">Update password</button>
                    </form>
                </section>
            </div>

            <aside class="profile-side-column">
                <section class="profile-panel reveal-cinema">
                    <div class="section-tag">Cinema preferences</div><h2>Your taste</h2>
                    <div class="profile-preference-list">
                        <div><span>Language</span><strong><?= e($user['preferred_language'] ?: 'English') ?></strong></div>
                        <div><span>City</span><strong><?= e($user['city'] ?: 'Not set') ?></strong></div>
                        <div><span>Favorite genres</span><strong><?= e($user['preferred_genres'] ?: 'Choose genres') ?></strong></div>
                        <div><span>Last login</span><strong><?= $user['last_login'] ? date('d M Y, h:i A', strtotime((string)$user['last_login'])) : 'First session' ?></strong></div>
                    </div>
                </section>

                <section class="profile-panel reveal-cinema">
                    <div class="profile-panel-head"><div><div class="section-tag">Recent activity</div><h2>Latest bookings</h2></div></div>
                    <div class="profile-booking-list">
                        <?php if ($recentBookings->num_rows): while ($booking = $recentBookings->fetch_assoc()): ?>
                            <a href="<?= url('booking_confirm.php?ref=' . urlencode($booking['booking_ref'])) ?>" class="profile-booking-card">
                                <img src="<?= e(posterUrl($booking['poster'])) ?>" alt="<?= e($booking['movie_title']) ?> poster" loading="lazy">
                                <span><strong><?= e($booking['movie_title']) ?></strong><small><?= e($booking['cinema_name']) ?> · <?= date('d M', strtotime((string)$booking['show_date'])) ?> · <?= date('h:i A', strtotime((string)$booking['show_time'])) ?></small><b><?= e(ucfirst((string)$booking['status'])) ?> · <?= money($booking['total_amount']) ?></b></span>
                            </a>
                        <?php endwhile; else: ?>
                            <div class="profile-empty"><span>◎</span><p>No bookings yet.</p><a class="btn btn-primary btn-sm" href="<?= url('movies.php') ?>">Explore movies</a></div>
                        <?php endif; ?>
                    </div>
                    <a class="btn btn-secondary btn-block" href="<?= url('my_bookings.php') ?>">View booking history</a>
                </section>
            </aside>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
