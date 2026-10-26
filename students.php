<?php
require_once "config/db.php";
require_once "includes/functions.php";

/* ------------------------------------------------------------------
   1. Read the search and filter choices from the URL
   ------------------------------------------------------------------ */
$search        = input_string($_GET, 'q');
$yearFilter    = input_string($_GET, 'grade_year');
$sectionFilter = input_string($_GET, 'section');
$statusFilter  = input_string($_GET, 'status');

// The class drop-downs are filled from the labels that already exist in
// the students table, so no fixed list of years or sections is forced.
$gradeYears = $pdo->query(
    "SELECT DISTINCT grade_year FROM students ORDER BY grade_year ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$sections = $pdo->query(
    "SELECT DISTINCT section FROM students ORDER BY section ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// Ignore a filter value that is not one of the real choices.
if (!in_array($yearFilter, $gradeYears, true)) {
    $yearFilter = '';
}

if (!in_array($sectionFilter, $sections, true)) {
    $sectionFilter = '';
}

// Default is "all students", exactly like the original page.
if (!in_array($statusFilter, ['active', 'inactive'], true)) {
    $statusFilter = '';
}

/* ------------------------------------------------------------------
   2. Build the query. Conditions are added only for the filters that
   are in use, and every typed value is a "?" placeholder (prepared
   statement), never pasted into the SQL text.
   ------------------------------------------------------------------ */
$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(s.student_number LIKE ?
                      OR s.first_name LIKE ?
                      OR s.last_name LIKE ?
                      OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
    $like = "%" . $search . "%";
    array_push($params, $like, $like, $like, $like);
}

if ($yearFilter !== '') {
    $conditions[] = "s.grade_year = ?";
    $params[] = $yearFilter;
}

if ($sectionFilter !== '') {
    $conditions[] = "s.section = ?";
    $params[] = $sectionFilter;
}

if ($statusFilter === 'active') {
    $conditions[] = "s.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $conditions[] = "s.is_active = 0";
}

// visit_count (a sub-query) tells us how many visits each student has.
// A student with visits can never be deleted.
$sql = "SELECT
            s.student_id,
            s.student_number,
            s.first_name,
            s.last_name,
            s.grade_year,
            s.section,
            s.is_active,
            (SELECT COUNT(*)
               FROM visits v
              WHERE v.student_id = s.student_id) AS visit_count
        FROM students s";

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY s.last_name ASC, s.first_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Totals for the summary cards (always for ALL students, not filtered).
$totals = $pdo->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count
     FROM students"
)->fetch();

$totalStudents  = (int) $totals['total'];
$activeStudents = (int) $totals['active_count'];

/* ------------------------------------------------------------------
   3. Where to return to after Deactivate / Delete
   The current filters are rebuilt from the CLEANED values above, so
   the user lands back on the same filtered list.
   ------------------------------------------------------------------ */
$activeFilters = array_filter([
    'q'          => $search,
    'grade_year' => $yearFilter,
    'section'    => $sectionFilter,
    'status'     => $statusFilter,
], function ($value) {
    return $value !== '';
});

$returnUrl = 'students.php' . (empty($activeFilters) ? '' : '?' . http_build_query($activeFilters));

$filtersAreOn = !empty($activeFilters);

$pageTitle  = "Student Records";
$activePage = "students";
require "includes/header.php";
?>

        <div class="toolbar">
            <a class="btn btn-primary" href="student_form.php">+ Add Student</a>
        </div>

        <section class="dashboard-cards">
            <div class="card">
                <h3>Total Students</h3>
                <p class="number"><?php echo $totalStudents; ?></p>
            </div>

            <div class="card">
                <h3>Active</h3>
                <p class="number"><?php echo $activeStudents; ?></p>
            </div>

            <div class="card">
                <h3>Inactive</h3>
                <p class="number"><?php echo $totalStudents - $activeStudents; ?></p>
            </div>
        </section>

        <div class="card">
            <h3>Registered Students</h3>

            <!-- Search and filters: a GET form, so the choices stay in the URL -->
            <form method="GET" action="students.php" class="filter-bar">
                <div class="field grow">
                    <label for="q">Search name or student number</label>
                    <input type="text" id="q" name="q" class="form-control"
                           value="<?php echo e($search); ?>" placeholder="e.g. Reyes or 2024-0001">
                </div>

                <div class="field">
                    <label for="grade_year">Program / Year</label>
                    <select id="grade_year" name="grade_year" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($gradeYears as $value): ?>
                            <option value="<?php echo e($value); ?>"
                                <?php echo $yearFilter === $value ? 'selected' : ''; ?>>
                                <?php echo e($value); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="section">Section</label>
                    <select id="section" name="section" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($sections as $value): ?>
                            <option value="<?php echo e($value); ?>"
                                <?php echo $sectionFilter === $value ? 'selected' : ''; ?>>
                                <?php echo e($value); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All</option>
                        <option value="active"   <?php echo $statusFilter === 'active'   ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Search</button>
                <a class="btn btn-secondary" href="students.php">Reset</a>
            </form>

            <?php if ($filtersAreOn): ?>
                <p class="form-hint">
                    Showing <?php echo count($students); ?> of <?php echo $totalStudents; ?> students.
                </p>
            <?php endif; ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student Number</th>
                            <th>Full Name</th>
                            <th>Program / Year &amp; Section</th>
                            <th>Visits</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="6" class="empty-row">
                                    <?php echo $filtersAreOn
                                        ? 'No students match your search or filters.'
                                        : 'No students registered yet.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <?php
                                $isActive  = (int) $student['is_active'] === 1;
                                $fullName  = $student['first_name'] . ' ' . $student['last_name'];
                                $visitCount = (int) $student['visit_count'];
                                ?>
                                <tr>
                                    <td><?php echo e($student['student_number']); ?></td>
                                    <td><?php echo e($fullName); ?></td>
                                    <td><?php echo e($student['grade_year'] . '-' . $student['section']); ?></td>
                                    <td><?php echo $visitCount; ?></td>
                                    <td>
                                        <span class="badge <?php echo $isActive ? 'badge-success' : 'badge-neutral'; ?>">
                                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a class="btn btn-secondary btn-small"
                                               href="student_form.php?id=<?php echo (int) $student['student_id']; ?>">Edit</a>

                                            <?php if ($isActive): ?>
                                                <form method="POST" action="student_action.php"
                                                      data-confirm="<?php echo e(
                                                          'Deactivate ' . $fullName . '? This student will no longer appear in the ' .
                                                          'Log Clinic Visit list. Past visits are kept. You can reactivate later.'
                                                      ); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <input type="hidden" name="id" value="<?php echo (int) $student['student_id']; ?>">
                                                    <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">
                                                    <button type="submit" class="btn btn-secondary btn-small">Deactivate</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="student_action.php">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <input type="hidden" name="id" value="<?php echo (int) $student['student_id']; ?>">
                                                    <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">
                                                    <button type="submit" class="btn btn-secondary btn-small">Reactivate</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($visitCount === 0): ?>
                                                <!-- Only a student with NO visits can be deleted. -->
                                                <form method="POST" action="student_action.php"
                                                      data-confirm="<?php echo e(
                                                          'Delete ' . $fullName . ' (' . $student['student_number'] .
                                                          ') permanently? This cannot be undone.'
                                                      ); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $student['student_id']; ?>">
                                                    <input type="hidden" name="return" value="<?php echo e($returnUrl); ?>">
                                                    <button type="submit" class="btn btn-danger btn-small">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="help-text">
                A student with clinic visits cannot be deleted, because the health history must be
                kept. Deactivate the student instead: they stop appearing in the Log Clinic Visit
                list, and all past visits stay in Visit History.
            </p>
        </div>

<?php require "includes/footer.php"; ?>
