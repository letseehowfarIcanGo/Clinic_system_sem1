<?php
require_once "config/db.php";
require_once "includes/functions.php";
require_migration_m3($pdo);

/* Search and filter choices (same idea as medicines.php). */
$search      = input_string($_GET, 'q');
$stateFilter = input_string($_GET, 'state');

if (!in_array($stateFilter, ['active', 'retired', 'all'], true)) {
    $stateFilter = 'active';
}

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "m.medicine_name LIKE ?";
    $params[] = "%" . $search . "%";
}

if ($stateFilter === 'active') {
    $conditions[] = "m.is_active = 1";
} elseif ($stateFilter === 'retired') {
    $conditions[] = "m.is_active = 0";
}

// One row per medicine. COUNT(b.batch_id) counts its batches.
// usable_stock adds up only the batches that have NOT expired
// (CASE WHEN ... works like an if-statement inside SQL).
$sql = "SELECT
            m.medicine_id,
            m.medicine_name,
            m.unit,
            m.reorder_level,
            m.is_active,
            COUNT(b.batch_id) AS batch_count,
            COALESCE(SUM(CASE WHEN b.expiry_date >= CURDATE()
                              THEN b.quantity_available ELSE 0 END), 0) AS usable_stock
        FROM medicines m
        LEFT JOIN medicine_batches b
            ON m.medicine_id = b.medicine_id";

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " GROUP BY m.medicine_id, m.medicine_name, m.unit, m.reorder_level, m.is_active
          ORDER BY m.medicine_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$medicines = $stmt->fetchAll();

$filtersAreOn = ($search !== '' || $stateFilter !== 'active');

$pageTitle  = "Manage Medicines";
$activePage = "medicines";
$activeTab  = "manage";
require "includes/header.php";
require "includes/medicine_tabs.php";
?>

        <div class="toolbar">
            <a class="btn btn-primary" href="medicine_form.php">+ Add Medicine</a>
        </div>

        <div class="card">
            <h3>Medicines</h3>
            <p class="number"><?php echo count($medicines); ?></p>
        </div>

        <div class="card">
            <h3>Medicine List</h3>

            <form method="GET" action="medicine_manage.php" class="filter-bar">
                <div class="field grow">
                    <label for="q">Search medicine name</label>
                    <input type="text" id="q" name="q" class="form-control"
                           value="<?php echo e($search); ?>" placeholder="e.g. Paracetamol">
                </div>

                <div class="field">
                    <label for="state">State</label>
                    <select id="state" name="state" class="form-control">
                        <option value="active"  <?php echo $stateFilter === 'active'  ? 'selected' : ''; ?>>Active</option>
                        <option value="retired" <?php echo $stateFilter === 'retired' ? 'selected' : ''; ?>>Retired</option>
                        <option value="all"     <?php echo $stateFilter === 'all'     ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Search</button>
                <a class="btn btn-secondary" href="medicine_manage.php">Reset</a>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Unit</th>
                            <th>Reorder Level</th>
                            <th>Batches</th>
                            <th>Usable Stock</th>
                            <th>State</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($medicines)): ?>
                            <tr>
                                <td colspan="7" class="empty-row">
                                    <?php echo $filtersAreOn
                                        ? 'No medicines match your search or filter.'
                                        : 'No medicines recorded yet.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($medicines as $medicine): ?>
                                <?php $isActive = (int) $medicine['is_active'] === 1; ?>
                                <tr>
                                    <td><?php echo e($medicine['medicine_name']); ?></td>
                                    <td><?php echo e($medicine['unit']); ?></td>
                                    <td><?php echo (int) $medicine['reorder_level']; ?></td>
                                    <td><?php echo (int) $medicine['batch_count']; ?></td>
                                    <td><?php echo (int) $medicine['usable_stock']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $isActive ? 'badge-success' : 'badge-neutral'; ?>">
                                            <?php echo $isActive ? 'Active' : 'Retired'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <a class="btn btn-secondary btn-small"
                                               href="medicine_form.php?id=<?php echo (int) $medicine['medicine_id']; ?>">Edit</a>

                                            <?php if ($isActive): ?>
                                                <a class="btn btn-secondary btn-small"
                                                   href="batch_form.php?medicine_id=<?php echo (int) $medicine['medicine_id']; ?>">Add batch</a>

                                                <form method="POST" action="medicine_action.php"
                                                      data-confirm="<?php echo e(
                                                          'Retire ' . $medicine['medicine_name'] . '? It will be hidden from the active ' .
                                                          'inventory and can no longer be dispensed. You can reactivate it later.'
                                                      ); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="retire">
                                                    <input type="hidden" name="id" value="<?php echo (int) $medicine['medicine_id']; ?>">
                                                    <input type="hidden" name="return" value="medicine_manage.php">
                                                    <button type="submit" class="btn btn-secondary btn-small">Retire</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" action="medicine_action.php">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <input type="hidden" name="id" value="<?php echo (int) $medicine['medicine_id']; ?>">
                                                    <input type="hidden" name="return" value="medicine_manage.php">
                                                    <button type="submit" class="btn btn-secondary btn-small">Reactivate</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ((int) $medicine['batch_count'] === 0): ?>
                                                <!-- A medicine with no batches has no stock history, so it may be deleted. -->
                                                <form method="POST" action="medicine_action.php"
                                                      data-confirm="<?php echo e(
                                                          'Delete ' . $medicine['medicine_name'] . ' permanently? This cannot be undone.'
                                                      ); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_medicine">
                                                    <input type="hidden" name="id" value="<?php echo (int) $medicine['medicine_id']; ?>">
                                                    <input type="hidden" name="return" value="medicine_manage.php">
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
                A medicine that has batches cannot be deleted, because its stock and dispensing
                history must be kept. Retire it instead. "Usable Stock" counts only batches that
                have not expired.
            </p>
        </div>

<?php require "includes/footer.php"; ?>
