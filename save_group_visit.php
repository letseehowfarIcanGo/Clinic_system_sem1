<?php
/*
 * save_group_visit.php   (NEW in Stage 5)
 * ----------------------------------------------------------------
 * Saves a GROUP clinic visit (3 to 5 students) from the Group Log form
 * in visits.php.
 *
 * It follows the same plan as save_visit.php (one student), with one
 * important difference: ONE transaction covers the WHOLE group.
 *
 *     beginTransaction()  -> start; nothing is final yet
 *     ... validate every student, symptom and medicine ...
 *     ... insert a SEPARATE visit for each student ...
 *     commit()            -> all students saved at once
 *     rollBack()          -> if ANYTHING is wrong, NO student is saved
 *                            and NO stock is deducted
 *
 * What each student gets (exactly like a single visit):
 *     1 row in visits, rows in visit_symptoms, rows in medication_dispensed,
 *     and the stock of the chosen batches is reduced.
 * The existing Health Surveillance rule reads these same tables, so every
 * student in the group counts toward the 7-day trend automatically.
 *
 * Stock safety: if two students take the same batch, their quantities are
 * ADDED and compared with the stock once. Batches are locked (FOR UPDATE)
 * in ascending id order, and every deduction re-checks the stock in SQL.
 *
 * Steps:
 *   1. Read and check the form (no database yet)
 *   2. Start the transaction
 *   3. Check the database rules while the stock rows are LOCKED
 *   4. Save every student's visit, symptoms and medicines; deduct the stock
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

$maxMedicineRows = 10;   // per student, same limit as a single visit

/** Back to the form with the messages and what the user typed. */
function return_to_group_form(array $values, array $errors): void
{
    $_SESSION['group_visit_form'] = ['values' => $values, 'errors' => $errors];
    redirect("visits.php");
}

/* ==================================================================
   1. READ AND CHECK THE FORM  (no database used yet)
   ================================================================== */
$errors = [];

$values = [
    'students'   => [],
    'group_note' => input_string($_POST, 'group_note'),
];

if (mb_strlen($values['group_note']) > 500) {
    $errors[] = 'The group note must be 500 characters or fewer.';
}

$rawStudents = $_POST['students'] ?? [];

if (!is_array($rawStudents)) {
    $errors[] = 'The student list was not valid. Please add the students again.';
    $rawStudents = [];
}

// Reindex 0,1,2... in the order submitted.
$rawStudents = array_values($rawStudents);

if (count($rawStudents) < GROUP_MIN_STUDENTS || count($rawStudents) > GROUP_MAX_STUDENTS) {
    $errors[] = 'A group visit needs ' . GROUP_MIN_STUDENTS . ' to ' . GROUP_MAX_STUDENTS
              . ' students. You sent ' . count($rawStudents) . '.';
    // Keep only a sensible number so a huge request cannot overload the page.
    $rawStudents = array_slice($rawStudents, 0, GROUP_MAX_STUDENTS);
}

// $entries[position] = the cleaned data for each student (position 1, 2, 3 ...)
$entries     = [];
$seenStudent = [];       // student_id => position, to catch duplicates
$batchTotals = [];       // batch_id => ['quantity' => total for the GROUP, 'by' => [position => qty]]

foreach ($rawStudents as $i => $raw) {
    $pos   = $i + 1;
    $label = "Student $pos";

    if (!is_array($raw)) {
        $errors[] = "$label: the data was not valid.";
        continue;
    }

    $sv = [
        'student_id'     => isset($raw['student_id']) && is_string($raw['student_id']) ? trim($raw['student_id']) : '',
        'symptoms'       => [],
        'other_symptoms' => isset($raw['other_symptoms']) && is_string($raw['other_symptoms']) ? trim($raw['other_symptoms']) : '',
        'notes'          => isset($raw['notes']) && is_string($raw['notes']) ? trim($raw['notes']) : '',
        'medicines'      => [],
    ];

    // --- Student, and no duplicates inside the group ---
    $studentId = parse_whole_number($sv['student_id'], 1, 2147483647);

    if ($studentId === null) {
        $errors[] = "$label: please choose a student.";
    } elseif (isset($seenStudent[$studentId])) {
        $errors[] = "$label: this is the same student as Student " . $seenStudent[$studentId]
                  . ". A student can only be in the group once.";
    } else {
        $seenStudent[$studentId] = $pos;
    }

    // --- Symptoms (this student's own checkboxes) ---
    $symptomIds = [];
    $rawSymptoms = $raw['symptoms'] ?? [];

    if (!is_array($rawSymptoms)) {
        $errors[] = "$label: the symptom selection was not valid.";
        $rawSymptoms = [];
    }

    foreach ($rawSymptoms as $rawSymptom) {
        $symptomId = is_string($rawSymptom) ? parse_whole_number($rawSymptom, 1, 2147483647) : null;

        if ($symptomId === null) {
            $errors[] = "$label: one of the selected symptoms was not valid.";
            break;
        }

        $symptomIds[$symptomId] = $symptomId;
    }

    $sv['symptoms'] = array_map('strval', array_keys($symptomIds));

    if (mb_strlen($sv['other_symptoms']) > 255) {
        $errors[] = "$label: the \"Others\" symptom text must be 255 characters or fewer.";
    }

    if (mb_strlen($sv['notes']) > 1000) {
        $errors[] = "$label: the notes must be 1000 characters or fewer.";
    }

    // --- This student's medicine rows ---
    $myBatches  = [];   // batch_id => quantity, for THIS student only
    $filledRows = 0;
    $rawRows    = $raw['medicines'] ?? [];

    if (!is_array($rawRows)) {
        $errors[] = "$label: the medicine list was not valid.";
        $rawRows = [];
    }

    if (count($rawRows) > 50) {
        $errors[] = "$label: too many medicine rows were submitted.";
        $rawRows = array_slice($rawRows, 0, 50);
    }

    $rowNumber = 0;

    foreach ($rawRows as $row) {
        $rowNumber++;

        if (!is_array($row)) {
            $errors[] = "$label, medicine row $rowNumber: not valid.";
            continue;
        }

        $batchText    = isset($row['batch_id']) && is_string($row['batch_id']) ? trim($row['batch_id']) : '';
        $quantityText = isset($row['quantity']) && is_string($row['quantity']) ? trim($row['quantity']) : '';

        $sv['medicines'][] = [
            'batch_id' => substr($batchText, 0, 20),
            'quantity' => substr($quantityText, 0, 20),
        ];

        if ($batchText === '' && $quantityText === '') {
            continue;   // an empty row is skipped
        }

        $filledRows++;

        if ($batchText === '') {
            $errors[] = "$label, medicine row $rowNumber: choose a medicine and batch, or remove the row.";
            continue;
        }

        $batchId = parse_whole_number($batchText, 1, 2147483647);

        if ($batchId === null) {
            $errors[] = "$label, medicine row $rowNumber: the selected medicine batch is not valid.";
            continue;
        }

        if ($quantityText === '') {
            $errors[] = "$label, medicine row $rowNumber: enter the quantity to dispense.";
            continue;
        }

        $quantity = parse_whole_number($quantityText, 1, 1000000);

        if ($quantity === null) {
            $errors[] = "$label, medicine row $rowNumber: the quantity must be a whole number of 1 or more "
                      . "(no zero, negative numbers, decimals or letters).";
            continue;
        }

        // Same batch twice for the same student: add the quantities.
        $myBatches[$batchId] = ($myBatches[$batchId] ?? 0) + $quantity;

        // And add it to the GROUP total for that batch, remembering who asked.
        if (!isset($batchTotals[$batchId])) {
            $batchTotals[$batchId] = ['quantity' => 0, 'by' => []];
        }
        $batchTotals[$batchId]['quantity'] += $quantity;
        $batchTotals[$batchId]['by'][$pos] = ($batchTotals[$batchId]['by'][$pos] ?? 0) + $quantity;
    }

    if ($filledRows > $maxMedicineRows) {
        $errors[] = "$label: at most $maxMedicineRows medicines can be dispensed in one visit.";
    }

    $entries[$pos] = [
        'student_id' => $studentId,
        'symptoms'   => $symptomIds,
        'other'      => $sv['other_symptoms'],
        'notes'      => $sv['notes'],
        'batches'    => $myBatches,
    ];

    $values['students'][] = $sv;
}

// Problems found so far need no database. Send the user back.
if (!empty($errors)) {
    return_to_group_form($values, $errors);
}

/* ==================================================================
   2-5. THE TRANSACTION (the whole group, all or nothing)
   ================================================================== */
try {
    $pdo->beginTransaction();

    $problems = [];

    // --- Every student must exist AND be active ---
    $ids = array_column($entries, 'student_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT student_id, student_number, first_name, last_name, grade_year, section
         FROM students
         WHERE student_id IN ($placeholders) AND is_active = 1"
    );
    $stmt->execute($ids);

    $students = [];
    foreach ($stmt->fetchAll() as $row) {
        $students[(int) $row['student_id']] = $row;
    }

    $who = [];   // position => readable name used in messages

    foreach ($entries as $pos => $entry) {
        if (!isset($students[$entry['student_id']])) {
            $problems[] = "Student $pos: the chosen student does not exist or is not active.";
            $who[$pos]  = "Student $pos";
        } else {
            $s = $students[$entry['student_id']];
            $who[$pos] = "Student $pos (" . $s['student_number'] . ' ' . $s['first_name'] . ' ' . $s['last_name'] . ')';
        }
    }

    // --- Every chosen symptom must exist in the symptoms table ---
    $allSymptomIds = [];
    foreach ($entries as $entry) {
        foreach ($entry['symptoms'] as $symptomId) {
            $allSymptomIds[$symptomId] = $symptomId;
        }
    }

    $symptomNames = [];

    if (!empty($allSymptomIds)) {
        $placeholders = implode(',', array_fill(0, count($allSymptomIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT symptom_id, symptom_name
             FROM symptoms
             WHERE symptom_id IN ($placeholders)"
        );
        $stmt->execute(array_values($allSymptomIds));
        $symptomNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);   // [id => name]

        foreach ($entries as $pos => $entry) {
            foreach ($entry['symptoms'] as $symptomId) {
                if (!isset($symptomNames[$symptomId])) {
                    $problems[] = $who[$pos] . ': one of the selected symptoms no longer exists. '
                                . 'Please select the symptoms again.';
                    break;
                }
            }
        }
    }

    // --- Medicines: lock each batch ONCE, check the GROUP total ---
    // Ascending batch_id order, so two people saving at the same time
    // lock rows in the same order and cannot block each other forever.
    ksort($batchTotals);

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

    $batchInfo = [];   // batch_id => label and unit, for the summary

    foreach ($batchTotals as $batchId => $total) {
        // "Student 1: 2, Student 3: 4" - who asked for how much.
        $parts = [];
        foreach ($total['by'] as $pos => $qty) {
            $parts[] = "Student $pos: $qty";
        }
        $byText = implode(', ', $parts);

        $lockBatch->execute([$batchId]);
        $batch = $lockBatch->fetch();

        if (!$batch) {
            $problems[] = "The selected batch no longer exists ($byText).";
            continue;
        }

        $getMedicine->execute([$batch['medicine_id']]);
        $medicine = $getMedicine->fetch();

        $label = $medicine['medicine_name'] . ' (batch ' . $batch['batch_number'] . ')';

        if ((int) $medicine['is_active'] !== 1) {
            $problems[] = "$label: this medicine has been retired and cannot be dispensed ($byText).";
            continue;
        }

        if ((int) $batch['is_expired'] === 1) {
            $problems[] = "$label: this batch expired on " . $batch['expiry_date']
                        . " and cannot be dispensed ($byText).";
            continue;
        }

        $available = (int) $batch['quantity_available'];

        if ($total['quantity'] > $available) {
            $problems[] = "$label: the group asked for " . $total['quantity'] . ' ' . $medicine['unit']
                        . " in total ($byText), but only $available are available.";
            continue;
        }

        $batchInfo[$batchId] = ['label' => $label, 'unit' => $medicine['unit']];
    }

    // Any problem at all: undo everything and go back to the form.
    // Nothing has been inserted yet; rolling back also releases the locks.
    if (!empty($problems)) {
        $pdo->rollBack();
        return_to_group_form($values, $problems);
    }

    // --- Everything is valid: now save, one student after another. ---
    $saveVisit = $pdo->prepare(
        "INSERT INTO visits (student_id, notes, other_symptoms)
         VALUES (?, ?, ?)"
    );

    $saveSymptom = $pdo->prepare(
        "INSERT INTO visit_symptoms (visit_id, symptom_id)
         VALUES (?, ?)"
    );

    $saveDispensing = $pdo->prepare(
        "INSERT INTO medication_dispensed (visit_id, batch_id, quantity_dispensed)
         VALUES (?, ?, ?)"
    );

    // The WHERE conditions are a last safety check made by the database at
    // the moment of deduction: enough stock, and not expired.
    $deductStock = $pdo->prepare(
        "UPDATE medicine_batches
         SET quantity_available = quantity_available - ?
         WHERE batch_id = ?
           AND quantity_available >= ?
           AND expiry_date >= CURDATE()"
    );

    $groupSize = count($entries);
    $summaries = [];

    foreach ($entries as $pos => $entry) {
        // Notes: a tag so the history shows it was a group visit, the shared
        // group note, then this student's own note.
        $notes = '[Group log of ' . $groupSize . ' students]';

        if ($values['group_note'] !== '') {
            $notes .= ' ' . $values['group_note'];
        }

        if ($entry['notes'] !== '') {
            $notes .= "\n" . $entry['notes'];
        }

        // This student's OWN visit record.
        $saveVisit->execute([
            $entry['student_id'],
            $notes,
            $entry['other'] !== '' ? $entry['other'] : null,
        ]);

        $visitId = (int) $pdo->lastInsertId();

        // This student's OWN symptoms.
        $names = [];
        foreach ($entry['symptoms'] as $symptomId) {
            $saveSymptom->execute([$visitId, $symptomId]);
            $names[] = $symptomNames[$symptomId];
        }
        sort($names);

        // This student's OWN medicines, tied to $visitId (this student's visit),
        // so one student's medicine can never land on another student's record.
        $given = [];
        foreach ($entry['batches'] as $batchId => $quantity) {
            $saveDispensing->execute([$visitId, $batchId, $quantity]);
            $deductStock->execute([$quantity, $batchId, $quantity]);

            if ($deductStock->rowCount() !== 1) {
                // Should never happen (we hold the lock). If it does, this
                // exception rolls back EVERY student in the group.
                throw new Exception("Stock could not be deducted for batch $batchId");
            }

            $given[] = $batchInfo[$batchId]['label'] . ' x ' . $quantity . ' ' . $batchInfo[$batchId]['unit'];
        }

        $s = $students[$entry['student_id']];

        $line = $s['last_name'] . ', ' . $s['first_name'] . ' (' . $s['student_number']
              . ', ' . $s['grade_year'] . '-' . $s['section'] . ')';
        $line .= !empty($names) ? ': ' . implode(', ', $names) : ': no symptoms selected';

        if ($entry['other'] !== '') {
            $line .= '; Others: ' . $entry['other'];
        }

        $line .= !empty($given) ? '; Dispensed: ' . implode(', ', $given) : '; no medicine dispensed';

        $summaries[] = $line;
    }

    // Success: make ALL the changes permanent in one step.
    $pdo->commit();

} catch (Throwable $e) {
    // Any unexpected failure: undo the whole group.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    return_to_group_form($values, [
        'The group visit could not be saved because of a system problem. '
        . 'Nothing was saved for any student and no stock was changed. Please try again.'
    ]);
}

/* ==================================================================
   Success: short summary, then redirect (so refreshing the page
   cannot save the same group twice).
   ================================================================== */
flash_set(
    'success',
    'Group visit saved for ' . count($summaries) . ' students (one visit record each). '
    . implode(' | ', $summaries) . '.'
);
redirect("visits.php");
