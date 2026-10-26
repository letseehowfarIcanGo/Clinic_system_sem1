<?php
require_once "config/db.php";
require_once "includes/functions.php";

// Get each visit, its student, selected symptoms, and custom symptoms.
$sql = "SELECT
            v.visit_id,
            v.visit_date,
            v.notes,
            v.other_symptoms,
            s.student_number,
            s.first_name,
            s.last_name,
            s.grade_year,
            s.section,
            GROUP_CONCAT(
                DISTINCT sy.symptom_name
                ORDER BY sy.symptom_name
                SEPARATOR ', '
            ) AS symptoms
        FROM visits v
        INNER JOIN students s
            ON v.student_id = s.student_id
        LEFT JOIN visit_symptoms vs
            ON v.visit_id = vs.visit_id
        LEFT JOIN symptoms sy
            ON vs.symptom_id = sy.symptom_id
        GROUP BY
            v.visit_id,
            v.visit_date,
            v.notes,
            v.other_symptoms,
            s.student_number,
            s.first_name,
            s.last_name,
            s.grade_year,
            s.section
        ORDER BY v.visit_date DESC";

$stmt = $pdo->query($sql);
$visits = $stmt->fetchAll();

// Get the medicines dispensed for each visit.
$medicineSql = "SELECT
                    md.visit_id,
                    m.medicine_name,
                    mb.batch_number,
                    md.quantity_dispensed,
                    m.unit
                FROM medication_dispensed md
                INNER JOIN medicine_batches mb
                    ON md.batch_id = mb.batch_id
                INNER JOIN medicines m
                    ON mb.medicine_id = m.medicine_id
                ORDER BY md.dispensing_id ASC";

$medicineStmt = $pdo->query($medicineSql);
$medicineRows = $medicineStmt->fetchAll();

// Group medicine records by visit ID.
$medicinesByVisit = [];

foreach ($medicineRows as $medicine) {
    $visitId = $medicine['visit_id'];
    $medicinesByVisit[$visitId][] = $medicine;
}

$pageTitle  = "Visit History";
$activePage = "history";
require "includes/header.php";
?>

<div class="card">
    <h3>Total Recorded Visits</h3>
    <p class="number"><?php echo count($visits); ?></p>
</div>

<div class="card">
    <h3>Clinic Visit Records</h3>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Program / Year &amp; Section</th>
                    <th>Symptoms</th>
                    <th>Medicines Dispensed</th>
                    <th>Visit Notes</th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($visits)): ?>
                    <tr>
                        <td colspan="6" class="empty-row">
                            No clinic visits recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($visits as $visit): ?>
                        <tr>
                            <td><?php echo e($visit['visit_date']); ?></td>

                            <td>
                                <?php
                                echo e(
                                    $visit['student_number'] . ' - ' .
                                    $visit['first_name'] . ' ' .
                                    $visit['last_name']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo e(
                                    $visit['grade_year'] . '-' .
                                    $visit['section']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                $symptoms = trim($visit['symptoms'] ?? '');
                                $otherSymptoms = trim($visit['other_symptoms'] ?? '');

                                if ($symptoms !== '') {
                                    echo e($symptoms);
                                }

                                if ($otherSymptoms !== '') {
                                    if ($symptoms !== '') {
                                        echo '<br>';
                                    }

                                    echo '<strong>Others:</strong> ' .
                                         e($otherSymptoms);
                                }

                                if ($symptoms === '' && $otherSymptoms === '') {
                                    echo 'None recorded';
                                }
                                ?>
                            </td>

                            <td>
                                <?php
                                $visitId = $visit['visit_id'];
                                $dispensed = $medicinesByVisit[$visitId] ?? [];

                                if (empty($dispensed)) {
                                    echo 'None recorded';
                                } else {
                                    foreach ($dispensed as $medicine) {
                                        echo '<div>';
                                        echo e($medicine['medicine_name']);
                                        echo ' — Qty: ';
                                        echo e($medicine['quantity_dispensed']);
                                        echo ' ';
                                        echo e($medicine['unit']);
                                        echo '<br><small>Batch: ';
                                        echo e($medicine['batch_number']);
                                        echo '</small>';
                                        echo '</div>';
                                    }
                                }
                                ?>
                            </td>

                            <td><?php echo e($visit['notes'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require "includes/footer.php"; ?>