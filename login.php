<?php
/*
 * login.php   (NEW in Stage 5)
 * ----------------------------------------------------------------
 * The login page. It is the one page (with setup.php and logout.php)
 * that can be opened while logged out, so it says PUBLIC_PAGE first.
 *
 * What happens when the form is sent:
 *   1. CSRF token is checked.
 *   2. The user is looked up by username (prepared statement).
 *   3. If the account is locked, we stop.
 *   4. password_verify() compares the typed password with the stored hash.
 *   5. Wrong -> count the failure (5 failures lock the account 15 minutes).
 *      Right -> check the account is active, give a NEW session id, go on.
 *
 * The same message "Invalid username or password" is used whether the
 * username or the password was wrong, so the page does not reveal which
 * usernames exist.
 */
define('PUBLIC_PAGE', true);

require_once "config/db.php";
require_once "includes/functions.php";

// Already logged in? Go to the dashboard.
if (auth_user($pdo) !== null) {
    redirect('index.php');
}

$error    = '';
$username = '';
$noUsersYet = false;

try {
    $noUsersYet = ((int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0);
} catch (PDOException $e) {
    auth_database_problem($e);   // shows the "run migration M5" message
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $username = input_string($_POST, 'username');
    // The password is NOT trimmed: spaces may be part of it.
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    $genericError = 'Invalid username or password.';

    $stmt = $pdo->prepare(
        "SELECT user_id, username, password_hash, role, is_active, must_change_password,
                failed_attempts, (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
         FROM users
         WHERE username = ?"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        // Spend the same time as a real check, so the response time does
        // not reveal whether the username exists.
        password_verify($password, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
        $error = $genericError;

    } elseif ((int) $user['is_locked'] === 1) {
        $error = 'Too many failed attempts. This account is locked for a few minutes. Please try again later.';

    } elseif (!password_verify($password, $user['password_hash'])) {
        // Count the failure. When the limit is reached, lock the account
        // and start counting again from zero. (In this UPDATE, locked_until
        // is written BEFORE failed_attempts on purpose: MySQL runs the SET
        // parts left to right, so it still sees the old count.)
        // (The two settings are whole-number constants, so they are safe to
        // write straight into the SQL text.)
        $maxFails = (int) MAX_FAILED_LOGINS;
        $lockMins = (int) LOCKOUT_MINUTES;

        $pdo->prepare(
            "UPDATE users
             SET locked_until = IF(failed_attempts + 1 >= $maxFails, NOW() + INTERVAL $lockMins MINUTE, locked_until),
                 failed_attempts = failed_attempts + 1
             WHERE user_id = ?"
        )->execute([$user['user_id']]);

        $pdo->prepare(
            "UPDATE users SET failed_attempts = 0
             WHERE user_id = ? AND failed_attempts >= $maxFails"
        )->execute([$user['user_id']]);

        $error = $genericError;

    } elseif ((int) $user['is_active'] !== 1) {
        // Correct password, but the Super Admin has deactivated the account.
        $error = 'This account has been deactivated. Please contact the Super Admin.';

    } else {
        // SUCCESS.
        // If PHP's recommended hashing settings have improved since the
        // password was saved, quietly store a stronger hash.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
        }

        $pdo->prepare(
            "UPDATE users
             SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW()
             WHERE user_id = ?"
        )->execute([$user['user_id']]);

        login_user($user);   // new session id + remember who logged in

        redirect('index.php');
    }
}

$loggedOut = isset($_GET['loggedout']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In | School Clinic</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="auth-page">
    <main class="auth-card">
        <h1>School Clinic</h1>
        <p class="auth-sub">Inventory &amp; Health Surveillance<br>PHINMA Araullo University - South Campus</p>

        <?php flash_render(); ?>

        <?php if ($loggedOut): ?>
            <div class="alert alert-success">You have been logged out.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($noUsersYet): ?>
            <div class="alert alert-warning">
                No accounts exist yet.
                <a href="setup.php">Create the first Super Admin account</a>.
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
            <?php echo csrf_field(); ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control"
                       maxlength="50" required autofocus
                       value="<?php echo e($username); ?>">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control"
                       maxlength="200" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary">Log In</button>
            </div>
        </form>
    </main>
</div>
</body>
</html>
