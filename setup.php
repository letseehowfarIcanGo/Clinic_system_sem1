<?php
/*
 * setup.php   (NEW in Stage 5)
 * ----------------------------------------------------------------
 * ONE-TIME page that creates the FIRST Super Admin account.
 *
 * Why not a default account like admin / admin123? Everybody knows
 * default passwords, so a system with one is not really protected.
 * Here YOU choose the username and password, once, on your own computer.
 *
 * Safety rules:
 *   - Only works while the users table is EMPTY. After the first account
 *     exists this page refuses to do anything, forever.
 *   - Only works from the computer running XAMPP (localhost).
 *   - The "is the table empty?" check and the INSERT are ONE SQL
 *     statement, so two people cannot both become the first admin.
 *   - The password is stored only as a password_hash() hash.
 */
define('PUBLIC_PAGE', true);

require_once "config/db.php";
require_once "includes/functions.php";

// Only from the same computer.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die('First-time setup can only be done on the computer that runs the system (localhost).');
}

try {
    $userCount = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (PDOException $e) {
    auth_database_problem($e);   // "run migration M5" message
}

$setupOpen = ($userCount === 0);
$errors    = [];
$username  = '';
$fullName  = '';

if ($setupOpen && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $username = input_string($_POST, 'username');
    $fullName = input_string($_POST, 'full_name');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm  = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        $errors[] = 'The username must be 3 to 50 characters: letters, numbers, dot, dash or underscore.';
    }

    if ($fullName === '' || mb_strlen($fullName) > 100) {
        $errors[] = 'Please enter the full name (up to 100 characters).';
    }

    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    $errors = array_merge($errors, password_problems($password, $username));

    if (empty($errors)) {
        // One statement: insert ONLY IF the table is still empty.
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, full_name, password_hash, role, is_active, must_change_password)
             SELECT ?, ?, ?, 'super_admin', 1, 0 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM users)"
        );
        $stmt->execute([$username, $fullName, password_hash($password, PASSWORD_DEFAULT)]);

        if ($stmt->rowCount() === 1) {
            flash_set('success', 'Super Admin account created. You can log in now.');
            redirect('login.php');
        }

        // Someone else created an account a moment ago.
        $setupOpen = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First-Time Setup | School Clinic</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="auth-page">
    <main class="auth-card">
        <h1>First-Time Setup</h1>
        <p class="auth-sub">Create the first Super Admin account</p>

        <?php if (!$setupOpen): ?>

            <div class="alert alert-warning">
                Setup has already been completed. This page is now disabled.
            </div>
            <p><a href="login.php">Go to the login page</a></p>

        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="form-errors" role="alert">
                    <strong>The account was NOT created:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="setup.php" autocomplete="off">
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control"
                           maxlength="100" required value="<?php echo e($fullName); ?>">
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control"
                           maxlength="50" required value="<?php echo e($username); ?>">
                    <p class="form-hint">Letters, numbers, dot, dash, underscore (3-50 characters).</p>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                           maxlength="72" required autocomplete="new-password">
                    <p class="form-hint">At least 8 characters.</p>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="form-control" maxlength="72" required autocomplete="new-password">
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Create Super Admin</button>
                </div>
            </form>

        <?php endif; ?>
    </main>
</div>
</body>
</html>
