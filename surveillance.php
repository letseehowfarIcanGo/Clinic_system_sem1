<?php
require_once "config/db.php";
require_once "includes/functions.php";

// Count reports for each symptom.
$sql = "SELECT
            sy.symptom_name,
            COUNT(DISTINCT v.visit_id) AS total_reports
        FROM symptoms sy
        LEFT JOIN visit_symptoms vs
            ON sy.symptom_id = vs.symptom_id
        LEFT JOIN visits v
            ON vs.visit_id = v.visit_id
        GROUP BY sy.symptom_id, sy.symptom_name
        ORDER BY total_reports DESC, sy.symptom_name ASC";

$stmt = $pdo->query($sql);
$symptomReports = $stmt->fetchAll();

// Symptoms reported by at least 3 distinct students of the same class
// within the last 7 days. The rule lives in ONE place,
// get_trend_warnings() in includes/functions.php, and the Dashboard
// uses the same function, so the two pages always agree.
$trendWarnings = get_trend_warnings($pdo);

$pageTitle  = "Health Surveillance";
$activePage = "surveillance";
require "includes/header.php";
?>

        <div class="card">
            <h3>Tracked Symptoms</h3>
            <p class="number"><?php echo count($symptomReports); ?></p>
        </div>

        <div class="card">
            <h3>Health Trend Warnings</h3>

            <?php if (empty($trendWarnings)): ?>
                <p class="help-text">
                    No symptom clusters currently meet the warning threshold
                    of <?php echo (int) TREND_MIN_STUDENTS; ?> distinct students within
                    <?php echo (int) TREND_WINDOW_DAYS; ?> days.
                </p>
            <?php else: ?>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Symptom</th>
                                <th>Students Reported</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($trendWarnings as $warning): ?>
                                <tr>
                                    <td>
                                        <?php
                                        echo e(
                                            $warning['grade_year'] . '-' .
                                            $warning['section']
                                        );
                                        ?>
                                    </td>
                                    <td><?php echo e($warning['symptom_name']); ?></td>
                                    <td><?php echo (int) $warning['affected_students']; ?></td>
                                    <td>
                                        <span class="badge badge-warning">Review required</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p class="help-text">
                    These warnings indicate patterns in recorded reports,
                    not a confirmed outbreak or medical diagnosis.
                </p>

            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Reported Symptom Summary</h3>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Symptom</th>
                            <th>Number of Reports</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($symptomReports as $report): ?>
                            <tr>
                                <td><?php echo e($report['symptom_name']); ?></td>
                                <td><?php echo (int) $report['total_reports']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

<?php require "includes/footer.php"; ?>
