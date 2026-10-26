<?php
/*
 * includes/header.php
 * ----------------------------------------------------------------
 * The top part of every page: <head>, the sidebar menu, and the
 * page title. It stops right after the title, so the page that
 * included it can print its own content next.
 *
 * Before including this file, a page must set two variables:
 *     $pageTitle  - the heading text, e.g. "Student Records"
 *     $activePage - which menu link to highlight, e.g. "students"
 *
 * The matching bottom part is includes/footer.php.
 */

// Safe defaults in case a page forgets to set them.
$pageTitle  = $pageTitle  ?? 'School Clinic';
$activePage = $activePage ?? '';

// The menu is written ONCE here. To add or rename a menu link later,
// change this list and every page updates automatically.
// Format:  'key' => ['file name', 'text shown in the menu']
$navLinks = [
    'dashboard'    => ['index.php',        'Dashboard'],
    'students'     => ['students.php',     'Student Records'],
    'medicines'    => ['medicines.php',    'Medicine Inventory'],
    'visits'       => ['visits.php',       'Log Clinic Visit'],
    'history'      => ['history.php',      'Visit History'],
    'surveillance' => ['surveillance.php', 'Health Surveillance'],
];

// Stage 5: who is logged in? (the login check itself already ran in
// includes/auth.php; this is only used to decorate the menu)
$currentUser = auth_user($pdo);

// The Super Admin also sees the user-management link. Hiding the link is
// only for convenience: users.php checks the role again on the server.
if ($currentUser !== null && $currentUser['role'] === 'super_admin') {
    $navLinks['users'] = ['users.php', 'User Accounts'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> | School Clinic</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<a class="skip-link" href="#main">Skip to main content</a>

<div class="container">

    <!-- Sidebar menu -->
    <aside class="sidebar">
        <h2>School Clinic</h2>
        <p>Inventory &amp; Health Surveillance</p>

        <nav class="sidebar-nav" aria-label="Main menu">
            <?php foreach ($navLinks as $key => $link): ?>
                <a href="<?php echo e($link[0]); ?>"
                   class="<?php echo $key === $activePage ? 'active' : ''; ?>"
                   <?php echo $key === $activePage ? 'aria-current="page"' : ''; ?>>
                    <?php echo e($link[1]); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($currentUser !== null): ?>
            <!-- Stage 5: who is logged in, plus password and logout -->
            <div class="user-box">
                <strong><?php echo e($currentUser['full_name']); ?></strong>
                <span><?php echo e(role_labels()[$currentUser['role']] ?? $currentUser['role']); ?></span>
                <a href="account.php" class="<?php echo $activePage === 'account' ? 'active' : ''; ?>">Change Password</a>
                <form method="POST" action="logout.php">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="logout-button">Log Out</button>
                </form>
            </div>
        <?php endif; ?>
    </aside>

    <!-- Main content (closed in footer.php) -->
    <main class="main-content" id="main">

        <header class="page-header">
            <h1><?php echo e($pageTitle); ?></h1>
            <p>PHINMA Araullo University - South Campus</p>
        </header>

        <?php flash_render(); // Show any success/error message saved before a redirect. ?>
