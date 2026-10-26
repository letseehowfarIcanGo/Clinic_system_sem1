<?php
/*
 * save_visit.php
 * ----------------------------------------------------------------
 * Saves a clinic visit from the form in visits.php.
 *
 * THE BIG IDEA: a visit touches several tables
 *     visits, visit_symptoms, medication_dispensed, medicine_batches
 * and these changes must happen ALL TOGETHER or NOT AT ALL. We use a
 * database TRANSACTION:
 *     beginTransaction()  -> start; nothing is final yet
 *     commit()            -> make every change permanent at once
 *     rollBack()          -> undo every change since beginTransaction()
 * If anything goes wrong, we roll back, so there is never a visit
 * without its medicines, or stock deducted for a visit that was not saved.
 *
 * Steps:
 *   1. Read and check the form (no database yet)
 *   2. Start the transaction
 *   3. Check the database rules while the stock rows are LOCKED
 *   4. Save the visit, symptoms and medicines, and deduct the stock
 *   5. Commit  (or roll back + show the error messages)
 */
require_once "config/db.php";
require_once "includes/functions.php";
require_migration_m3($pdo);
require_migration_m1($pdo);

// Only a submitted form is accepted (typing the address does nothing).
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirect("visits.php");
}

csrf_check();

$maxMedicineRows = 10;

/**
 * Send the user back to the form with the error messages and the values
 * they typed, so nothing has to be typed again. visits.php shows them.
 */
function return_to_form(array $values, array $errors): void
{
    $_SESSION['visit_form'] = ['values' => $values, 'errors' => $errors];
    redirect("visits.php");
}

/* ==================================================================
   1. READ AND CHECK THE FORM  (no database used yet)
   Every problem adds a plain-language sentence to $errors.
   ================================================================== */
$errors = [];

// $values is what we send back to the form if something is wrong.
$values = [
    'student_id'     => input_string($_POST, 'student_id'),
    'symptoms'       => [],
    'other_symptoms' => input_string($_POST, 'other_symptoms'),
    'notes'          => input_string($_POST, 'notes'),
    'medicines'      => [],
];

// --- Student ---
$studentId = parse_whole_number($values['student_id'], 1, 2147483647);

if ($studentId === null) {
    $errors[] = 'Please choose a student.';
}

// --- Symptoms (checkboxes). Using the id as the array key removes duplicates. ---
$symptomIds = [];
$rawSymptoms = $_POST['symptoms'] ?? [];

if (!is_array($rawSymptoms)) {
    $errors[] = 'The symptom selection was not valid. Please select the symptoms again.';
    $rawSymptoms = [];
}

foreach ($rawSymptoms as $rawSymptom) {
    $symptomId = is_string($rawSymptom) ? parse_whole_number($rawSymptom, 1, 2147483647) : null;

    if ($symptomId === null) {
        $errors[] = 'One of the selected symptoms was not valid.';
        break;
    }

    $symptomIds[$symptomId] = $symptomId;
}

$values['symptoms'] = array_map('strval', array_keys($symptomIds));

// --- "Others" and notes (free text; length limits match the form and the database) ---
if (mb_strlen($values['other_symptoms']) > 255) {
    $errors[] = 'The "Others" symptom text must be 255 characters or fewer.';
}

if (mb_strlen($values['notes']) > 2000) {
    $errors[] = 'The visit notes must be 2000 characters or fewer.';
}

// --- Medicine rows ---
// $requested holds one entry PER BATCH. If two rows pick the same batch,
// their quantities are ADDED, so the stock check sees the true total.
//     $requested[batch_id] = ['quantity' => total, 'rows' => [row numbers]]
$requested = [];
$filledRows = 0;

$rawRows = $_POST['medicines'] ?? [];

if (!is_array($rawRows)) {
    $errors[] = 'The medicine list was not valid. Please select the medicines again.';
    $rawRows = [];
}

if (count($rawRows) > 50) {
    $errors[] = 'Too many medicine rows were submitted.';
    $rawRows = array_slice($rawRows, 0, 50);
}

$rowNumber = 0;

foreach ($rawRows as $row) {
    $rowNumber++;

    if (!is_array($row)) {
        $errors[] = "Medicine row $rowNumber was not valid.";
        continue;
    }

    $batchText    = isset($row['batch_id'])  && is_string($row['batch_id'])  ? trim($row['batch_id'])  : '';
    $quantityText = isset($row['quantity'])  && is_string($row['quantity'])  ? trim($row['quantity']) : '';

    // Remember what was typed, to refill the form if there is an error.
    $values['medicines'][] = [
        'batch_id' => substr($batchText, 0, 20),
        'quantity' => substr($quantityText, 0, 20),
    ];

    // A completely empty row is simply skipped.
    if ($batchText === '' && $quantityText === '') {
        continue;
    }

    $filledRows++;

    if ($batchText === '') {
        $errors[] = "Medicine row $rowNumber: choose a medicine and batch, or remove the row.";
        continue;
    }

    $batchId = parse_whole_number($batchText, 1, 2147483647);

    if ($batchId === null) {
        $errors[] = "Medicine row $rowNumber: the selected medicine batch is not valid.";
        continue;
    }

    if ($quantityText === '') {
        $errors[] = "Medicine row $rowNumber: enter the quantity to dispense.";
        continue;
    }

    // parse_whole_number accepts digits only: it rejects 0 (below the minimum 1),
    // negative numbers, decimals like 1.5, and letters.
    $quantity = parse_whole_number($quantityText, 1, 1000000);

    if ($quantity === null) {
        $errors[] = "Medicine row $rowNumber: the quantity must be a whole number of 1 or more "
                  . "(no zero, negative numbers, decimals or letters).";
        continue;
    }

    if (!isset($requested[$batchId])) {
        $requested[$batchId] = ['quantity' => 0, 'rows' => []];
    }

    $requested[$batchId]['quantity'] += $quantity;
    $requested[$batchId]['rows'][] = $rowNumber;
}

if ($filledRows > $maxMedicineRows) {
    $errors[] = "You can dispense at most $maxMedicineRows medicines in one visit.";
}

// Problems found so far need no database. Send the user back.
if (!empty($errors)) {
    return_to_form($values, $errors);
}

/* ==================================================================
   2-5. THE TRANSACTION
   ================================================================== */
try {
    $pdo->beginTransaction();

    // Rule problems found by checking the database (not the form itself).
    $problems = [];

    // --- The student must exist AND be active ---
    $stmt = $pdo->prepare(
        "SELECT student_number, first_name, last_name
         FROM students
         WHERE student_id = ? AND is_active = 1"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        $problems[] = 'The chosen student does not exist or is not active.';
    }

    // --- Every chosen symptom must exist in the symptoms table ---
    $symptomNames = [];

    if (!empty($symptomIds)) {
        // One "?" for each id, e.g. "?,?,?" for three symptoms.
        $placeholders = implode(',', array_fill(0, count($symptomIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT symptom_id, symptom_name
             FROM symptoms
             WHERE symptom_id IN ($placeholders)
             ORDER BY symptom_name ASC"
        );
        $stmt->execute(array_values($symptomIds));
        $symptomNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);   // [id => name]

        if (count($symptomNames) !== count($symptomIds)) {
            $problems[] = 'One of the selected symptoms no longer exists. Please select the symptoms again.';
        }
    }

    // --- Medicines: lock each batch, then re-check the rules ---
    // We always lock batches in ASCENDING batch_id order. If two people save
    // visits at the same time, they then lock rows in the same order, so they
    // wait for each other instead of blocking each other forever (deadlock).
    ksort($requested);

    // FOR UPDATE locks the row: nobody else can change this batch's stock
    // until we commit or roll back. The expiry is compared with the
    // DATABASE's own CURDATE(), the same clock the rest of the system uses.
    $lockBatch = $pdo->prepare(
        "SELECT batch_id, medicine_id, batch_number, quantity_available, expiry_date,
                (expiry_date < CURDATE()) AS is_expired
         FROM medicine_batches
         WHERE batch_id = ?
         FOR UPDATE"
    );

    $getMedicine = $pdo->prepare(
        "SELECT medicine_name, unit, is_active
         FROM medicines
         WHERE medicine_id = ?"
    );

    $toDispense = [];   // validated lines, ready to save

    foreach ($requested as $batchId => $line) {
        $rowsText = implode(', ', $line['rows']);

        $lockBatch->execute([$batchId]);
        $batch = $lockBatch->fetch();

        if (!$batch) {
            $problems[] = "Medicine row $rowsText: the selected batch no longer exists.";
            continue;
        }

        $getMedicine->execute([$batch['medicine_id']]);
        $medicine = $getMedicine->fetch();

        $label = $medicine['medicine_name'] . ' (batch ' . $batch['batch_number'] . ')';

        if ((int) $medicine['is_active'] !== 1) {
            $problems[] = "$label: this medicine has been retired and cannot be dispensed.";
            continue;
        }

        if ((int) $batch['is_expired'] === 1) {
            $problems[] = "$label: this batch expired on " . $batch['expiry_date'] . " and cannot be dispensed.";
            continue;
        }

        $available = (int) $batch['quantity_available'];

        if ($line['quantity'] > $available) {
            $problems[] = "$label: you asked for " . $line['quantity'] . ' ' . $medicine['unit']
                        . (count($line['rows']) > 1 ? " in total (rows $rowsText added together)" : '')
                        . ", but only $available are available.";
            continue;
        }

        $toDispense[] = [
            'batch_id' => $batchId,
            'quantity' => $line['quantity'],
            'label'    => $label,
            'unit'     => $medicine['unit'],
        ];
    }

    // Any problem at all: undo everything and go back to the form.
    // Nothing has been inserted yet, but rolling back also releases the locks.
    if (!empty($problems)) {
        $pdo->rollBack();
        return_to_form($values, $problems);
    }

    // --- Everything is valid: now save. ---

    // The visit itself (visit_date is filled in by the database).
    $stmt = $pdo->prepare(
        "INSERT INTO visits (student_id, notes, other_symptoms)
         VALUES (?, ?, ?)"
    );
    $stmt->execute([
        $studentId,
        $values['notes'] !== '' ? $values['notes'] : null,
        $values['other_symptoms'] !== '' ? $values['other_symptoms'] : null,
    ]);

    $visitId = (int) $pdo->lastInsertId();

    // The checked symptoms.
    $saveSymptom = $pdo->prepare(
        "INSERT INTO visit_symptoms (visit_id, symptom_id)
         VALUES (?, ?)"
    );

    foreach ($symptomIds as $symptomId) {
        $saveSymptom->execute([$visitId, $symptomId]);
    }

    // The medicines: one record per batch, then deduct the stock.
    $saveDispensing = $pdo->prepare(
        "INSERT INTO medication_dispensed (visit_id, batch_id, quantity_dispensed)
         VALUES (?, ?, ?)"
    );

    // The WHERE conditions are a last safety check made by the database
    // itself at the moment of deduction: enough stock, and not expired.
    $deductStock = $pdo->prepare(
        "UPDATE medicine_batches
         SET quantity_available = quantity_available - ?
         WHERE batch_id = ?
           AND quantity_available >= ?
           AND expiry_date >= CURDATE()"
    );

    foreach ($toDispense as $line) {
        $saveDispensing->execute([$visitId, $line['batch_id'], $line['quantity']]);

        $deductStock->execute([$line['quantity'], $line['batch_id'], $line['quantity']]);

        if ($deductStock->rowCount() !== 1) {
            // Should never happen (we hold the lock), but if it does, the
            // exception below rolls EVERYTHING back.
            throw new Exception("Stock could not be deducted for batch " . $line['batch_id']);
        }
    }

    // Success: make all the changes permanent in one step.
    $pdo->commit();

} catch (Throwable $e) {
    // Any unexpected failure (database error, etc.): undo everything.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    return_to_form($values, [
        'The visit could not be saved because of a system problem. Nothing was saved and no stock was changed. Please try again.'
    ]);
}

/* ==================================================================
   Success: build a short summary, then redirect (so refreshing the
   page cannot save the same visit twice).
   ================================================================== */
$summary = 'Visit saved for ' . $student['first_name'] . ' ' . $student['last_name']
         . ' (' . $student['student_number'] . ').';

$summary .= !empty($symptomNames)
    ? ' Symptoms: ' . implode(', ', $symptomNames) . '.'
    : ' No symptoms selected.';

if ($values['other_symptoms'] !== '') {
    $summary .= ' Others: ' . $values['other_symptoms'] . '.';
}

if (!empty($toDispense)) {
    $parts = [];

    foreach ($toDispense as $line) {
        $parts[] = $line['label'] . ' x ' . $line['quantity'] . ' ' . $line['unit'];
    }

    $summary .= ' Dispensed: ' . implode('; ', $parts) . '.';
} else {
    $summary .= ' No medicine dispensed.';
}

flash_set('success', $summary);
redirect("visits.php");
