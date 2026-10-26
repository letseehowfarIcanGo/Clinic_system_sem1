/*
 * assets/app.js
 * Small script used by every page (loaded in includes/footer.php).
 *
 * "Are you sure?" prompts: any <form> that has a data-confirm="..."
 * attribute asks the user first. If they press Cancel, the form is not
 * sent. The server ALSO re-checks every rule, so this is a convenience,
 * not the only protection.
 */
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
