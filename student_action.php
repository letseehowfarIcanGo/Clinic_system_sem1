<?php
/*
 * student_action.php
 * Handles the one-click actions on a student: deactivate, reactivate, delete.
 *
 * Like medicine_action.php it accepts POST only, checks the CSRF token,
 * re-checks every safety rule on the server, sets a message, and redirects.
 */
require_once "config/db.php";
require_once "includes/functions.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('students.php');
}

csrf_check();

$action = input_string($_POST, 'action');
$id     = parse_whole_number(input_string($_POST, 'id'), 1, 2147483647);

// Where to go afterwards: the student list, possibly with filters such as
// students.php?q=Reyes&status=active. We accept ONLY that shape, so this
// form cannot be used to send someone to another website.
$return = input_string($_POST, 'return');

if (!preg_match('/^students\.php(\?[A-Za-z0-9_.%+=&\-]*)?$/', $return)) {
    $return = 'students.php';
}

if ($id === null) {
    flash_set('danger', 'Invalid request.');
    redirect($return);
}

// Load the student once; every action needs the name for its message.
$stmt = $pdo->prepare(
    "SELECT first_name, last_name, student_number
     FROM students
     WHERE student_id = ?"
);
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    flash_set('danger', 'That student was not found.');
    redirect($return);
}

$fullName = $student['first_name'] . ' ' . $student['last_name'];

try {

    /* ----------------------------------------------------------------
       Deactivate / Reactivate: flips students.is_active. Nothing is deleted.
       An inactive student is hidden from the Log Clinic Visit list
       (that page only lists is_active = 1) but all visits are kept.
       ---------------------------------------------------------------- */
    if ($action === 'deactivate' || $action === 'reactivate') {
        $newValue = ($action === 'deactivate') ? 0 : 1;

        $stmt = $pdo->prepare("UPDATE students SET is_active = ? WHERE student_id = ?");
        $stmt->execute([$newValue, $id]);

        flash_set('success', $action === 'deactivate'
            ? $fullName . ' was deactivated. They no longer appear in the Log Clinic Visit list; past visits are kept.'
            : $fullName . ' was reactivated.');
        redirect($return);
    }

    /* ----------------------------------------------------------------
       Delete: ONLY if the student has no clinic visits at all.
       (The database foreign key would also refuse, as a second safety net.)
       ---------------------------------------------------------------- */
    if ($action === 'delete') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE student_id = ?");
        $stmt->execute([$id]);
        $visitCount = (int) $stmt->fetchColumn();

        if ($visitCount > 0) {
            flash_set('danger',
                $fullName . ' has ' . $visitCount . ' clinic visit' . ($visitCount === 1 ? '' : 's') .
                ' on record, so the student cannot be deleted. Health history must be kept. ' .
                'Use Deactivate instead: the student will no longer appear in the Log Clinic Visit list, ' .
                'but past visits stay in Visit History.');
            redirect($return);
        }

        $stmt = $pdo->prepare("DELETE FROM students WHERE student_id = ?");
        $stmt->execute([$id]);

        flash_set('success', 'Student ' . $fullName . ' (' . $student['student_number'] . ') was deleted.');
        redirect($return);
    }

    // Unknown action name.
    flash_set('danger', 'Unknown action.');
    redirect($return);

} catch (PDOException $ex) {
    // For example: a visit was saved for this student a moment ago,
    // so the database refused the delete.
    error_log($ex->getMessage());
    flash_set('danger', 'The action could not be completed because this student record is still in use.');
    redirect($return);
}
