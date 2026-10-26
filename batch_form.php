<?php
/*
 * batch_form.php
 * One page for BOTH "Add Batch" and "Edit Batch".
 *   batch_form.php                  -> add a batch (choose the medicine)
 *   batch_form.php?medicine_id=3    -> add a batch for medicine 3
 *   batch_form.php?id=12            -> edit batch number 12
 *
 * A "batch" is one delivery of a medicine, with its own batch number,
 * quantity and expiry date.
 */
require_once "config/db.php";
require_once "includes/functions.php";
require_migration_m3($pdo);

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$errors = [];

// Today's date from the database clock (same clock the rest of the system uses).
list($today) = status_dates($pdo);

/* ------------------------------------------------------------------
   1. Which batch are we editing? (none = adding a new one)
   ------------------------------------------------------------------ */
$batchId = null;
$rawId = $isPost ? input_string($_POST, 'batch_id') : input_string($_GET, 'id');

if ($rawId !== '') {
    $batchId = parse_whole_number($rawId, 1, 2147483647);

    if ($batchId === null) {
        flash_set('danger', 'Invalid batch.');
        redirect('medicines.php');
    }
}

$isEdit = ($batchId !== null);

$form = [
    'medicine_id'        => input_string($_GET, 'medicine_id'),   // pre-selects the medicine
    'batch_number'       => '',
    'quantity_available' => '',
    'expiry_date'        => '',
    'date_received'      => $today,    // handy default; the user can change or clear it
    'original_quantity'  => '',
];

$medicine = null;   // the medicine this batch belongs to (when editing)

if ($isEdit) {
    $stmt = $pdo->prepare(
        "SELECT b.medicine_id, b.batch_number, b.quantity_available,
                b.expiry_date, b.date_received,
                m.medicine_name, m.unit
         FROM medicine_batches b
         INNER JOIN medicines m ON b.medicine_id = m.medicine_id
         WHERE b.batch_id = ?"
    );
    $stmt->execute([$batchId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        flash_set('danger', 'That batch was not found.');
        redirect('medicines.php');
    }

    $medicine = $existing;

    $form = [
        'medicine_id'        => (string) $existing['medicine_id'],
        'batch_number'       => $existing['batch_number'],
        'quantity_available' => (string) $existing['quantity_available'],
        'expiry_date'        => $existing['expiry_date'],
        'date_received'      => (string) $existing['date_received'],
        // The quantity as it was when this form was opened. Used on save
        // to detect that someone else changed the stock in the meantime.
        'original_quantity'  => (string) $existing['quantity_available'],
    ];
}

// Active medicines for the drop-down on the Add form.
$stmt = $pdo->query(
    "SELECT medicine_id, medicine_name, unit
     FROM medicines
     WHERE is_active = 1
     ORDER BY medicine_name ASC"
);
$activeMedicines = $stmt->fetchAll();

/* ------------------------------------------------------------------
   2. The Save button was pressed: validate, then save
   ------------------------------------------------------------------ */
if ($isPost) {
    csrf_check();

    $form['batch_number']       = input_string($_POST, 'batch_number');
    $form['quantity_available'] = input_string($_POST, 'quantity_available');
    $form['expiry_date']        = input_string($_POST, 'expiry_date');
    $form['date_received']      = input_string($_POST, 'date_received');

    if (!$isEdit) {
        $form['medicine_id'] = input_string($_POST, 'medicine_id');
    } else {
        $form['original_quantity'] = input_string($_POST, 'original_quantity');
    }

    // --- Which medicine? (Add form only; an existing batch keeps its medicine) ---
    $medicineId = $isEdit ? (int) $existing['medicine_id'] : parse_whole_number($form['medicine_id'], 1, 2147483647);

    if (!$isEdit) {
        if ($medicineId === null) {
            $errors[] = 'Please choose a medicine.';
        } else {
            // The medicine must exist AND still be active.
            $stmt = $pdo->prepare(
                "SELECT medicine_name FROM medicines
                 WHERE medicine_id = ? AND is_active = 1"
            );
            $stmt->execute([$medicineId]);

            if (!$stmt->fetch()) {
                $errors[] = 'The chosen medicine does not exist or has been retired.';
                $medicineId = null;
            }
        }
    }

    // --- Batch number ---
    if ($form['batch_number'] === '') {
        $errors[] = 'Batch number is required.';
    } elseif (mb_strlen($form['batch_number']) > 50) {
        $errors[] = 'Batch number must be 50 characters or fewer.';
    }

    // --- Quantity: whole number, 0 or more ---
    $quantity = parse_whole_number($form['quantity_available'], 0, 1000000);

    if ($quantity === null) {
        $errors[] = 'Quantity must be a whole number from 0 to 1,000,000 (no negative numbers or decimals).';
    }

    // --- Expiry date ---
    if (!is_valid_date($form['expiry_date'])) {
        $errors[] = 'Please enter a valid expiry date.';
    } elseif (!$isEdit && $form['expiry_date'] < $today) {
        // When RECEIVING new stock, an already-expired date is almost surely
        // a typing mistake. (When editing, any valid date is allowed so old
        // batches can still be corrected.)
        $errors[] = 'A new batch cannot already be expired. Check the expiry date.';
    }

    // --- Date received (optional) ---
    $dateReceived = null;

    if ($form['date_received'] !== '') {
        if (!is_valid_date($form['date_received'])) {
            $errors[] = 'Please enter a valid date received, or leave it empty.';
        } elseif ($form['date_received'] > $today) {
            $errors[] = 'Date received cannot be in the future.';
        } else {
            $dateReceived = $form['date_received'];
        }
    }

    // --- Batch number must be unique within the same medicine ---
    if (empty($errors) && $medicineId !== null) {
        $stmt = $pdo->prepare(
            "SELECT batch_id FROM medicine_batches
             WHERE medicine_id = ? AND batch_number = ? AND batch_id <> ?"
        );
        $stmt->execute([$medicineId, $form['batch_number'], $batchId ?? 0]);

        if ($stmt->fetch()) {
            $errors[] = 'This medicine already has a batch with that batch number.';
        }
    }

    // The hidden "quantity when the form was opened" must be a valid number.
    $originalQuantity = null;

    if ($isEdit) {
        $originalQuantity = parse_whole_number($form['original_quantity'], 0, 2147483647);

        if ($originalQuantity === null) {
            $errors[] = 'The form data was incomplete. Please reload the page and try again.';
        }
    }

    /* --------------------------------------------------------------
       3. Save (only if there were no problems)
       -------------------------------------------------------------- */
    if (empty($errors)) {
        try {
            if ($isEdit) {
                // Edit inside a TRANSACTION. FOR UPDATE locks this batch row so
                // nobody can dispense from it while we compare and save.
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT quantity_available FROM medicine_batches
                     WHERE batch_id = ?
                     FOR UPDATE"
                );
                $stmt->execute([$batchId]);
                $currentQuantity = (int) $stmt->fetchColumn();

                if ($currentQuantity !== $originalQuantity) {
                    // The stock changed while this form was open (for example a
                    // visit dispensed some). Saving would overwrite that change.
                    $pdo->rollBack();

                    $form['quantity_available'] = (string) $currentQuantity;
                    $form['original_quantity']  = (string) $currentQuantity;
                    $errors[] = 'The stock quantity changed while you were editing (it is now '
                              . $currentQuantity . '). The quantity box was refreshed. '
                              . 'Please check it and save again.';
                } else {
                    $stmt = $pdo->prepare(
                        "UPDATE medicine_batches
                         SET batch_number = ?, quantity_available = ?,
                             expiry_date = ?, date_received = ?
                         WHERE batch_id = ?"
                    );
                    $stmt->execute([
                        $form['batch_number'], $quantity,
                        $form['expiry_date'], $dateReceived, $batchId
                    ]);

                    $pdo->commit();

                    flash_set('success', 'Batch "' . $form['batch_number'] . '" was updated.');
                    redirect('medicines.php');
                }
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO medicine_batches
                        (medicine_id, batch_number, quantity_available, expiry_date, date_received)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $medicineId, $form['batch_number'], $quantity,
                    $form['expiry_date'], $dateReceived
                ]);

                flash_set('success', 'Batch "' . $form['batch_number'] . '" was added.');
                redirect('medicines.php');
            }

        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($ex->getMessage());
            $errors[] = 'The batch could not be saved. Please try again.';
        }
    }
}

$pageTitle  = $isEdit ? "Edit Batch" : "Add Batch";
$activePage = "medicines";
require "includes/header.php";
?>

        <div class="card">
            <h3><?php echo $isEdit ? 'Batch Details' : 'New Batch'; ?></h3>

            <?php if (!empty($errors)): ?>
                <ul class="form-errors">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="POST" action="batch_form.php<?php echo $isEdit ? '?id=' . (int) $batchId : ''; ?>">
                <?php echo csrf_field(); ?>

                <?php if ($isEdit): ?>
                    <input type="hidden" name="batch_id" value="<?php echo (int) $batchId; ?>">
                    <input type="hidden" name="original_quantity" value="<?php echo e($form['original_quantity']); ?>">

                    <div class="form-group">
                        <span class="form-label">Medicine</span>
                        <p><?php echo e($medicine['medicine_name']); ?> (<?php echo e($medicine['unit']); ?>)</p>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label for="medicine_id">Medicine</label>
                        <select id="medicine_id" name="medicine_id" class="form-control" required>
                            <option value="">-- Select a medicine --</option>
                            <?php foreach ($activeMedicines as $option): ?>
                                <option value="<?php echo (int) $option['medicine_id']; ?>"
                                    <?php echo (string) $option['medicine_id'] === $form['medicine_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($option['medicine_name'] . ' (' . $option['unit'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Only active medicines are listed. Need a new one? <a href="medicine_form.php">Add a medicine first</a>.</p>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="batch_number">Batch number</label>
                        <input type="text" id="batch_number" name="batch_number"
                               class="form-control" maxlength="50" required
                               value="<?php echo e($form['batch_number']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="quantity_available">Quantity available</label>
                        <input type="number" id="quantity_available" name="quantity_available"
                               class="form-control" min="0" max="1000000" step="1" required
                               value="<?php echo e($form['quantity_available']); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="expiry_date">Expiry date</label>
                        <input type="date" id="expiry_date" name="expiry_date"
                               class="form-control" required
                               <?php echo !$isEdit ? 'min="' . e($today) . '"' : ''; ?>
                               value="<?php echo e($form['expiry_date']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="date_received">Date received (optional)</label>
                        <input type="date" id="date_received" name="date_received"
                               class="form-control" max="<?php echo e($today); ?>"
                               value="<?php echo e($form['date_received']); ?>">
                    </div>
                </div>

                <?php if ($isEdit): ?>
                    <p class="alert alert-warning">
                        Changing the quantity here corrects the stock count directly. It does
                        <strong>not</strong> create a dispensing record. Use it to fix a counting
                        mistake or add stock you actually received.
                    </p>
                <?php endif; ?>

                <div class="form-group actions">
                    <button type="submit" class="btn btn-primary">Save Batch</button>
                    <a class="btn btn-secondary" href="medicines.php">Cancel</a>
                </div>
            </form>
        </div>

<?php require "includes/footer.php"; ?>
