<?php
/*
 * user_form.php   (NEW in Stage 5)  -  SUPER ADMIN ONLY
 * Creates a new login account (for example the Clinic Personnel account
 * used in the demonstration). The Super Admin types a first password;
 * by default the new user must replace it at their first login.
 */
require_once "config/db.php";
require_once "includes/functions.php";

require_super_admin($pdo);

$roleLabels = role_labels();
$errors     = [];
$form = ['username' => '', 'full_name' => '', 'role' => 'clinic_personnel', 'must_change' => '1'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $form['username']  = input_string($_POST, 'username');
    $form['full_name'] = input_string($_POST, 'full_name');
    $form['role']      = input_string($_POST, 'role');
    $form['must_change'] = isset($_POST['must_change']) ? '1' : '0';

    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm  = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $form['username'])) {
        $errors[] = 'The username must be 3 to 50 characters: letters, numbers, dot, dash or underscore.';
    }

    if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 100) {
        $errors[] = 'Please enter the full name (up to 100 characters).';
    }

    // The role must be one of the two known roles (never trust the drop-down).
    if (!array_key_exists($form['role'], $roleLabels)) {
        $errors[] = 'Please choose a valid role.';
    }

    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    $errors = array_merge($errors, password_problems($password, $form['username']));

    if (empty($errors)) {
        try {
            $pdo->prepare(
                "INSERT INTO users (username, full_name, password_hash, role, is_active, must_change_password)
                 VALUES (?, ?, ?, ?, 1, ?)"
            )->execute([
                $form['username'],
                $form['full_name'],
                password_hash($password, PASSWORD_DEFAULT),
                $form['role'],
                (int) $form['must_change'],
            ]);

            flash_set('success', 'Account "' . $form['username'] . '" was created.');
            redirect('users.php');

        } catch (PDOException $e) {
            // 23000 = duplicate username (the UNIQUE key on users.username).
            if ($e->getCode() === '23000') {
                $errors[] = 'That username is already taken.';
            } else {
                error_log($e->getMessage());
                $errors[] = 'The account could not be saved because of a system problem.';
            }
        }
    }
}

$pageTitle  = "Add Account";
$activePage = "users";
require "includes/header.php";
?>

        <div class="card">
            <h3>New Login Account</h3>

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

            <form method="POST" action="user_form.php" autocomplete="off">
                <?php echo csrf_field(); ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Full name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control"
                               maxlength="100" required value="<?php echo e($form['full_name']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control"
                               maxlength="50" required value="<?php echo e($form['username']); ?>">
                        <p class="form-hint">Letters, numbers, dot, dash, underscore (3-50 characters).</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" class="form-control">
                        <?php foreach ($roleLabels as $value => $label): ?>
                            <option value="<?php echo e($value); ?>"
                                <?php echo $form['role'] === $value ? 'selected' : ''; ?>>
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">
                        Clinic Personnel: the normal clinic work. Super Admin: the same, plus managing accounts.
                    </p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">First password</label>
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
                        <input type="checkbox" name="must_change" value="1"
                               <?php echo $form['must_change'] === '1' ? 'checked' : ''; ?>>
                        The person must choose a new password at the first login
                    </label>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Create Account</button>
                    <a class="btn btn-secondary" href="users.php">Cancel</a>
                </div>
            </form>
        </div>

<?php require "includes/footer.php"; ?>
