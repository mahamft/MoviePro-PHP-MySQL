<?php
declare(strict_types=1);

$pageTitle = 'Login';
require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    redirect(isAdmin() ? 'admin/index.php' : 'index.php');
}

$error = '';
$oldLogin = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $oldLogin = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE (email = ? OR username = ?) AND is_active = 1 LIMIT 1');
    $stmt->bind_param('ss', $oldLogin, $oldLogin);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, (string)$user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = (string)$user['username'];
        $_SESSION['full_name'] = (string)$user['full_name'];
        $_SESSION['avatar'] = (string)($user['avatar'] ?? '');
        $_SESSION['role'] = (string)$user['role'];

        $loginUserId = (int)$user['id'];
        $update = $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $update->bind_param('i', $loginUserId);
        $update->execute();
        $update->close();

        flash('success', 'Welcome back, ' . ($user['full_name'] ?: $user['username']) . '!');

        if ($user['role'] === 'admin') {
            redirect('admin/index.php');
        }
        if (!(bool)($user['profile_completed'] ?? false)) {
            flash('info', 'Set your profile picture and movie preferences to complete your account.');
            redirect('profile.php?welcome=1');
        }
        redirect('index.php');
    }

    $error = 'Invalid username/email or password.';
}

require_once __DIR__ . '/includes/header.php';
?>
<section class="auth-page">
    <form class="auth-card reveal-cinema" method="post">
        <?= csrfField() ?>
        <div class="section-tag">Member access</div>
        <h1>Welcome back</h1>
        <p>Log in to manage your profile, reservations, rewards and tickets.</p>

        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

        <div class="form-group">
            <label class="form-label" for="loginIdentity">Username or email</label>
            <input id="loginIdentity" class="form-control" name="login" value="<?= e($oldLogin) ?>" required autocomplete="username">
        </div>
        <div class="form-group">
            <label class="form-label" for="loginPassword">Password</label>
            <input id="loginPassword" class="form-control" type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary btn-block magnetic" type="submit">Log in to MoviePro</button>
       
        <p class="helper">No account? <a class="accent" href="<?= url('register.php') ?>">Create one</a></p>
    </form>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
