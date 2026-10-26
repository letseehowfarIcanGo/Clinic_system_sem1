<?php
/*
 * medicine_form.php
 * One page for BOTH "Add Medicine" and "Edit Medicine".
 *   medicine_form.php          -> add a new medicine
 *   medicine_form.php?id=5     -> edit medicine number 5
 *
 * The same file does two jobs:
 *   - GET request  (just opening the page): show the form
 *   - POST request (pressing Save): validate, save, then redirect
 */
require_once "config/db.php";
require_once "includes/functions.php";
require_migration_m3($pdo);

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$errors = [];

/* ------------------------------------------------------------------
   1. Which medicine are we editing? (none = adding a new one)
   ------------------------------------------------------------------ */
$medicineId = null;
$rawId = $isPost ? input_string($_POST, 'medicine_id') : input_string($_GET, 'id');

if ($rawId !== '') {
    $medicineId = parse_whole_number($rawId, 1, 2147483647);

    if ($medicineId === null) {
        flash_set('danger', 'Invalid medicine.');
        redirect('medicine_manage.php');
    }
}

$isEdit = ($medicineId !== null);

// Starting values for the form fields. 10 is the database's own
// default reorder level.
$form = [
    'medicine_name' => '',
    'description'   => '',
    'unit'          => '',
    'reorder_level' => '10',
];

if ($isEdit) {
    $stmt = $pdo->prepare(
        "SELECT medicine_name, description, unit, reorder_level
         FROM medicines
         WHERE medicine_id = ?"
    );
    $stmt->execute([$medicineId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        flash_set('danger', 'That medicine was not found.');
        redirect('medicine_manage.php');
    }

    $form = [
        'medicine_name' => $existing['medicine_name'],
        'description'   => (string) $existing['description'],
        'unit'          => $existing['unit'],
        'reorder_level' => (string) $existing['reorder_level'],
    ];
}

/* ------------------------------------------------------------------
   2. The Save button was pressed: validate, then save
   ------------------------------------------------------------------ */
if ($isPost) {
    csrf_check();   // reject forms that did not come from our own page

    // Replace the starting values with what the user typed.
    $form['medicine_name'] = input_string($_POST, 'medicine_name');
    $form['description']   = input_string($_POST, 'description');
    $form['unit']          = input_string($_POST, 'unit');
    $form['reorder_level'] = input_string($_POST, 'reorder_level');

    // --- Validation: every problem adds one message to $errors ---
    if ($form['medicine_name'] === '') {
        $errors[] = 'Medicine name is required.';
    } elseif (mb_strlen($form['medicine_name']) > 150) {
        $errors[] = 'Medicine name must be 150 characters or fewer.';
    }

    if ($form['unit'] === '') {
        $errors[] = 'Unit is required (for example: tablet, capsule, bottle).';
    } elseif (mb_strlen($form['unit']) > 30) {
        $errors[] = 'Unit must be 30 characters or fewer.';
    }

    if (mb_strlen($form['description']) > 1000) {
        $errors[] = 'Description must be 1000 characters or fewer.';
    }

    // Whole number, 0 or more. Negative numbers and decimals are rejected.
    $reorderLevel = parse_whole_number($form['reorder_level'], 0, 1000000);

    if ($reorderLevel === null) {
        $errors[] = 'Reorder level must be a whole number from 0 to 1,000,000 (no negative numbers or decimals).';
    }

    // Do not allow two medicines with the same name. When editing, the
    // medicine itself is excluded (medicine_id <> ?; 0 matches nothing).
    if (empty($errors)) {
        $stmt = $pdo->prepare(
            "SELECT medicine_id FROM medicines
             WHERE medicine_name = ? AND medicine_id <> ?"
        );
        $stmt->execute([$form['medicine_name'], $medicineId ?? 0]);

        if ($stmt->fetch()) {
            $errors[] = 'A medicine with this name already exists.';
        }
    }

    // --- Save (only if there were no problems) ---
    if (empty($errors)) {
        $description = $form['description'] !== '' ? $form['description'] : null;

        try {
            if ($isEdit) {
                $stmt = $pdo->prepare(
                    "UPDATE medicines
                     SET medicine_name = ?, description = ?, unit = ?, reorder_level = ?
                     WHERE medicine_id = ?"
                );
                $stmt->execute([
                    $form['medicine_name'], $description,
                    $form['unit'], $reorderLevel, $medicineId
                ]);

                flash_set('success', 'Medicine "' . $form['medicine_name'] . '" was updated.');
                redirect('medicine_manage.php');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO medicines (medicine_name, description, unit, reorder_level)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                $form['medicine_name'], $description,
                $form['unit'], $reorderLevel
            ]);

            // A new medicine has no stock until a batch is added,
            // so go straight to the "Add Batch" form for it.
            flash_set('success', 'Medicine "' . $form['medicine_name'] . '" was added. You can now record its first batch.');
            redirect('batch_form.php?medicine_id=' . (int) $pdo->lastInsertId());

        } catch (PDOException $ex) {
            // Log the technical detail; show the user a plain message.
            error_log($ex->getMessage());
            $errors[] = 'The medicine could not be saved. Please try again.';
        }
    }
}

$pageTitle  = $isEdit ? "Edit Medicine" : "Add Medicine";
$activePage = "medicines";
require "includes/header.php";
?>

        <div class="card">
            <h3><?php echo $isEdit ? 'Medicine Details' : 'New Medicine'; ?></h3>

            <?php if (!empty($errors)): ?>
                <ul class="form-errors">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="POST" action="medicine_form.php<?php echo $isEdit ? '?id=' . (int) $medicineId : ''; ?>">
                <?php echo csrf_field(); ?>
                <?php if ($isEdit): ?>
                    <input type="hidden" name="medicine_id" value="<?php echo (int) $medicineId; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="medicine_name">Medicine name</label>
                    <input type="text" id="medicine_name" name="medicine_name"
                           class="form-control" maxlength="150" required
                           value="<?php echo e($form['medicine_name']); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="unit">Unit</label>
                        <input type="text" id="unit" name="unit" class="form-control"
                               maxlength="30" required placeholder="tablet, capsule, bottle..."
                               value="<?php echo e($form['unit']); ?>">
                        <?php if ($isEdit): ?>
                            <p class="form-hint">Changing the unit changes how every batch of this medicine is labeled.</p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="reorder_level">Reorder level</label>
                        <input type="number" id="reorder_level" name="reorder_level"
                               class="form-control" min="0" max="1000000" step="1" required
                               value="<?php echo e($form['reorder_level']); ?>">
                        <p class="form-hint">A batch at or below this quantity is marked "Low Stock".</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description (optional)</label>
                    <textarea id="description" name="description" class="form-control"
                              rows="3" maxlength="1000"><?php echo e($form['description']); ?></textarea>
                </div>

                <div class="form-group actions">
                    <button type="submit" class="btn btn-primary">Save Medicine</button>
                    <a class="btn btn-secondary" href="medicine_manage.php">Cancel</a>
                </div>
            </form>
        </div>

<?php require "includes/footer.php"; ?>
