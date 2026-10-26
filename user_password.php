<?php
/*
 * user_password.php   (NEW in Stage 5)  -  SUPER ADMIN ONLY
 * Password RESET for another account (for example when someone forgot
 * theirs). The Super Admin types a temporary password; by default the
 * person must replace it at their next login. The old password is never
 * shown (it cannot be: only its hash is stored).
 * Resetting also clears any "locked after failed logins" state.
 */
require_once "config/db.php";
require_once "includes/functions.php";

require_super_admin($pdo);

$me = auth_user($pdo);

$rawId = $_SERVER['REQUEST_METHOD'] === 'POST' ? input_string($_POST, 'id') : input_string($_GET, 'id');
$id    = parse_whole_number($rawId, 1, 2147483647);

if ($id === null) {
    flash_set('danger', 'Invalid request.');
    redirect('users.php');
}

// Your own password is changed on the "Change Password" page instead.
if ($id === (int) $me['user_id']) {
    redirect('account.php');
}

$stmt = $pdo->prepare("SELECT user_id, username, full_name FROM users WHERE user_id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('danger', 'That account was not found.');
    redirect('users.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $password   = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm    = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $mustChange = isset($_POST['must_change']) ? 1 : 0;

    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    $errors = array_merge($errors, password_problems($password, $target['username']));

    if (empty($errors)) {
        $pdo->prepare(
            "UPDATE users
             SET password_hash = ?, must_change_password = ?, failed_attempts = 0, locked_until = NULL
             WHERE user_id = ?"
        )->execute([password_hash($password, PASSWORD_DEFAULT), $mustChange, $id]);

        flash_set('success', 'The password for "' . $target['username'] . '" was reset.');
        redirect('users.php');
    }
}

$pageTitle  = "Reset Password";
$activePage = "users";
require "includes/header.php";
?>

        <div class="card">
            <h3>Reset Password: <?php echo e($target['full_name']); ?> (<?php echo e($target['username']); ?>)</h3>

            <?php if (!empty($errors)): ?>
                <div class="form-errors" role="alert">
                    <strong>The password was NOT reset:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="user_password.php" autocomplete="off">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Temporary password</label>
                        <input type="password" id="password" name="password" class="form-control"
                               maxlength="72" required autocomplete="new-password">
                        <p class="form-hint">At least 8 characters.</p>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="form-control" maxlength="72" required autocomplete="new-password">
                    </div>
                </div>

                <div class="form-group">
                    <label class="radio-row">
                        <input type="checkbox" name="must_change" value="1" checked>
                        The person must choose a new password at the next login
                    </label>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                    <a class="btn btn-secondary" href="users.php">Cancel</a>
                </div>
            </form>
        </div>

<?php require "includes/footer.php"; ?>
