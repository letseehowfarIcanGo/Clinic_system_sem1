<?php
/*
 * student_form.php
 * One page for BOTH "Add Student" and "Edit Student".
 *   student_form.php          -> add a new student
 *   student_form.php?id=7     -> edit student number 7
 *
 * GET request  (opening the page)  : show the form
 * POST request (pressing Save)     : validate, save, then redirect
 *
 * (Deactivating a student is done from the student list, not here.)
 */
require_once "config/db.php";
require_once "includes/functions.php";

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
$errors = [];

/* ------------------------------------------------------------------
   1. Which student are we editing? (none = adding a new one)
   ------------------------------------------------------------------ */
$studentId = null;
$rawId = $isPost ? input_string($_POST, 'student_id') : input_string($_GET, 'id');

if ($rawId !== '') {
    $studentId = parse_whole_number($rawId, 1, 2147483647);

    if ($studentId === null) {
        flash_set('danger', 'Invalid student.');
        redirect('students.php');
    }
}

$isEdit = ($studentId !== null);

$form = [
    'student_number' => '',
    'first_name'     => '',
    'last_name'      => '',
    'grade_year'     => '',
    'section'        => '',
];

if ($isEdit) {
    $stmt = $pdo->prepare(
        "SELECT student_number, first_name, last_name, grade_year, section
         FROM students
         WHERE student_id = ?"
    );
    $stmt->execute([$studentId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        flash_set('danger', 'That student was not found.');
        redirect('students.php');
    }

    $form = $existing;
}

/* ------------------------------------------------------------------
   2. The Save button was pressed: validate, then save
   ------------------------------------------------------------------ */
if ($isPost) {
    csrf_check();   // reject forms that did not come from our own page

    foreach (array_keys($form) as $field) {
        $form[$field] = input_string($_POST, $field);
    }

    // Each field: [label, maximum length]. The limits match the
    // column sizes in the database (varchar sizes).
    $rules = [
        'student_number' => ['Student number', 30],
        'first_name'     => ['First name', 100],
        'last_name'      => ['Last name', 100],
        'grade_year'     => ['Program / Year', 30],
        'section'        => ['Section', 100],
    ];

    foreach ($rules as $field => $rule) {
        list($label, $maxLength) = $rule;

        if ($form[$field] === '') {
            $errors[] = $label . ' is required.';
        } elseif (mb_strlen($form[$field]) > $maxLength) {
            $errors[] = $label . ' must be ' . $maxLength . ' characters or fewer.';
        }
    }

    // Student numbers must be unique. When editing, the student
    // themselves is excluded (student_id <> ?; 0 matches nobody).
    if (empty($errors)) {
        $stmt = $pdo->prepare(
            "SELECT first_name, last_name
             FROM students
             WHERE student_number = ? AND student_id <> ?"
        );
        $stmt->execute([$form['student_number'], $studentId ?? 0]);
        $owner = $stmt->fetch();

        if ($owner) {
            $errors[] = 'Student number "' . $form['student_number'] . '" is already used by '
                      . $owner['first_name'] . ' ' . $owner['last_name'] . '.';
        }
    }

    // --- Save (only if there were no problems) ---
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $stmt = $pdo->prepare(
                    "UPDATE students
                     SET student_number = ?, first_name = ?, last_name = ?,
                         grade_year = ?, section = ?
                     WHERE student_id = ?"
                );
                $stmt->execute([
                    $form['student_number'], $form['first_name'], $form['last_name'],
                    $form['grade_year'], $form['section'], $studentId
                ]);

                flash_set('success', 'Student ' . $form['first_name'] . ' ' . $form['last_name'] . ' was updated.');
            } else {
                // is_active is not listed: the database default (1 = active) is used.
                $stmt = $pdo->prepare(
                    "INSERT INTO students
                        (student_number, first_name, last_name, grade_year, section)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $form['student_number'], $form['first_name'], $form['last_name'],
                    $form['grade_year'], $form['section']
                ]);

                flash_set('success', 'Student ' . $form['first_name'] . ' ' . $form['last_name'] . ' was added.');
            }

            redirect('students.php');

        } catch (PDOException $ex) {
            // SQLSTATE 23000 = a database rule was broken. Here it means two
            // people saved the same student number at the same moment (the
            // UNIQUE index on student_number caught it).
            if ($ex->getCode() === '23000') {
                $errors[] = 'Student number "' . $form['student_number'] . '" is already in use.';
            } else {
                error_log($ex->getMessage());
                $errors[] = 'The student could not be saved. Please try again.';
            }
        }
    }
}

/* ------------------------------------------------------------------
   3. Suggestions for the Program/Year and Section boxes.
   They come from the labels already in the database. They are only
   suggestions: any text may still be typed.
   ------------------------------------------------------------------ */
$gradeYearOptions = $pdo->query(
    "SELECT DISTINCT grade_year FROM students ORDER BY grade_year ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$sectionOptions = $pdo->query(
    "SELECT DISTINCT section FROM students ORDER BY section ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$pageTitle  = $isEdit ? "Edit Student" : "Add Student";
$activePage = "students";
require "includes/header.php";
?>

        <div class="card">
            <h3><?php echo $isEdit ? 'Student Details' : 'New Student'; ?></h3>

            <?php if (!empty($errors)): ?>
                <ul class="form-errors">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="POST" action="student_form.php<?php echo $isEdit ? '?id=' . (int) $studentId : ''; ?>">
                <?php echo csrf_field(); ?>
                <?php if ($isEdit): ?>
                    <input type="hidden" name="student_id" value="<?php echo (int) $studentId; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="student_number">Student number</label>
                    <input type="text" id="student_number" name="student_number"
                           class="form-control" maxlength="30" required
                           value="<?php echo e($form['student_number']); ?>">
                    <?php if ($isEdit): ?>
                        <p class="form-hint">Visits are linked to the student record, not the number, so correcting a typo here is safe.</p>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First name</label>
                        <input type="text" id="first_name" name="first_name"
                               class="form-control" maxlength="100" required
                               value="<?php echo e($form['first_name']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last name</label>
                        <input type="text" id="last_name" name="last_name"
                               class="form-control" maxlength="100" required
                               value="<?php echo e($form['last_name']); ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="grade_year">Program / Year</label>
                        <!-- list="..." connects this box to the <datalist> of suggestions below -->
                        <input type="text" id="grade_year" name="grade_year"
                               class="form-control" maxlength="30" required
                               list="grade_year_options" autocomplete="off"
                               value="<?php echo e($form['grade_year']); ?>">
                        <datalist id="grade_year_options">
                            <?php foreach ($gradeYearOptions as $option): ?>
                                <option value="<?php echo e($option); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="form-group">
                        <label for="section">Section</label>
                        <input type="text" id="section" name="section"
                               class="form-control" maxlength="100" required
                               list="section_options" autocomplete="off"
                               value="<?php echo e($form['section']); ?>">
                        <datalist id="section_options">
                            <?php foreach ($sectionOptions as $option): ?>
                                <option value="<?php echo e($option); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <p class="form-hint">
                    Pick a suggested Program/Year and Section so classes are spelled the same way
                    every time. Surveillance groups students by these exact labels.
                </p>

                <div class="form-group actions">
                    <button type="submit" class="btn btn-primary">Save Student</button>
                    <a class="btn btn-secondary" href="students.php">Cancel</a>
                </div>
            </form>
        </div>

<?php require "includes/footer.php"; ?>
