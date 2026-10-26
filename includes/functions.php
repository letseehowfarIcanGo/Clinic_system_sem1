<?php
/*
 * includes/functions.php
 * ----------------------------------------------------------------
 * Helper functions shared by many pages. Include it near the top of
 * a page, after config/db.php:
 *
 *     require_once "config/db.php";
 *     require_once "includes/functions.php";
 *
 * (No closing "?>" tag on purpose: a stray space after it would be
 * sent to the browser and break redirects.)
 *
 * Contents:
 *   1. Session start
 *   2. Output and input helpers   e(), redirect(), input_string() ...
 *   3. Flash messages             flash_set(), flash_render()
 *   4. CSRF protection            csrf_field(), csrf_check()
 *   5. Medicine stock status      status_dates(), batch_status() ...
 *   6. Migration guard            require_migration_m3()
 *   7. Health trend warnings      get_trend_warnings()   (Stage 5)
 *   8. Login system               includes/auth.php      (Stage 5)
 */


/* ==================================================================
   1. Session start
   A session lets PHP remember small things between page loads. We use
   it for two things: flash messages and the CSRF token (see below).
   ================================================================== */
if (session_status() === PHP_SESSION_NONE) {
    // Stage 5: safer session settings.
    //  - own session name, so other projects on localhost (e.g. QuickBite)
    //    cannot share or overwrite this system's session
    //  - cookie only sent to this project's folder, never readable by
    //    JavaScript (httponly), and not sent along from other websites (samesite)
    //  - strict mode: PHP refuses a session id it did not create itself
    session_name('CLINICSESSID');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

    session_set_cookie_params([
        'lifetime' => 0,                                   // ends when the browser closes
        'path'     => $cookiePath,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);

    session_start();
}


/* ==================================================================
   2. Output and input helpers
   ================================================================== */

/**
 * Escape a value so it is safe to print inside HTML.
 * If a name or note contains < or >, the browser could treat it as
 * HTML. htmlspecialchars() turns those characters into harmless text.
 * Every value from the database or the user goes through e() when printed.
 */
function e($value): string
{
    // (string) ($value ?? '') makes NULL print as an empty string.
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Send the browser to another page and stop running this one.
 * (exit is important: without it the rest of the page would still run.)
 */
function redirect(string $url): void
{
    header("Location: " . $url);
    exit;
}

/**
 * Read one text value from $_GET or $_POST safely.
 * Returns a trimmed string, or '' if it is missing. Someone could send
 * ?q[]=x (an array) instead of text; this function turns that into ''
 * so the page does not crash.
 *
 * Usage: $name = input_string($_POST, 'medicine_name');
 */
function input_string(array $source, string $key): string
{
    $value = $source[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

/**
 * Turn text into a whole number, but only if it is a valid one.
 * Returns the number, or null if the text is not acceptable.
 *
 * ctype_digit() accepts ONLY the characters 0-9, so "-5", "1.5",
 * "abc" and "" are all rejected. Then we check the allowed range.
 */
function parse_whole_number($value, int $min, int $max): ?int
{
    $value = trim((string) $value);

    if ($value === '' || !ctype_digit($value)) {
        return null;
    }

    $number = (int) $value;

    if ($number < $min || $number > $max) {
        return null;
    }

    return $number;
}

/**
 * Is $date a real calendar date written as YYYY-MM-DD?
 * (createFromFormat alone would accept 2026-02-31 and "fix" it to
 * March 3, so we also check that the date prints back the same.)
 */
function is_valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}


/* ==================================================================
   3. Flash messages
   A "flash" message is saved before a redirect and shown ONCE on the
   next page, e.g. "Medicine added." header.php calls flash_render().
   $type is: success, danger, or warning (matches the CSS alert colors)
   ================================================================== */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): void
{
    foreach ($_SESSION['flash'] ?? [] as $flash) {
        echo '<div class="alert alert-' . e($flash['type']) . '">'
           . e($flash['message']) . '</div>';
    }

    // Remove them so they are not shown again on the next page.
    unset($_SESSION['flash']);
}


/* ==================================================================
   4. CSRF protection
   CSRF = another website tricking your browser into sending a form
   to this system. Defense: every form carries a secret random token
   stored in the session; the server accepts a POST only if the token
   sent back matches. A different website cannot know the token.
   Usage: put <?php echo csrf_field(); ?> inside every POST form, and
   call csrf_check() at the top of the code that handles the POST.
   ================================================================== */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
         . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';

    // hash_equals compares in a way that is safe against timing tricks.
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        die("Invalid or expired form. Please go back, refresh the page, and try again.");
    }
}


/* ==================================================================
   5. Medicine stock status
   These are the SAME status rules the system has always used. They
   live here so the Inventory page and the Dashboard can never
   disagree with each other.
   ================================================================== */

/** All status names, used for the filter drop-down. */
function stock_status_names(): array
{
    return ['Normal', 'Low Stock', 'Out of Stock', 'Expiring Soon', 'Expired', 'No Batches'];
}

/**
 * Today's date and the "expiring soon" cut-off (today + 90 days),
 * both taken from the DATABASE clock. The batch lists on the visit form
 * also use the database's CURDATE(), so every page now agrees on what
 * "today" is, whatever time zone PHP is set to.
 *
 * Returns: [today, warningDate], both like '2026-10-25'
 */
function status_dates(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT CURDATE() AS today,
                CURDATE() + INTERVAL 90 DAY AS warning_date"
    )->fetch();

    return [$row['today'], $row['warning_date']];
}

/**
 * Work out the status of ONE batch row. The first rule that matches
 * wins, so the ORDER of the rules matters (unchanged from the original
 * system): Expired, Expiring Soon, Out of Stock, Low Stock, Normal.
 *
 * "No Batches" is only for a medicine that has no batch yet; the old
 * page could never show that case.
 */
function batch_status(array $row, string $today, string $warningDate): string
{
    if ($row['expiry_date'] === null) {
        return 'No Batches';
    }

    if ($row['expiry_date'] < $today) {
        return 'Expired';
    }

    if ($row['expiry_date'] <= $warningDate) {
        return 'Expiring Soon';
    }

    if ((int) $row['quantity_available'] <= 0) {
        return 'Out of Stock';
    }

    if ((int) $row['quantity_available'] <= (int) $row['reorder_level']) {
        return 'Low Stock';
    }

    return 'Normal';
}

/** Which badge color goes with each status. */
function status_badge_class(string $status): string
{
    $classes = [
        'Expired'       => 'badge-danger',
        'Expiring Soon' => 'badge-warning',
        'Out of Stock'  => 'badge-danger',
        'Low Stock'     => 'badge-warning',
        'Normal'        => 'badge-success',
        'No Batches'    => 'badge-neutral',
    ];

    return $classes[$status] ?? 'badge-neutral';
}


/* ==================================================================
   6. Migration guard
   Stage 2 needs the medicines.is_active column (migration M3). If it
   has not been added yet, show a clear message instead of a confusing
   database error. information_schema is MySQL's built-in "catalog"
   that describes the database's own tables and columns.
   ================================================================== */
function require_migration_m3(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'medicines'
           AND COLUMN_NAME = 'is_active'"
    );

    if ((int) $stmt->fetchColumn() === 0) {
        http_response_code(503);
        die(
            '<p style="font-family: Arial; padding: 20px;">'
          . '<strong>Database update needed.</strong> '
          . 'This page needs migration M3 (the <code>medicines.is_active</code> column). '
          . 'Back up your database, run <code>migrations/M3_add_medicines_is_active.sql</code> '
          . 'in phpMyAdmin, then reload this page.</p>'
        );
    }
}

/**
 * Stage 4 needs the visits.other_symptoms column (migration M1).
 * Same idea as the M3 check above: a clear message instead of a
 * confusing database error if the column is missing.
 */
function require_migration_m1(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'visits'
           AND COLUMN_NAME = 'other_symptoms'"
    );

    if ((int) $stmt->fetchColumn() === 0) {
        http_response_code(503);
        die(
            '<p style="font-family: Arial; padding: 20px;">'
          . '<strong>Database update needed.</strong> '
          . 'This page needs migration M1 (the <code>visits.other_symptoms</code> column). '
          . 'Back up your database, run the M1 migration file in phpMyAdmin, '
          . 'then reload this page.</p>'
        );
    }
}


/* ==================================================================
   7. Health trend warnings   (Stage 5)
   ONE place that decides what counts as a warning. Both the Health
   Surveillance page and the Dashboard call this function, so they can
   never disagree. The rule is unchanged from the original system:

       the same symptom, reported by at least 3 DIFFERENT students of
       the same class (grade_year + section), within the last 7 days.

   A warning is a prompt for the nurse to review. It is NOT a diagnosis
   and NOT a confirmed outbreak.
   ================================================================== */
const TREND_WINDOW_DAYS = 7;
const TREND_MIN_STUDENTS = 3;

// Group Log: how many students one group submission may contain.
const GROUP_MIN_STUDENTS = 3;
const GROUP_MAX_STUDENTS = 5;

/**
 * Returns the current warnings, biggest cluster first. Each row has:
 * grade_year, section, symptom_name, affected_students.
 * (The two numbers are whole-number constants, so it is safe to write
 * them straight into the SQL text.)
 */
function get_trend_warnings(PDO $pdo): array
{
    $days = (int) TREND_WINDOW_DAYS;
    $min  = (int) TREND_MIN_STUDENTS;

    $sql = "SELECT
                s.grade_year,
                s.section,
                sy.symptom_name,
                COUNT(DISTINCT v.student_id) AS affected_students
            FROM visits v
            INNER JOIN students s
                ON v.student_id = s.student_id
            INNER JOIN visit_symptoms vs
                ON v.visit_id = vs.visit_id
            INNER JOIN symptoms sy
                ON vs.symptom_id = sy.symptom_id
            WHERE v.visit_date >= NOW() - INTERVAL $days DAY
            GROUP BY
                s.grade_year,
                s.section,
                sy.symptom_id,
                sy.symptom_name
            HAVING COUNT(DISTINCT v.student_id) >= $min
            ORDER BY affected_students DESC, s.grade_year ASC, s.section ASC, sy.symptom_name ASC";

    return $pdo->query($sql)->fetchAll();
}


/* ==================================================================
   8. Login system   (Stage 5)
   Loaded last, because it uses redirect(), flash_set() and e() above.
   Unless a page defines PUBLIC_PAGE first, this file makes sure the
   visitor is logged in.
   ================================================================== */
require_once __DIR__ . '/auth.php';
