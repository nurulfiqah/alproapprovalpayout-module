<?php
// A page may set $page_js before including this footer to load a
// page-specific script from js/ - mirrors okr/footer.php's $page_js.
// Must print BEFORE the include below: common/index_adv.php's $connect=0
// branch ends with `mysqli_close($conn); exit;`, so anything placed after
// that include() never runs.
?>
<?php if (isset($page_js) && $page_js !== ''): ?>
<script src="<?php echo $page_js; ?>?v=<?php echo time(); ?>"></script>
<?php endif; ?>
<?php
// Same $aap_base convention as aap_modern_head.php/aap_sidebar.php - a page
// one level deeper than aap/ (admin/*.php) sets $aap_base = '../' before
// including this footer, so this relative include still resolves (PHP
// resolves it against the *original* script's cwd, not this file's own
// directory, so admin/ pages need the extra ../ here too).
$connect = 0;
include(($aap_base ?? '') . '../common/index_adv.php');
