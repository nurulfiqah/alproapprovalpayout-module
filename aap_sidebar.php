<?php
// Top navigation menu (follows framework: legacy layout + Alpro CSS overlay)
// A page one level deeper than aap/ (admin/*.php) must set $aap_base = '../'
// before including this, so every relative link/asset here still resolves;
// root-level pages leave it unset. $admin_prefix separately controls the
// Settings/Admin nav links, which point INTO admin/ from root pages but stay
// bare (same-folder) once already inside admin/.
$current_page = basename($_SERVER['PHP_SELF']);
$aap_base = $aap_base ?? '';
$admin_prefix = ($aap_base === '') ? 'admin/' : '';
?>
<link rel="stylesheet" href="<?php echo $aap_base; ?>../common/css/layout.css">
<link rel="stylesheet" href="<?php echo $aap_base; ?>../common/css/page.css">
<link rel="stylesheet" href="<?php echo $aap_base; ?>../common/css/alpro-core.css">
<link rel="stylesheet" href="<?php echo $aap_base; ?>../common/css/alpro-components.css">
<link rel="stylesheet" href="<?php echo $aap_base; ?>../common/css/alpro-utils.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?php echo $aap_base; ?>css/aap-sidebar.css?v=<?php echo time(); ?>" rel="stylesheet">

<div class="alpro-box" style="margin-bottom: 20px; text-align: left; background: #fff; overflow: visible;">
    <div class="alpro-actions" style="margin-bottom: 0px; display: flex; align-items: center; flex-wrap: wrap; gap: 5px; overflow: visible;">
        <strong style="margin-right: 15px; color: #2c3e50; font-size: 16px;">Alpro Approval Protocol (AAP)</strong>

        <a href="<?php echo $aap_base; ?>index.php" class="alpro-btn <?php echo ($current_page == 'index.php') ? 'alpro-btn-blue' : 'alpro-btn-grey'; ?>" style="text-decoration: none;">Case Queue</a>

        <?php if (!empty($aap_is_admin)): ?>
        <a href="<?php echo $admin_prefix; ?>aap_admin.php" class="alpro-btn <?php echo ($current_page == 'aap_admin.php') ? 'alpro-btn-orange' : 'alpro-btn-grey'; ?>" style="text-decoration: none;">Settings</a>
        <a href="<?php echo $admin_prefix; ?>aap_settings.php" class="alpro-btn <?php echo ($current_page == 'aap_settings.php') ? 'alpro-btn-orange' : 'alpro-btn-grey'; ?>" style="text-decoration: none;">Admin</a>
        <?php endif; ?>

        <div style="margin-left: auto; display: flex; align-items: center; gap: 10px; position: relative;">
            <a href="#" id="aap-notif-bell" title="Notifications" style="position: relative; text-decoration: none; color: #2c3e50; font-size: 18px; padding: 4px;">
                <i class="bi bi-bell"></i>
                <span id="aap-notif-badge" style="display:none; position:absolute; top:-4px; right:-6px; background:#e74c3c; color:#fff; font-size:10px; font-weight:bold; padding:1px 5px; border-radius:10px; line-height:1.4;">0</span>
            </a>
            <div id="aap-notif-menu" style="display:none; position:absolute; top:100%; right:0; margin-top:8px; width:320px; background:#fff; border:1px solid #ddd; border-radius:6px; box-shadow:0 8px 16px rgba(0,0,0,0.1); z-index:1000; text-align:left;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-bottom:1px solid #eee;">
                    <strong style="font-size:13px;">Notifications</strong>
                    <button type="button" id="aap-notif-markall" style="background:none; border:none; color:#2980b9; font-size:12px; cursor:pointer; padding:0;">Mark all read</button>
                </div>
                <div id="aap-notif-list" style="max-height:320px; overflow-y:auto;">
                    <div style="padding:14px 12px; color:#888; font-size:12px;">No notifications.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>var AAP_BASE = '<?php echo $aap_base; ?>';</script>
<script src="<?php echo $aap_base; ?>js/aap-sidebar.js?v=<?php echo time(); ?>"></script>
