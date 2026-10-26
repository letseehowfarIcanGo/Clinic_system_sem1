<?php
/*
 * visits.php  -  the "Log Clinic Visit" form
 *
 * This page only SHOWS the form. Saving is done by save_visit.php, which
 * checks everything again on the server and saves it as one transaction.
 * If save_visit.php finds a problem, it sends the user back here with the
 * error messages and the values they typed (kept in the session).
 *
 * Stage 5: a "Group Log" switch at the top lets the nurse log 3 to 5
 * students at once (for example after the same laboratory session).
 * The group form posts to save_group_visit.php. The normal single-student
 * form below is unchanged and still posts to save_visit.php.
 */
require_once "config/db.php";
require_once "includes/functions.php";
require_migration_m3($pdo);   // needs medicines.is_active
require_migration_m1($pdo);   // needs visits.other_symptoms

$maxMedicineRows = 10;

/* ------------------------------------------------------------------
   1. Values to put back in the form after a failed save (if any)
   They are read once and then removed from the session.
   ------------------------------------------------------------------ */
$old = $_SESSION['visit_form'] ?? [];
unset($_SESSION['visit_form']);

$errors    = $old['errors'] ?? [];
$oldValues = $old['values'] ?? [];

$oldStudentId  = (string) ($oldValues['student_id'] ?? '');
$oldSymptomIds = array_map('strval', $oldValues['symptoms'] ?? []);
$oldOther      = (string) ($oldValues['other_symptoms'] ?? '');
$oldNotes      = (string) ($oldValues['notes'] ?? '');
$oldMedicines  = array_slice($oldValues['medicines'] ?? [], 0, $maxMedicineRows);

// Always show at least one (empty) medicine row.
if (empty($oldMedicines)) {
    $oldMedicines = [['batch_id' => '', 'quantity' => '']];
}

// Stage 5: the same idea for a failed GROUP save (saved by save_group_visit.php).
$oldGroup    = $_SESSION['group_visit_form'] ?? [];
unset($_SESSION['group_visit_form']);

$groupErrors = $oldGroup['errors'] ?? [];
$groupValues = $oldGroup['values'] ?? [];
$groupMode   = !empty($oldGroup);          // reopen the Group Log after an error

/* ------------------------------------------------------------------
   2. Data for the form
   ------------------------------------------------------------------ */

// Only ACTIVE students can be chosen for a new visit.
$stmt = $pdo->query(
    "SELECT student_id, student_number, first_name,
            last_name, grade_year, section
     FROM students
     WHERE is_active = 1
     ORDER BY last_name ASC, first_name ASC"
);
$students = $stmt->fetchAll();

// Symptoms come from the symptoms table (the checklist).
$stmt = $pdo->query(
    "SELECT symptom_id, symptom_name
     FROM symptoms
     ORDER BY symptom_name ASC"
);
$symptoms = $stmt->fetchAll();

// Batches that can be dispensed: stock available, not expired, and the
// medicine is not retired. (save_visit.php checks this AGAIN when saving.)
$stmt = $pdo->query(
    "SELECT
        b.batch_id,
        b.batch_number,
        b.quantity_available,
        b.expiry_date,
        m.medicine_name,
        m.unit
     FROM medicine_batches b
     INNER JOIN medicines m
        ON b.medicine_id = m.medicine_id
     WHERE b.quantity_available > 0
       AND b.expiry_date >= CURDATE()
       AND m.is_active = 1
     ORDER BY m.medicine_name ASC, b.expiry_date ASC"
);
$medicineBatches = $stmt->fetchAll();

/**
 * Print ONE medicine row (a batch drop-down + a quantity box).
 * It is used for the rows already on the page AND for the hidden
 * <template> that the JavaScript copies when "Add another medicine"
 * is clicked. $index makes the field names unique:
 *     medicines[0][batch_id], medicines[0][quantity], medicines[1][...]
 */
function output_medicine_row($index, array $batches, string $selectedBatch, string $quantity,
                             string $namePrefix = 'medicines'): void
{
    // Stage 5: $namePrefix is "medicines" for the normal form (exactly as
    // before) and "students[2][medicines]" for student number 2 of a group.
    ?>
    <div class="medicine-row">
        <label class="row-field grow">
            Medicine and batch
            <select name="<?php echo e($namePrefix); ?>[<?php echo e($index); ?>][batch_id]" class="form-control batch-select">
                <option value="">-- None --</option>
                <?php foreach ($batches as $batch): ?>
                    <option value="<?php echo (int) $batch['batch_id']; ?>"
                            data-available="<?php echo (int) $batch['quantity_available']; ?>"
                            data-unit="<?php echo e($batch['unit']); ?>"
                            <?php echo (string) $batch['batch_id'] === $selectedBatch ? 'selected' : ''; ?>>
                        <?php
                        echo e(
                            $batch['medicine_name'] . ' | Batch ' . $batch['batch_number'] .
                            ' | Expires ' . $batch['expiry_date'] . ' | ' .
                            (int) $batch['quantity_available'] . ' ' . $batch['unit'] . ' available'
                        );
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="row-field">
            Quantity
            <input type="number" name="<?php echo e($namePrefix); ?>[<?php echo e($index); ?>][quantity]"
                   class="form-control qty-input" min="1" step="1"
                   value="<?php echo e($quantity); ?>">
        </label>

        <button type="button" class="btn btn-secondary btn-small remove-row">Remove</button>
    </div>
    <?php
}

/**
 * Stage 5: print the box for ONE student of the group (symptoms, medicines,
 * notes). $sIndex makes the field names unique:
 *     students[0][symptoms][], students[0][medicines][0][batch_id], students[1][...]
 * Medicine rows of student 0 are named students[0][medicines][..], so one
 * student's medicine can never end up on another student's record.
 *
 * $student is the student's row, or null when this is the hidden <template>
 * (the JavaScript fills in the name when a student is added).
 */
function output_group_student_card($sIndex, ?array $student, array $symptoms, array $batches,
                                   array $old, int $maxRows): void
{
    $oldSymptoms  = array_map('strval', $old['symptoms'] ?? []);
    $oldMedicines = array_slice($old['medicines'] ?? [], 0, $maxRows);

    if (empty($oldMedicines)) {
        $oldMedicines = [['batch_id' => '', 'quantity' => '']];
    }

    $name   = $student ? $student['last_name'] . ', ' . $student['first_name'] : '';
    $number = $student ? $student['student_number'] : '';
    $class  = $student ? $student['grade_year'] . '-' . $student['section'] : '';
    $id     = $student ? (int) $student['student_id'] : '';
    ?>
    <div class="group-student" data-student-id="<?php echo e($id); ?>">
        <div class="group-student-head">
            <h4>
                <span class="g-position">Student</span>:
                <span class="g-name"><?php echo e($name); ?></span>
                <span class="class-tag g-class"><?php echo e($class); ?></span>
                <br><small class="form-hint">Student No. <span class="g-number"><?php echo e($number); ?></span></small>
            </h4>
            <button type="button" class="btn btn-secondary btn-small group-remove">Remove from group</button>
        </div>

        <input type="hidden" class="g-id" name="students[<?php echo e($sIndex); ?>][student_id]"
               value="<?php echo e($id); ?>">

        <h5>Reported symptoms</h5>
        <div class="checkbox-grid">
            <?php foreach ($symptoms as $symptom): ?>
                <label>
                    <input type="checkbox" class="g-symptom"
                           name="students[<?php echo e($sIndex); ?>][symptoms][]"
                           value="<?php echo (int) $symptom['symptom_id']; ?>"
                           <?php echo in_array((string) $symptom['symptom_id'], $oldSymptoms, true) ? 'checked' : ''; ?>>
                    <?php echo e($symptom['symptom_name']); ?>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-group">
            <label for="g<?php echo e($sIndex); ?>_other">Others (optional)</label>
            <input type="text" id="g<?php echo e($sIndex); ?>_other" class="form-control" maxlength="255"
                   name="students[<?php echo e($sIndex); ?>][other_symptoms]"
                   placeholder="A symptom that is not in the list above"
                   value="<?php echo e($old['other_symptoms'] ?? ''); ?>">
        </div>

        <h5>Medicine dispensed to THIS student (optional)</h5>
        <div class="group-medicine-rows"
             data-next-index="<?php echo count($oldMedicines); ?>"
             data-max-rows="<?php echo (int) $maxRows; ?>">
            <?php foreach ($oldMedicines as $m => $row): ?>
                <?php output_medicine_row(
                    $m, $batches,
                    (string) ($row['batch_id'] ?? ''), (string) ($row['quantity'] ?? ''),
                    'students[' . $sIndex . '][medicines]'
                ); ?>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-small group-add-medicine">+ Add another medicine</button>

        <div class="form-group">
            <label for="g<?php echo e($sIndex); ?>_notes">Notes for this student (optional)</label>
            <input type="text" id="g<?php echo e($sIndex); ?>_notes" class="form-control" maxlength="1000"
                   name="students[<?php echo e($sIndex); ?>][notes]"
                   value="<?php echo e($old['notes'] ?? ''); ?>">
        </div>
    </div>
    <?php
}

$pageTitle  = "Log Clinic Visit";
$activePage = "visits";
require "includes/header.php";
?>

        <div class="card">
            <h3>Student Visit Information</h3>

            <!-- Stage 5: Individual / Group switch. Both forms stay on the page;
                 assets/group_log.js only shows one of them. -->
            <div class="mode-switch">
                <label>
                    <input type="checkbox" id="group_toggle" <?php echo $groupMode ? 'checked' : ''; ?>>
                    Group Log
                </label>
                <span class="form-hint">
                    Turn on to record <?php echo (int) GROUP_MIN_STUDENTS; ?> to <?php echo (int) GROUP_MAX_STUDENTS; ?>
                    students at once, for example after the same laboratory session.
                    Each student still gets their own visit record.
                </span>
            </div>

            <div id="individual_mode" <?php echo $groupMode ? 'hidden' : ''; ?>>

            <?php if (!empty($errors)): ?>
                <div class="form-errors" role="alert">
                    <strong>The visit was NOT saved. Please fix the following:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Field names below must match what save_visit.php reads. -->
            <form action="save_visit.php" method="POST" id="visit_form">
                <?php echo csrf_field(); ?>

                <!-- ============ 1. Student (searchable) ============ -->
                <div class="form-group">
                    <label for="student_search">Student</label>

                    <?php if (empty($students)): ?>
                        <p class="alert alert-warning">
                            There are no active students. Add or reactivate a student first
                            (see Student Records).
                        </p>
                    <?php else: ?>
                        <!-- This box has no name, so it is NOT sent to the server.
                             It only filters the list below (see assets/visit_form.js). -->
                        <input type="search" id="student_search" class="form-control"
                               placeholder="Type a name or student number to search..."
                               autocomplete="off">

                        <p class="form-hint" id="student_selected">No student selected yet.</p>

                        <div class="student-list" id="student_list">
                            <?php foreach ($students as $student): ?>
                                <?php
                                $label = $student['student_number'] . ' - ' .
                                         $student['last_name'] . ', ' . $student['first_name'] .
                                         ' (' . $student['grade_year'] . '-' . $student['section'] . ')';
                                // Lower-case text the search box looks inside.
                                $searchText = mb_strtolower(
                                    $student['student_number'] . ' ' . $student['first_name'] . ' ' .
                                    $student['last_name'] . ' ' . $student['grade_year'] . ' ' .
                                    $student['section']
                                );
                                ?>
                                <label class="student-option" data-search="<?php echo e($searchText); ?>">
                                    <input type="radio" name="student_id" required
                                           value="<?php echo (int) $student['student_id']; ?>"
                                           <?php echo (string) $student['student_id'] === $oldStudentId ? 'checked' : ''; ?>>
                                    <span><?php echo e($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <p class="form-hint" id="student_empty" hidden>No student matches your search.</p>
                    <?php endif; ?>
                </div>

                <!-- ============ 2. Symptoms ============ -->
                <div class="form-group">
                    <span class="form-label">Reported Symptoms</span>

                    <div class="checkbox-grid">
                        <?php foreach ($symptoms as $symptom): ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="symptoms[]"
                                    value="<?php echo (int) $symptom['symptom_id']; ?>"
                                    <?php echo in_array((string) $symptom['symptom_id'], $oldSymptomIds, true) ? 'checked' : ''; ?>
                                >
                                <?php echo e($symptom['symptom_name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="other_symptoms">Others (optional)</label>
                    <input type="text" id="other_symptoms" name="other_symptoms"
                           class="form-control" maxlength="255"
                           placeholder="A symptom that is not in the list above"
                           value="<?php echo e($oldOther); ?>">
                    <p class="form-hint">Saved with this visit only. It is not added to the symptom list.</p>
                </div>

                <!-- ============ 3. Medicines (one or more) ============ -->
                <div class="form-group">
                    <span class="form-label">Medicine Dispensed (optional)</span>

                    <?php if (empty($medicineBatches)): ?>
                        <p class="form-hint">
                            No medicine is available to dispense right now (every batch is out of stock,
                            expired, or retired).
                        </p>
                    <?php endif; ?>

                    <div id="medicine_rows"
                         data-next-index="<?php echo count($oldMedicines); ?>"
                         data-max-rows="<?php echo (int) $maxMedicineRows; ?>">
                        <?php foreach ($oldMedicines as $index => $row): ?>
                            <?php output_medicine_row(
                                $index,
                                $medicineBatches,
                                (string) ($row['batch_id'] ?? ''),
                                (string) ($row['quantity'] ?? '')
                            ); ?>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn btn-secondary btn-small" id="add_medicine">
                        + Add another medicine
                    </button>

                    <p class="form-hint">
                        Leave a row on "None" to skip it. If the same batch is chosen in two rows, the
                        quantities are added together and checked against the stock.
                    </p>

                    <!-- A hidden copy of one row. The JavaScript copies it for each new row. -->
                    <template id="medicine_row_template">
                        <?php output_medicine_row('__INDEX__', $medicineBatches, '', ''); ?>
                    </template>
                </div>

                <!-- ============ 4. Notes ============ -->
                <div class="form-group">
                    <label for="notes">Visit Notes</label>

                    <textarea
                        id="notes"
                        name="notes"
                        class="form-control"
                        rows="4"
                        maxlength="2000"
                        placeholder="Enter relevant visit notes..."
                    ><?php echo e($oldNotes); ?></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary" id="save_button">Save Clinic Visit</button>
                </div>

            </form>

            </div><!-- /individual_mode -->

            <!-- ====================================================== -->
            <!-- Stage 5: GROUP LOG (3 to 5 students, one transaction)   -->
            <!-- ====================================================== -->
            <div id="group_mode" <?php echo $groupMode ? '' : 'hidden'; ?>>

                <?php if (!empty($groupErrors)): ?>
                    <div class="form-errors" role="alert">
                        <strong>The group visit was NOT saved (nothing was saved for any student). Please fix the following:</strong>
                        <ul>
                            <?php foreach ($groupErrors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="save_group_visit.php" method="POST" id="group_form"
                      data-min="<?php echo (int) GROUP_MIN_STUDENTS; ?>"
                      data-max="<?php echo (int) GROUP_MAX_STUDENTS; ?>">
                    <?php echo csrf_field(); ?>

                    <!-- ---- 1. Choose the students ---- -->
                    <div class="form-group">
                        <label for="group_search">Students in this group</label>

                        <?php if (empty($students)): ?>
                            <p class="alert alert-warning">There are no active students.</p>
                        <?php else: ?>
                            <p class="group-counter" id="group_counter" aria-live="polite"></p>

                            <input type="search" id="group_search" class="form-control"
                                   placeholder="Type a name or student number, then press Add..."
                                   autocomplete="off">

                            <div class="student-list" id="group_student_list">
                                <?php foreach ($students as $student): ?>
                                    <?php
                                    $classText = $student['grade_year'] . '-' . $student['section'];
                                    $label = $student['student_number'] . ' - ' .
                                             $student['last_name'] . ', ' . $student['first_name'] .
                                             ' (' . $classText . ')';
                                    $searchText = mb_strtolower(
                                        $student['student_number'] . ' ' . $student['first_name'] . ' ' .
                                        $student['last_name'] . ' ' . $student['grade_year'] . ' ' .
                                        $student['section']
                                    );
                                    ?>
                                    <div class="group-option"
                                         data-search="<?php echo e($searchText); ?>"
                                         data-id="<?php echo (int) $student['student_id']; ?>"
                                         data-name="<?php echo e($student['last_name'] . ', ' . $student['first_name']); ?>"
                                         data-number="<?php echo e($student['student_number']); ?>"
                                         data-class="<?php echo e($classText); ?>">
                                        <span><?php echo e($label); ?></span>
                                        <button type="button" class="btn btn-secondary btn-small group-pick">Add</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <p class="form-hint" id="group_empty" hidden>No student matches your search.</p>
                            <p class="form-hint">
                                The same student cannot be added twice. Click "Remove from group" on a
                                student's box to take them out.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($students)): ?>

                    <!-- ---- 2. Optional shortcut ---- -->
                    <div class="form-group">
                        <span class="form-label">Common symptoms shortcut (optional)</span>
                        <div class="checkbox-grid">
                            <?php foreach ($symptoms as $symptom): ?>
                                <label>
                                    <!-- no "name": this box is NOT sent to the server -->
                                    <input type="checkbox" class="common-symptom"
                                           value="<?php echo (int) $symptom['symptom_id']; ?>">
                                    <?php echo e($symptom['symptom_name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p>
                            <button type="button" class="btn btn-secondary btn-small" id="apply_common">
                                Tick these for every student below
                            </button>
                        </p>
                        <p class="form-hint">
                            This only ticks the boxes to save time. You can still untick or add symptoms
                            for each student individually.
                        </p>
                    </div>

                    <!-- ---- 3. One box per selected student ---- -->
                    <div id="group_students" data-next-index="<?php echo count($groupValues['students'] ?? []); ?>">
                        <?php
                        $studentsById = [];
                        foreach ($students as $student) {
                            $studentsById[(int) $student['student_id']] = $student;
                        }

                        foreach (($groupValues['students'] ?? []) as $sIndex => $oldStudent) {
                            $oldId = (int) ($oldStudent['student_id'] ?? 0);

                            if (isset($studentsById[$oldId])) {
                                output_group_student_card(
                                    (int) $sIndex, $studentsById[$oldId], $symptoms,
                                    $medicineBatches, $oldStudent, $maxMedicineRows
                                );
                            }
                        }
                        ?>
                    </div>

                    <p class="form-hint">
                        Stock note: each student's medicine is saved on that student's own record. If
                        several students take the same batch, the quantities are added up and checked
                        against the stock together.
                    </p>

                    <!-- ---- 4. Note for everybody ---- -->
                    <div class="form-group">
                        <label for="group_note">Group note (optional)</label>
                        <input type="text" id="group_note" name="group_note" class="form-control"
                               maxlength="500"
                               placeholder="Example: After Chemistry laboratory session"
                               value="<?php echo e($groupValues['group_note'] ?? ''); ?>">
                        <p class="form-hint">Saved in the notes of every student's visit.</p>
                    </div>

                    <div class="form-group">
                        <p class="alert alert-danger" id="group_message" role="alert" hidden></p>
                        <button type="submit" class="btn btn-primary" id="group_save_button">Save Group Visit</button>
                    </div>

                    <!-- Hidden copies the JavaScript clones. __S__ = student number, __M__ = medicine row number. -->
                    <template id="group_student_template">
                        <?php output_group_student_card('__S__', null, $symptoms, $medicineBatches, [], $maxMedicineRows); ?>
                    </template>

                    <template id="group_medicine_template">
                        <?php output_medicine_row('__M__', $medicineBatches, '', '', 'students[__S__][medicines]'); ?>
                    </template>

                    <?php endif; ?>
                </form>
            </div><!-- /group_mode -->
        </div>

        <script src="assets/visit_form.js"></script>
        <script src="assets/group_log.js"></script>

<?php require "includes/footer.php"; ?>
