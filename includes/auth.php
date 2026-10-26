<?php
/*
 * includes/auth.php   (NEW in Stage 5)
 * ----------------------------------------------------------------
 * Everything about logging in and who is allowed to do what.
 * It is loaded automatically at the bottom of includes/functions.php,
 * so every page that already includes functions.php gets it.
 *
 * THE BIG IDEA - "protected unless said otherwise":
 *   The last lines of this file check that someone is logged in. That
 *   check runs on EVERY page that includes functions.php. Only the three
 *   pages that must be reachable while logged out (login.php, setup.php
 *   and logout.php) say  define('PUBLIC_PAGE', true);  before including
 *   functions.php. So if a new page is added later and the programmer
 *   forgets to protect it, it is protected anyway.
 *
 * Roles (users.role):
 *   clinic_personnel - the normal clinic work (every existing page)
 *   super_admin      - everything above + user management
 *
 * Contents:
 *   1. Settings
 *   2. Who is logged in?          auth_user(), is_super_admin()
 *   3. Guards                     require_super_admin(), forbidden_page()
 *   4. Login / logout helpers     login_user(), logout_user()
 *   5. Password rules             password_problems()
 *   6. The automatic check
 */


/* ==================================================================
   1. Settings
   ================================================================== */
const SESSION_IDLE_MINUTES = 60;   // logged out after this long with no activity
const MAX_FAILED_LOGINS    = 5;    // wrong passwords before the account is locked
const LOCKOUT_MINUTES      = 15;   // how long the lock lasts

// The two allowed roles and the name shown on screen.
function role_labels(): array
{
    return [
        'super_admin'      => 'Super Admin',
        'clinic_personnel' => 'Clinic Personnel',
    ];
}


/* ==================================================================
   2. Who is logged in?
   ================================================================== */

/**
 * The logged-in user as an array (user_id, username, full_name, role,
 * must_change_password), or null if nobody valid is logged in.
 *
 * The user is read from the DATABASE on every request, not trusted from
 * the session. So if the Super Admin deactivates an account or changes
 * its role, it takes effect on that person's very next click.
 */
function auth_user(PDO $pdo): ?array
{
    static $loaded = false;
    static $user   = null;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    // Idle timeout: no activity for too long -> logged out.
    $last = (int) ($_SESSION['last_activity'] ?? 0);

    if ($last === 0 || time() - $last > SESSION_IDLE_MINUTES * 60) {
        auth_reset_session();
        flash_set('warning', 'You were logged out because the page was idle for a while. Please log in again.');
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT user_id, username, full_name, role, is_active, must_change_password
             FROM users
             WHERE user_id = ?"
        );
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        auth_database_problem($e);
    }

    // Account removed or deactivated while logged in.
    if (!$row || (int) $row['is_active'] !== 1) {
        auth_reset_session();
        flash_set('danger', 'This account is no longer active.');
        return null;
    }

    $_SESSION['last_activity'] = time();
    $user = $row;

    return $user;
}

/** Is the logged-in user a Super Admin? */
function is_super_admin(PDO $pdo): bool
{
    $user = auth_user($pdo);
    return $user !== null && $user['role'] === 'super_admin';
}

/** If the users table is missing, say so clearly; otherwise show a safe message. */
function auth_database_problem(PDOException $e): void
{
    http_response_code(503);
    error_log($e->getMessage());

    // SQLSTATE 42S02 = "table does not exist" = migration M5 has not been run.
    if ($e->getCode() === '42S02') {
        die(
            '<p style="font-family: Arial; padding: 20px;">'
          . '<strong>Database update needed.</strong> '
          . 'The login system needs migration M5 (the <code>users</code> table). '
          . 'Back up your database, run <code>migrations/M5_create_users_table.sql</code> '
          . 'in phpMyAdmin, then reload this page.</p>'
        );
    }

    die('<p style="font-family: Arial; padding: 20px;">A database problem occurred. Please try again.</p>');
}


/* ==================================================================
   3. Guards (used by pages that only some roles may use)
   ================================================================== */

/** Show a "not allowed" page (HTTP 403) and stop. */
function forbidden_page(): void
{
    // header.php (included below, inside this function) needs $pdo.
    global $pdo;

    http_response_code(403);

    $pageTitle  = 'Access Denied';
    $activePage = '';
    require __DIR__ . '/header.php';
    ?>
        <div class="card">
            <h3>You do not have permission to open this page</h3>
            <p class="help-text">This page is only for the Super Admin.</p>
            <p class="help-text"><a href="index.php">Back to the Dashboard</a></p>
        </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

/**
 * Put this near the top of any page or action that only the Super Admin
 * may use. A Clinic Personnel account that types the address by hand gets
 * the "Access Denied" page and nothing runs. This is the REAL protection;
 * hiding the menu link is only for convenience.
 */
function require_super_admin(PDO $pdo): void
{
    if (!is_super_admin($pdo)) {
        forbidden_page();
    }
}


/* ==================================================================
   4. Login / logout helpers
   ================================================================== */

/** Forget everything in the session but keep the session itself. */
function auth_reset_session(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/**
 * Mark a user as logged in. session_regenerate_id(true) gives the browser
 * a NEW session id and deletes the old one, so nobody who knew the old id
 * (a "session fixation" attack) can use it.
 */
function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id']       = (int) $user['user_id'];
    $_SESSION['last_activity'] = time();

    // A fresh CSRF token for the logged-in session.
    unset($_SESSION['csrf_token']);
}

/** Log out completely: empty the session, delete its cookie, destroy it. */
function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}


/* ==================================================================
   5. Password rules
   ================================================================== */

/**
 * Returns a list of problems with a new password (empty list = fine).
 * Why 72? The hashing method (bcrypt) only reads the first 72 bytes, so
 * a longer password would silently be cut. We refuse instead.
 */
function password_problems(string $password, string $username = ''): array
{
    $problems = [];

    if (strlen($password) < 8) {
        $problems[] = 'The password must be at least 8 characters.';
    }

    if (strlen($password) > 72) {
        $problems[] = 'The password must be 72 characters or fewer.';
    }

    if ($username !== '' && strcasecmp($password, $username) === 0) {
        $problems[] = 'The password must not be the same as the username.';
    }

    return $problems;
}


/* ==================================================================
   6. The automatic check (runs on every protected page)
   ================================================================== */
if (!defined('PUBLIC_PAGE')) {
    $authPdo  = $GLOBALS['pdo'];
    $authUser = auth_user($authPdo);

    if ($authUser === null) {
        redirect('login.php');
    }

    // Pages with private information must not be kept in the browser's
    // cache, otherwise the Back button could show them after logging out.
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // A new or reset password must be replaced before anything else.
    if ((int) $authUser['must_change_password'] === 1
        && !in_array(basename($_SERVER['SCRIPT_NAME']), ['account.php', 'logout.php'], true)) {
        flash_set('warning', 'Please choose a new password before continuing.');
        redirect('account.php');
    }

    unset($authPdo, $authUser);
}
