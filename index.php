<?php
require_once "config/db.php";
require_once "includes/functions.php";
require_migration_m3($pdo);

/* ------------------------------------------------------------------
   1. Summary numbers
   ------------------------------------------------------------------ */
$totalStudents = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

// Only medicines that are still in use. Retired ones are counted apart.
$totalMedicines   = $pdo->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn();
$retiredMedicines = $pdo->query("SELECT COUNT(*) FROM medicines WHERE is_active = 0")->fetchColumn();

$totalVisits = $pdo->query("SELECT COUNT(*) FROM visits")->fetchColumn();

// "Today" = from midnight today up to (not including) midnight tomorrow.
// Written as a range instead of DATE(visit_date) = CURDATE() so MySQL
// can use the index on visit_date.
$visitsToday = $pdo->query(
    "SELECT COUNT(*) FROM visits
     WHERE visit_date >= CURDATE()
       AND visit_date <  CURDATE() + INTERVAL 1 DAY"
)->fetchColumn();

// Same "last 7 days" rule that the Health Surveillance page uses.
$visitsLast7Days = $pdo->query(
    "SELECT COUNT(*) FROM visits
     WHERE visit_date >= NOW() - INTERVAL 7 DAY"
)->fetchColumn();

/* ------------------------------------------------------------------
   2. Stock alerts
   Get every batch of an ACTIVE medicine, work out each batch's status
   with the shared batch_status() function, then keep the ones that
   need attention.
   ------------------------------------------------------------------ */
$stmt = $pdo->query(
    "SELECT
        m.medicine_name,
        m.unit,
        m.reorder_level,
        b.batch_number,
        b.quantity_available,
        b.expiry_date
     FROM medicines m
     INNER JOIN medicine_batches b
        ON m.medicine_id = b.medicine_id
     WHERE m.is_active = 1
     ORDER BY m.medicine_name ASC, b.expiry_date ASC"
);
$batches = $stmt->fetchAll();

list($today, $warningDate) = status_dates($pdo);

// How many batches have each alert status?
$alertCounts = [
    'Expired'       => 0,
    'Out of Stock'  => 0,
    'Expiring Soon' => 0,
    'Low Stock'     => 0,
];

$alertBatches = [];

foreach ($batches as $batch) {
    $status = batch_status($batch, $today, $warningDate);

    if (isset($alertCounts[$status])) {
        $alertCounts[$status]++;
        $batch['status'] = $status;
        $alertBatches[] = $batch;
    }
}

// Most urgent first. The array position of a status is its priority
// (position 0 = most urgent). The list above is already in that order.
$priority = array_flip(array_keys($alertCounts));

usort($alertBatches, function ($a, $b) use ($priority) {
    return $priority[$a['status']] <=> $priority[$b['status']];
});

$maxAlertsShown = 10;
$totalAlerts = count($alertBatches);
$alertBatches = array_slice($alertBatches, 0, $maxAlertsShown);

/* ------------------------------------------------------------------
   2b. Health trend warnings (Stage 5)
   The SAME function the Health Surveillance page uses
   (includes/functions.php), so both pages always show the same rule:
   3 or more different students of one class, same symptom, last 7 days.
   ------------------------------------------------------------------ */
$trendWarnings = get_trend_warnings($pdo);

/* ------------------------------------------------------------------
   3. Recent visits (latest 10)
   ------------------------------------------------------------------ */
$stmt = $pdo->query(
    "SELECT
        v.visit_id,
        v.visit_date,
        s.student_number,
        s.first_name,
        s.last_name,
        s.grade_year,
        s.section,
        GROUP_CONCAT(
            sy.symptom_name
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
        s.student_number,
        s.first_name,
        s.last_name,
        s.grade_year,
        s.section
     ORDER BY v.visit_date DESC, v.visit_id DESC
     LIMIT 10"
);
$recentVisits = $stmt->fetchAll();

$pageTitle  = "Dashboard";
$activePage = "dashboard";
require "includes/header.php";
?>

        <section class="dashboard-cards">

            <div class="card">
                <h3>Total Students</h3>
                <p class="number"><?php echo e($totalStudents); ?></p>
            </div>

            <div class="card">
                <h3>Medicine Types (Active)</h3>
                <p class="number"><?php echo e($totalMedicines); ?></p>
                <?php if ((int) $retiredMedicines > 0): ?>
                    <p class="form-hint"><?php echo e($retiredMedicines); ?> retired</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Clinic Visits (All Time)</h3>
                <p class="number"><?php echo e($totalVisits); ?></p>
            </div>

            <div class="card">
                <h3>Visits Today</h3>
                <p class="number"><?php echo e($visitsToday); ?></p>
            </div>

            <div class="card">
                <h3>Visits, Last 7 Days</h3>
                <p class="number"><?php echo e($visitsLast7Days); ?></p>
            </div>

        </section>

        <div class="card">
            <h3>Health Trend Warning</h3>

            <?php if (empty($trendWarnings)): ?>
                <p class="help-text">
                    No current warnings. No symptom has been reported by
                    <?php echo (int) TREND_MIN_STUDENTS; ?> or more different students of the same class
                    in the last <?php echo (int) TREND_WINDOW_DAYS; ?> days.
                </p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Class / Section</th>
                                <th>Symptom</th>
                                <th>Students Reporting</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trendWarnings as $warning): ?>
                                <tr>
                                    <td><?php echo e($warning['grade_year'] . '-' . $warning['section']); ?></td>
                                    <td><?php echo e($warning['symptom_name']); ?></td>
                                    <td><?php echo (int) $warning['affected_students']; ?></td>
                                    <td><span class="badge badge-warning">Review required</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="help-text">
                    These warnings show a pattern in recorded reports from the last
                    <?php echo (int) TREND_WINDOW_DAYS; ?> days. They are not a diagnosis or a confirmed outbreak.
                </p>
            <?php endif; ?>

            <p class="help-text"><a href="surveillance.php">Open Health Surveillance</a></p>
        </div>

        <div class="card">
            <h3>Stock Alerts</h3>

            <!-- Each box links to the inventory page, already filtered. -->
            <div class="alert-counts">
                <?php foreach ($alertCounts as $statusName => $count): ?>
                    <a class="alert-count <?php echo e(status_badge_class($statusName)); ?>"
                       href="medicines.php?status=<?php echo e(urlencode($statusName)); ?>">
                        <span class="count"><?php echo (int) $count; ?></span>
                        <?php echo e($statusName); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($totalAlerts === 0): ?>
                <p class="help-text">No stock alerts. All active batches are in normal condition.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Batch</th>
                                <th>Quantity</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alertBatches as $batch): ?>
                                <tr>
                                    <td><?php echo e($batch['medicine_name']); ?></td>
                                    <td><?php echo e($batch['batch_number']); ?></td>
                                    <td><?php echo (int) $batch['quantity_available'] . ' ' . e($batch['unit']); ?></td>
                                    <td><?php echo e($batch['expiry_date']); ?></td>
                                    <td>
                                        <span class="badge <?php echo e(status_badge_class($batch['status'])); ?>">
                                            <?php echo e($batch['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="help-text">
                    <?php if ($totalAlerts > $maxAlertsShown): ?>
                        Showing the <?php echo (int) $maxAlertsShown; ?> most urgent of
                        <?php echo (int) $totalAlerts; ?> alerts.
                    <?php endif; ?>
                    <a href="medicines.php">Open the full Medicine Inventory</a>
                </p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Recent Visits</h3>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Program / Year &amp; Section</th>
                            <th>Symptoms</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentVisits)): ?>
                            <tr>
                                <td colspan="4" class="empty-row">No clinic visits recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentVisits as $visit): ?>
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
                                    <td><?php echo e($visit['grade_year'] . '-' . $visit['section']); ?></td>
                                    <td><?php echo e($visit['symptoms'] ?? 'None recorded'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="help-text"><a href="history.php">Open the full Visit History</a></p>
        </div>

<?php require "includes/footer.php"; ?>
