<?php
/*
 * logout.php   (NEW in Stage 5)
 * Logs the user out. It accepts POST only (the "Log Out" button in the
 * sidebar), with a CSRF token, so another website cannot log someone out
 * just by showing an image or link.
 */
define('PUBLIC_PAGE', true);

require_once "config/db.php";
require_once "includes/functions.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();
logout_user();   // empties the session, deletes the cookie, destroys it

redirect('login.php?loggedout=1');
