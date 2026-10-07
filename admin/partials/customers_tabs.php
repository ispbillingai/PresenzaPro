<?php
/** Tabs of the admin Customers page. Expects $tab ('visits' | 'loyalty'). */
if (!isset($tab)) { http_response_code(404); return; }
?>
<div class="room-tabs" style="margin-bottom:16px;">
    <a href="/admin/customers.php" class="room-tab <?= $tab === 'visits' ? 'active' : '' ?>" style="text-decoration:none;"><i class="fas fa-calendar-day"></i> <?= te('cust_tab_visits') ?></a>
    <a href="/admin/customers.php?view=loyalty" class="room-tab <?= $tab === 'loyalty' ? 'active' : '' ?>" style="text-decoration:none;"><i class="fas fa-heart"></i> <?= te('cust_tab_loyalty') ?></a>
</div>
