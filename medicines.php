<?php
require_once "config/db.php";
require_once "includes/functions.php";
require_migration_m3($pdo);

/* ------------------------------------------------------------------
   1. Read the search and filter choices from the URL (?q=...&status=...)
   Anything unexpected is replaced with a safe default.
   ------------------------------------------------------------------ */
$search       = input_string($_GET, 'q');
$statusFilter = input_string($_GET, 'status');
$stateFilter  = input_string($_GET, 'state');

if (!in_array($statusFilter, stock_status_names(), true)) {
    $statusFilter = '';                 // '' = show every status
}

if (!in_array($stateFilter, ['active', 'retired', 'all'], true)) {
    $stateFilter = 'active';            // default: hide retired medicines
}

/* ------------------------------------------------------------------
   2. Build the query. The WHERE conditions are added one by one, and
   every user-typed value goes in as a "?" placeholder (prepared
   statement), never pasted into the SQL text. That is what prevents
   SQL injection.
   ------------------------------------------------------------------ */
$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(m.medicine_name LIKE ? OR b.batch_number LIKE ?)";
    $params[] = "%" . $search . "%";
    $params[] = "%" . $search . "%";
}

if ($stateFilter === 'active') {
    $conditions[] = "m.is_active = 1";
} elseif ($stateFilter === 'retired') {
    $conditions[] = "m.is_active = 0";
}

// LEFT JOIN (not INNER JOIN) so a medicine with no batch yet still
// appears. times_dispensed tells us if a batch has history, which
// decides whether it may be deleted.
$sql = "SELECT
            m.medicine_id,
            m.medicine_name,
            m.unit,
            m.reorder_level,
            m.is_active,
            b.batch_id,
            b.batch_number,
            b.quantity_available,
            b.expiry_date,
            (SELECT COUNT(*)
               FROM medication_dispensed d
              WHERE d.batch_id = b.batch_id) AS times_dispensed
        FROM medicines m
        LEFT JOIN medicine_batches b
            ON m.medicine_id = b.medicine_id";

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY m.medicine_name ASC, b.expiry_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* ------------------------------------------------------------------
   3. Work out each row's status (same rules as always), then apply
   the status filter.
   ------------------------------------------------------------------ */
list($today, $warningDate) = status_dates($pdo);

$batches = [];

foreach ($rows as $row) {
    $row['status'] = batch_status($row, $today, $warningDate);

    if ($statusFilter === '' || $row['status'] === $statusFilter) {
        $batches[] = $row;
    }
}

$filtersAreOn = ($search !== '' || $statusFilter !== '' || $stateFilter !== 'active');

$pageTitle  = "Medicine Inventory";
$activePage = "medicines";
$activeTab  = "inventory";
require "includes/header.php";
require "includes/medicine_tabs.php";
?>

        <div class="toolbar">
            <a class="btn btn-primary" href="medicine_form.php">+ Add Medicine</a>
            <a class="btn btn-secondary" href="batch_form.php">+ Add Batch</a>
        </div>

        <div class="card">
            <h3>Medicine Batches</h3>
            <p class="number"><?php echo count($batches); ?></p>
            <?php if ($filtersAreOn): ?>
                <p class="form-hint">Showing filtered results.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Inventory Records</h3>

            <!-- Search and filters: a GET form, so the choices stay in the URL -->
            <form method="GET" action="medicines.php" class="filter-bar">
                <div class="field grow">
                    <label for="q">Search medicine or batch</label>
                    <input type="text" id="q" name="q" class="form-control"
                           value="<?php echo e($search); ?>" placeholder="e.g. Paracetamol">
                </div>

                <div class="field">
                    <label for="status">Stock status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All statuses</option>
                        <?php foreach (stock_status_names() as $name): ?>
                            <option value="<?php echo e($name); ?>"
                                <?php echo $statusFilter === $name ? 'selected' : ''; ?>>
                                <?php echo e($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="state">Medicine state</label>
                    <select id="state" name="state" class="form-control">
                        <option value="active"  <?php echo $stateFilter === 'active'  ? 'selected' : ''; ?>>Active</option>
                        <option value="retired" <?php echo $stateFilter === 'retired' ? 'selected' : ''; ?>>Retired</option>
                        <option value="all"     <?php echo $stateFilter === 'all'     ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Search</button>
                <a class="btn btn-secondary" href="medicines.php">Reset</a>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Batch Number</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Expiry Date</th>
                            <th>Stock Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="7" class="empty-row">
                                    <?php echo $filtersAreOn
                                        ? 'No batches match your search or filters.'
                                        : 'No medicine batches recorded yet.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batches as $row): ?>
                                <?php $hasBatch = $row['batch_id'] !== null; ?>
                                <tr>
                                    <td>
                                        <?php echo e($row['medicine_name']); ?>
                                        <?php if ((int) $row['is_active'] === 0): ?>
                                            <span class="badge badge-neutral">Retired</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $hasBatch ? e($row['batch_number']) : '&mdash;'; ?></td>
                                    <td><?php echo $hasBatch ? (int) $row['quantity_available'] : '&mdash;'; ?></td>
                                    <td><?php echo e($row['unit']); ?></td>
                                    <td><?php echo $hasBatch ? e($row['expiry_date']) : '&mdash;'; ?></td>
                                    <td>
                                        <span class="badge <?php echo e(status_badge_class($row['status'])); ?>">
                                            <?php echo e($row['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if ($hasBatch): ?>
                                                <a class="btn btn-secondary btn-small"
                                                   href="batch_form.php?id=<?php echo (int) $row['batch_id']; ?>">Edit</a>

                                                <?php if ((int) $row['times_dispensed'] === 0): ?>
                                                    <!-- Only batches that were never dispensed can be deleted. -->
                                                    <form method="POST" action="medicine_action.php"
                                                          data-confirm="<?php echo e(
                                                              'Delete batch ' . $row['batch_number'] . ' of ' .
                                                              $row['medicine_name'] . ' (' . (int) $row['quantity_available'] .
                                                              ' ' . $row['unit'] . ')? This cannot be undone.'
                                                          ); ?>">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete_batch">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['batch_id']; ?>">
                                                        <input type="hidden" name="return" value="medicines.php">
                                                        <button type="submit" class="btn btn-danger btn-small">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php elseif ((int) $row['is_active'] === 1): ?>
                                                <a class="btn btn-secondary btn-small"
                                                   href="batch_form.php?medicine_id=<?php echo (int) $row['medicine_id']; ?>">Add batch</a>
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
                Status rules: Expired, then Expiring Soon (within 90 days), then Out of Stock,
                then Low Stock (at or below the medicine's reorder level). The first rule that
                matches is shown.
            </p>
        </div>

<?php require "includes/footer.php"; ?>
