<?php
/*
 * user_action.php   (NEW in Stage 5)  -  SUPER ADMIN ONLY
 * One-click account actions: activate, deactivate, set_role.
 * Like the other *_action.php files: POST only, CSRF token checked,
 * every rule re-checked on the server, then redirect with a message.
 */
require_once "config/db.php";
require_once "includes/functions.php";

require_super_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('users.php');
}

csrf_check();

$me     = auth_user($pdo);
$action = input_string($_POST, 'action');
$id     = parse_whole_number(input_string($_POST, 'id'), 1, 2147483647);

if ($id === null || !in_array($action, ['activate', 'deactivate', 'set_role'], true)) {
    flash_set('danger', 'Invalid request.');
    redirect('users.php');
}

// Protects against locking everyone out: the Super Admin cannot
// deactivate or demote their own account, so at least one active
// Super Admin (you) always remains.
if ($id === (int) $me['user_id']) {
    flash_set('danger', 'You cannot change your own role or deactivate your own account.');
    redirect('users.php');
}

$stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('danger', 'That account was not found.');
    redirect('users.php');
}

try {
    if ($action === 'deactivate' || $action === 'activate') {
        $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?")
            ->execute([$action === 'activate' ? 1 : 0, $id]);

        flash_set('success', 'Account "' . $target['username'] . '" was '
                . ($action === 'activate' ? 'activated.' : 'deactivated.'));

    } else { // set_role
        $role = input_string($_POST, 'role');

        if (!array_key_exists($role, role_labels())) {
            flash_set('danger', 'Please choose a valid role.');
            redirect('users.php');
        }

        $pdo->prepare("UPDATE users SET role = ? WHERE user_id = ?")->execute([$role, $id]);

        flash_set('success', 'Account "' . $target['username'] . '" is now '
                . role_labels()[$role] . '.');
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    flash_set('danger', 'The change could not be saved because of a system problem.');
}

redirect('users.php');
