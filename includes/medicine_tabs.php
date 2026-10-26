<?php
/*
 * includes/medicine_tabs.php
 * Small tab bar shown at the top of the two medicine pages.
 * Before including it, set  $activeTab = 'inventory'  or  'manage'.
 */
$activeTab = $activeTab ?? 'inventory';
?>
        <nav class="tabs" aria-label="Medicine sections">
            <a href="medicines.php"
               class="<?php echo $activeTab === 'inventory' ? 'active' : ''; ?>">Batch Inventory</a>
            <a href="medicine_manage.php"
               class="<?php echo $activeTab === 'manage' ? 'active' : ''; ?>">Manage Medicines</a>
        </nav>
