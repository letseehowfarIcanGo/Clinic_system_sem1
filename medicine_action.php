<?php
/*
 * medicine_action.php
 * Handles the small one-click actions on medicines and batches:
 *   retire / reactivate / delete_medicine / delete_batch
 *
 * It only accepts POST requests (a link typed in the address bar cannot
 * delete anything), checks the CSRF token, re-checks every safety rule
 * on the server, sets a message, and redirects back.
 */
require_once "config/db.php";
require_once "includes/functions.php";
require_migration_m3($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('medicine_manage.php');
}

csrf_check();

$action = input_string($_POST, 'action');
$id     = parse_whole_number(input_string($_POST, 'id'), 1, 2147483647);

// Where to go afterwards. Only these two pages are allowed, so nobody
// can use this form to send a user to another website.
$return = input_string($_POST, 'return');

if (!in_array($return, ['medicines.php', 'medicine_manage.php'], true)) {
    $return = 'medicine_manage.php';
}

if ($id === null) {
    flash_set('danger', 'Invalid request.');
    redirect($return);
}

try {

    /* ----------------------------------------------------------------
       Retire / Reactivate: flips medicines.is_active. No data is deleted.
       ---------------------------------------------------------------- */
    if ($action === 'retire' || $action === 'reactivate') {
        $stmt = $pdo->prepare("SELECT medicine_name FROM medicines WHERE medicine_id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            flash_set('danger', 'That medicine was not found.');
            redirect($return);
        }

        $newValue = ($action === 'retire') ? 0 : 1;

        $stmt = $pdo->prepare("UPDATE medicines SET is_active = ? WHERE medicine_id = ?");
        $stmt->execute([$newValue, $id]);

        flash_set('success', $action === 'retire'
            ? '"' . $name . '" was retired. It is hidden from the active inventory and can no longer be dispensed.'
            : '"' . $name . '" was reactivated.');
        redirect($return);
    }

    /* ----------------------------------------------------------------
       Delete a medicine: ONLY if it has no batches at all.
       ---------------------------------------------------------------- */
    if ($action === 'delete_medicine') {
        $stmt = $pdo->prepare("SELECT medicine_name FROM medicines WHERE medicine_id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            flash_set('danger', 'That medicine was not found.');
            redirect($return);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM medicine_batches WHERE medicine_id = ?");
        $stmt->execute([$id]);

        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('danger', '"' . $name . '" has batch records, so it cannot be deleted. Retire it instead.');
            redirect($return);
        }

        $stmt = $pdo->prepare("DELETE FROM medicines WHERE medicine_id = ?");
        $stmt->execute([$id]);

        flash_set('success', '"' . $name . '" was deleted.');
        redirect($return);
    }

    /* ----------------------------------------------------------------
       Delete a batch: ONLY if it was never dispensed.
       (The database foreign key would also refuse, as a second safety net.)
       ---------------------------------------------------------------- */
    if ($action === 'delete_batch') {
        $stmt = $pdo->prepare(
            "SELECT b.batch_number, m.medicine_name
             FROM medicine_batches b
             INNER JOIN medicines m ON b.medicine_id = m.medicine_id
             WHERE b.batch_id = ?"
        );
        $stmt->execute([$id]);
        $batch = $stmt->fetch();

        if (!$batch) {
            flash_set('danger', 'That batch was not found.');
            redirect($return);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM medication_dispensed WHERE batch_id = ?");
        $stmt->execute([$id]);

        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('danger', 'Batch "' . $batch['batch_number'] . '" has been dispensed to patients, so it cannot be deleted. Its history must be kept.');
            redirect($return);
        }

        $stmt = $pdo->prepare("DELETE FROM medicine_batches WHERE batch_id = ?");
        $stmt->execute([$id]);

        flash_set('success', 'Batch "' . $batch['batch_number'] . '" of ' . $batch['medicine_name'] . ' was deleted.');
        redirect($return);
    }

    // Unknown action name.
    flash_set('danger', 'Unknown action.');
    redirect($return);

} catch (PDOException $ex) {
    // For example: the database refused because the record is still in use.
    error_log($ex->getMessage());
    flash_set('danger', 'The action could not be completed because this record is still in use.');
    redirect($return);
}
