<?php
/*
 * account.php   (NEW in Stage 5)
 * Lets any logged-in user change THEIR OWN password. The current password
 * must be typed first, so someone who finds an unlocked computer cannot
 * silently take over the account.
 * Also used when the Super Admin created or reset the account: those
 * users are sent here first (must_change_password) and cannot open any
 * other page until they choose a new password.
 */
require_once "config/db.php";
require_once "includes/functions.php";

$user   = auth_user($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new     = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);
    $hash = $stmt->fetchColumn();

    if (!is_string($hash) || !password_verify($current, $hash)) {
        $errors[] = 'The current password is not correct.';
    }

    if ($new !== $confirm) {
        $errors[] = 'The two new passwords do not match.';
    }

    if ($new === $current && $current !== '') {
        $errors[] = 'The new password must be different from the current one.';
    }

    $errors = array_merge($errors, password_problems($new, $user['username']));

    if (empty($errors)) {
        $pdo->prepare(
            "UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?"
        )->execute([password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);

        // A password change is a good moment for a fresh session id.
        session_regenerate_id(true);

        flash_set('success', 'Your password was changed.');
        redirect('index.php');
    }
}

$mustChange = ((int) $user['must_change_password'] === 1);

$pageTitle  = "Change Password";
$activePage = "account";
require "includes/header.php";
?>

        <div class="card">
            <h3>Change My Password</h3>

            <?php if ($mustChange): ?>
                <p class="alert alert-warning">
                    Your password was set by the Super Admin. Please choose your own password to continue.
                </p>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="form-errors" role="alert">
                    <strong>The password was NOT changed:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="account.php" autocomplete="off">
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password"
                           class="form-control" maxlength="200" required autocomplete="current-password">
                </div>

                <div class="form-group">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password"
                           class="form-control" maxlength="72" required autocomplete="new-password">
                    <p class="form-hint">At least 8 characters.</p>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="form-control" maxlength="72" required autocomplete="new-password">
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </div>
            </form>
        </div>

<?php require "includes/footer.php"; ?>
