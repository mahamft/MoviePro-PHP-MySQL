<?php
declare(strict_types=1);

$pageTitle = 'Register';
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    $old = compact('username', 'fullName', 'email', 'phone');

    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
        $error = 'Username must be 3–50 letters, numbers or underscores.';
    } elseif ($fullName === '' || strlen($fullName) > 120) {
        $error = 'Enter your full name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($phone) > 30) {
        $error = 'Phone number is too long.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $db = getDB();
        $check = $db->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $check->bind_param('ss', $email, $username);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $error = 'Email or username already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                "INSERT INTO users (username, email, password, full_name, phone, profile_completed)
                 VALUES (?, ?, ?, ?, ?, 0)"
            );
            $stmt->bind_param('sssss', $username, $email, $hash, $fullName, $phone);
            $stmt->execute();
            $newUserId = (int)$db->insert_id;
            $stmt->close();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['username'] = $username;
            $_SESSION['full_name'] = $fullName;
            $_SESSION['avatar'] = '';
            $_SESSION['role'] = 'user';

            createNotification(
                $db,
                $newUserId,
                'Welcome to MoviePro',
                'Complete your profile, choose your favorite genres and start reserving premium cinema seats.',
                'profile.php'
            );
            flash('success', 'Account created. Complete your profile to personalize MoviePro.');
            redirect('profile.php?welcome=1');
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<section class="auth-page">
    <form class="auth-card reveal-cinema" method="post">
        <?= csrfField() ?>
        <div class="section-tag">Join the cinema</div>
        <h1>Create account</h1>
        <p>Register once, build your movie profile and book tickets from anywhere.</p>

        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="registerUsername">Username *</label>
                <input id="registerUsername" class="form-control" name="username" value="<?= e($old['username'] ?? '') ?>" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="registerName">Full name *</label>
                <input id="registerName" class="form-control" name="full_name" value="<?= e($old['fullName'] ?? '') ?>" autocomplete="name" required>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" for="registerEmail">Email *</label>
            <input id="registerEmail" class="form-control" type="email" name="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="email" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="registerPhone">Phone</label>
            <input id="registerPhone" class="form-control" name="phone" value="<?= e($old['phone'] ?? '') ?>" autocomplete="tel" placeholder="+92 300 1234567">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="registerPassword">Password *</label>
                <input id="registerPassword" class="form-control" type="password" name="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="registerConfirm">Confirm password *</label>
                <input id="registerConfirm" class="form-control" type="password" name="confirm" minlength="8" autocomplete="new-password" required>
            </div>
        </div>
        <button class="btn btn-primary btn-block magnetic" type="submit">Create account & set profile</button>
        <p class="helper" style="margin-top:16px">Already registered? <a class="accent" href="<?= url('login.php') ?>">Log in</a></p>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
