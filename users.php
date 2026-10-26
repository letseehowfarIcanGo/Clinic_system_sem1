<?php
/*
 * users.php   (NEW in Stage 5)  -  SUPER ADMIN ONLY
 * Lists every login account. From here the Super Admin can add an
 * account, change a role, activate / deactivate, or reset a password.
 * The buttons post to user_action.php, which checks the rules again.
 */
require_once "config/db.php";
require_once "includes/functions.php";

require_super_admin($pdo);   // Clinic Personnel typing this address gets "Access Denied"

$me = auth_user($pdo);

$users = $pdo->query(
    "SELECT user_id, username, full_name, role, is_active, must_change_password,
            last_login_at, created_at,
            (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked
     FROM users
     ORDER BY is_active DESC, role ASC, username ASC"
)->fetchAll();

$roleLabels = role_labels();

$pageTitle  = "User Accounts";
$activePage = "users";
require "includes/header.php";
?>

        <div class="card">
            <h3>Login Accounts</h3>

            <div class="toolbar">
                <a class="btn btn-primary" href="user_form.php">+ Add Account</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php $isMe = ((int) $u['user_id'] === (int) $me['user_id']); ?>
                            <tr>
                                <td>
                                    <?php echo e($u['full_name']); ?>
                                    <?php if ($isMe): ?><span class="badge badge-neutral">You</span><?php endif; ?>
                                </td>
                                <td><?php echo e($u['username']); ?></td>
                                <td>
                                    <?php if ($isMe): ?>
                                        <?php echo e($roleLabels[$u['role']] ?? $u['role']); ?>
                                    <?php else: ?>
                                        <form method="POST" action="user_action.php" class="inline-form"
                                              data-confirm="Change the role of this account?">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="set_role">
                                            <input type="hidden" name="id" value="<?php echo (int) $u['user_id']; ?>">
                                            <select name="role" aria-label="Role for <?php echo e($u['username']); ?>">
                                                <?php foreach ($roleLabels as $value => $label): ?>
                                                    <option value="<?php echo e($value); ?>"
                                                        <?php echo $u['role'] === $value ? 'selected' : ''; ?>>
                                                        <?php echo e($label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-secondary btn-small">Save</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $u['is_active'] === 1): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">Deactivated</span>
                                    <?php endif; ?>
                                    <?php if ((int) $u['is_locked'] === 1): ?>
                                        <span class="badge badge-warning">Locked (failed logins)</span>
                                    <?php endif; ?>
                                    <?php if ((int) $u['must_change_password'] === 1): ?>
                                        <span class="badge badge-warning">Must change password</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($u['last_login_at'] ?? 'Never'); ?></td>
                                <td>
                                    <div class="actions">
                                        <?php if (!$isMe): ?>
                                            <a class="btn btn-secondary btn-small"
                                               href="user_password.php?id=<?php echo (int) $u['user_id']; ?>">Reset Password</a>

                                            <form method="POST" action="user_action.php"
                                                  data-confirm="<?php echo (int) $u['is_active'] === 1
                                                      ? 'Deactivate this account? The person will not be able to log in.'
                                                      : 'Activate this account?'; ?>">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action"
                                                       value="<?php echo (int) $u['is_active'] === 1 ? 'deactivate' : 'activate'; ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $u['user_id']; ?>">
                                                <button type="submit" class="btn btn-small <?php echo (int) $u['is_active'] === 1 ? 'btn-danger' : 'btn-secondary'; ?>">
                                                    <?php echo (int) $u['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <a class="btn btn-secondary btn-small" href="account.php">Change My Password</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="help-text">
                You cannot deactivate your own account or change your own role. Accounts are
                deactivated instead of deleted, so the system always keeps a record of them.
            </p>
        </div>

<?php require "includes/footer.php"; ?>
