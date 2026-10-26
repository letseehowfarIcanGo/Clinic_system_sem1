/*
 * assets/group_log.js   (NEW in Stage 5)
 * JavaScript for the "Group Log" part of the Log Clinic Visit page.
 * It only makes the form easier to use:
 *   1. The Group Log switch shows the group form or the normal form.
 *   2. Search the student list and add students (3 to 5) to the group.
 *   3. Each added student gets their own box (symptoms, medicines, notes).
 *   4. The "common symptoms" shortcut ticks boxes for every student.
 *
 * It does NOT save anything. The form is sent to save_group_visit.php,
 * which checks everything again on the server and saves all the visits in
 * ONE database transaction (all students or none).
 */
(function () {
    'use strict';

    var toggle = document.getElementById('group_toggle');
    var individualMode = document.getElementById('individual_mode');
    var groupMode = document.getElementById('group_mode');
    var form = document.getElementById('group_form');

    if (!toggle || !individualMode || !groupMode) {
        return;
    }

    /* --------------------------------------------------------------
       1. Individual <-> Group switch
       -------------------------------------------------------------- */
    function showMode() {
        groupMode.hidden = !toggle.checked;
        individualMode.hidden = toggle.checked;
    }

    toggle.addEventListener('change', showMode);
    showMode();

    // No students on file: the group form has no controls to wire up.
    if (!form || !document.getElementById('group_students')) {
        return;
    }

    var minStudents = parseInt(form.getAttribute('data-min'), 10);
    var maxStudents = parseInt(form.getAttribute('data-max'), 10);

    var searchBox = document.getElementById('group_search');
    var options = document.querySelectorAll('.group-option');
    var emptyMessage = document.getElementById('group_empty');
    var studentsBox = document.getElementById('group_students');
    var counter = document.getElementById('group_counter');
    var message = document.getElementById('group_message');
    var saveButton = document.getElementById('group_save_button');
    var studentTemplate = document.getElementById('group_student_template');
    var medicineTemplate = document.getElementById('group_medicine_template');
    var nextIndex = parseInt(studentsBox.getAttribute('data-next-index'), 10);

    function cards() {
        return studentsBox.querySelectorAll('.group-student');
    }

    /* --------------------------------------------------------------
       2. Search the student list
       -------------------------------------------------------------- */
    function filterStudents() {
        var words = searchBox.value.toLowerCase().split(/\s+/).filter(Boolean);
        var shown = 0;

        options.forEach(function (option) {
            var text = option.getAttribute('data-search');
            var matches = words.every(function (word) {
                return text.indexOf(word) !== -1;
            });

            option.hidden = !matches;
            if (matches) {
                shown++;
            }
        });

        emptyMessage.hidden = shown !== 0;
    }

    searchBox.addEventListener('input', filterStudents);

    // Pressing Enter in the search box must not submit the form.
    searchBox.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
        }
    });

    /* --------------------------------------------------------------
       3. Counter, numbering and Add buttons
       -------------------------------------------------------------- */
    function refresh() {
        var list = cards();
        var count = list.length;
        var chosen = {};

        list.forEach(function (card, position) {
            card.querySelector('.g-position').textContent = 'Student ' + (position + 1);
            chosen[card.getAttribute('data-student-id')] = true;
        });

        options.forEach(function (option) {
            var added = chosen[option.getAttribute('data-id')] === true;
            var button = option.querySelector('.group-pick');

            option.classList.toggle('is-added', added);
            button.disabled = added || count >= maxStudents;
            button.textContent = added ? 'Added' : 'Add';
        });

        counter.textContent = count + ' of ' + maxStudents + ' students selected (minimum ' + minStudents + ')';
        counter.classList.toggle('bad', count < minStudents);
    }

    function showMessage(text) {
        message.textContent = text;
        message.hidden = (text === '');
    }

    /* --------------------------------------------------------------
       4. Medicine rows inside one student's box
       -------------------------------------------------------------- */
    function updateRow(row) {
        var select = row.querySelector('.batch-select');
        var quantity = row.querySelector('.qty-input');
        var option = select.options[select.selectedIndex];
        var hint = row.querySelector('.row-hint');

        if (!hint) {
            hint = document.createElement('span');
            hint.className = 'row-hint';
            row.querySelector('.row-field').appendChild(hint);
        }

        if (option && option.value !== '') {
            var available = option.getAttribute('data-available');
            quantity.max = available;
            hint.textContent = 'In stock: ' + available + ' ' + option.getAttribute('data-unit');
        } else {
            quantity.removeAttribute('max');
            hint.textContent = '';
        }
    }

    function wireMedicineRow(card, row) {
        row.querySelector('.batch-select').addEventListener('change', function () {
            updateRow(row);
        });

        row.querySelector('.remove-row').addEventListener('click', function () {
            row.remove();
            refreshAddMedicine(card);
        });

        updateRow(row);
    }

    function refreshAddMedicine(card) {
        var rows = card.querySelector('.group-medicine-rows');
        var max = parseInt(rows.getAttribute('data-max-rows'), 10);

        card.querySelector('.group-add-medicine').disabled =
            rows.querySelectorAll('.medicine-row').length >= max;
    }

    /* --------------------------------------------------------------
       5. One student's box: wire its buttons
       -------------------------------------------------------------- */
    function wireCard(card) {
        var sIndex = card.querySelector('.g-id').name.match(/^students\[([^\]]+)\]/)[1];
        var rows = card.querySelector('.group-medicine-rows');

        rows.querySelectorAll('.medicine-row').forEach(function (row) {
            wireMedicineRow(card, row);
        });

        card.querySelector('.group-add-medicine').addEventListener('click', function () {
            // The template row's names contain __S__ (student) and __M__ (row).
            var mIndex = parseInt(rows.getAttribute('data-next-index'), 10);
            var html = medicineTemplate.innerHTML
                .replace(/__S__/g, sIndex)
                .replace(/__M__/g, mIndex);

            rows.setAttribute('data-next-index', mIndex + 1);
            rows.insertAdjacentHTML('beforeend', html);
            wireMedicineRow(card, rows.lastElementChild);
            refreshAddMedicine(card);
        });

        card.querySelector('.group-remove').addEventListener('click', function () {
            card.remove();
            showMessage('');
            refresh();
        });

        refreshAddMedicine(card);
    }

    /* --------------------------------------------------------------
       6. Add a student to the group
       -------------------------------------------------------------- */
    function addStudent(option) {
        var id = option.getAttribute('data-id');

        if (cards().length >= maxStudents) {
            showMessage('A group can have at most ' + maxStudents + ' students.');
            return;
        }

        // The same student cannot be in the group twice.
        var duplicate = false;
        cards().forEach(function (card) {
            if (card.getAttribute('data-student-id') === id) {
                duplicate = true;
            }
        });

        if (duplicate) {
            showMessage('That student is already in the group.');
            return;
        }

        showMessage('');

        var html = studentTemplate.innerHTML.replace(/__S__/g, nextIndex);
        nextIndex++;

        studentsBox.insertAdjacentHTML('beforeend', html);

        var card = studentsBox.lastElementChild;
        card.setAttribute('data-student-id', id);
        card.querySelector('.g-id').value = id;
        card.querySelector('.g-name').textContent = option.getAttribute('data-name');
        card.querySelector('.g-class').textContent = option.getAttribute('data-class');
        card.querySelector('.g-number').textContent = option.getAttribute('data-number');

        wireCard(card);
        refresh();

        // Scroll the new box into view so the nurse sees it.
        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    options.forEach(function (option) {
        option.querySelector('.group-pick').addEventListener('click', function () {
            addStudent(option);
        });
    });

    /* --------------------------------------------------------------
       7. Common symptoms shortcut (only TICKS boxes, never forces)
       -------------------------------------------------------------- */
    document.getElementById('apply_common').addEventListener('click', function () {
        var ticked = [];

        document.querySelectorAll('.common-symptom:checked').forEach(function (box) {
            ticked.push(box.value);
        });

        if (ticked.length === 0) {
            showMessage('Tick at least one common symptom first.');
            return;
        }

        showMessage('');

        cards().forEach(function (card) {
            card.querySelectorAll('.g-symptom').forEach(function (box) {
                if (ticked.indexOf(box.value) !== -1) {
                    box.checked = true;
                }
            });
        });
    });

    /* --------------------------------------------------------------
       8. Before sending: check the group size, prevent double-click
       -------------------------------------------------------------- */
    cards().forEach(wireCard);   // boxes restored after a failed save
    refresh();

    form.addEventListener('submit', function (event) {
        var count = cards().length;

        if (count < minStudents || count > maxStudents) {
            event.preventDefault();
            showMessage('A group must have ' + minStudents + ' to ' + maxStudents +
                        ' students. You have ' + count + '.');
            return;
        }

        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
    });

    // Coming back with the browser's Back button: re-enable the button.
    window.addEventListener('pageshow', function () {
        saveButton.disabled = false;
        saveButton.textContent = 'Save Group Visit';
    });
})();
