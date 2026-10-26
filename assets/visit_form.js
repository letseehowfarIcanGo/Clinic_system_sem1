/*
 * assets/visit_form.js
 * JavaScript for the Log Clinic Visit form. It does two jobs:
 *   1. Student search: hide students that do not match what was typed.
 *   2. Medicine rows: add / remove rows, and show how much stock a
 *      chosen batch has.
 *
 * Remember: this only makes the form easier to use. save_visit.php
 * checks everything again on the server, because JavaScript in the
 * browser can be bypassed.
 */
(function () {
    'use strict';

    /* --------------------------------------------------------------
       1. Student search
       -------------------------------------------------------------- */
    var searchBox = document.getElementById('student_search');
    var studentOptions = document.querySelectorAll('.student-option');
    var emptyMessage = document.getElementById('student_empty');
    var selectedMessage = document.getElementById('student_selected');

    function filterStudents() {
        // "reyes ana" matches "Ana Reyes": every typed word must appear.
        var words = searchBox.value.toLowerCase().split(/\s+/).filter(Boolean);
        var shown = 0;

        studentOptions.forEach(function (option) {
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

    function showSelectedStudent() {
        var chosen = document.querySelector('.student-option input:checked');

        if (chosen) {
            selectedMessage.textContent = 'Selected: ' + chosen.nextElementSibling.textContent;
        } else {
            selectedMessage.textContent = 'No student selected yet.';
        }
    }

    if (searchBox) {
        searchBox.addEventListener('input', filterStudents);

        // Pressing Enter in the search box must not submit the whole form.
        searchBox.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });

        studentOptions.forEach(function (option) {
            option.querySelector('input').addEventListener('change', showSelectedStudent);
        });

        showSelectedStudent();   // for a student restored after an error
    }

    /* --------------------------------------------------------------
       2. Medicine rows
       -------------------------------------------------------------- */
    var rowsContainer = document.getElementById('medicine_rows');
    var addButton = document.getElementById('add_medicine');
    var template = document.getElementById('medicine_row_template');
    var maxRows = parseInt(rowsContainer.getAttribute('data-max-rows'), 10);
    var nextIndex = parseInt(rowsContainer.getAttribute('data-next-index'), 10);

    // When a batch is chosen, limit the quantity box and show the stock.
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

    function wireRow(row) {
        row.querySelector('.batch-select').addEventListener('change', function () {
            updateRow(row);
        });

        row.querySelector('.remove-row').addEventListener('click', function () {
            row.remove();
            refreshAddButton();
        });

        updateRow(row);
    }

    function refreshAddButton() {
        var count = rowsContainer.querySelectorAll('.medicine-row').length;
        addButton.disabled = count >= maxRows;
    }

    rowsContainer.querySelectorAll('.medicine-row').forEach(wireRow);

    addButton.addEventListener('click', function () {
        // The template holds one row; __INDEX__ becomes a new number
        // so the field names stay unique (medicines[3][batch_id] ...).
        var html = template.innerHTML.replace(/__INDEX__/g, nextIndex);
        nextIndex++;

        rowsContainer.insertAdjacentHTML('beforeend', html);
        wireRow(rowsContainer.lastElementChild);
        refreshAddButton();
    });

    refreshAddButton();

    /* --------------------------------------------------------------
       3. Prevent double-clicking "Save" from saving the visit twice
       -------------------------------------------------------------- */
    var form = document.getElementById('visit_form');
    var saveButton = document.getElementById('save_button');

    form.addEventListener('submit', function () {
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';
    });

    // If the user comes back with the browser's Back button, re-enable it.
    window.addEventListener('pageshow', function () {
        saveButton.disabled = false;
        saveButton.textContent = 'Save Clinic Visit';
    });
})();
