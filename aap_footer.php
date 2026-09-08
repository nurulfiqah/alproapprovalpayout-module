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
<script>
// lock_adv.php's top-bar icons (back/home/forward/smiley/noti/setting/lock2/
// T&C/help/exit) are hardcoded as "../../odb/common/img/*.png" - only correct
// when the current page sits exactly one folder under odb/. Every page under
// this module's admin/ folder is two folders deep, so that relative path
// resolves short and 404s. Not our file to edit (shared outer-app
// lock_adv.php, not part of this repo) - rewrite the broken <img> src to the
// equivalent absolute path instead, on every AAP page for consistency.
document.querySelectorAll('img[src*="../../odb/common/img/"]').forEach(function (img) {
    var raw = img.getAttribute('src');
    var file = raw.split('/').pop();
    img.setAttribute('src', '/odb/common/img/' + file);
});
</script>
<?php
// Same $aap_base convention as aap_modern_head.php/aap_sidebar.php - a page
// one level deeper than aap/ (admin/*.php) sets $aap_base = '../' before
// including this footer, so this relative include still resolves (PHP
// resolves it against the *original* script's cwd, not this file's own
// directory, so admin/ pages need the extra ../ here too).
$connect = 0;
include(($aap_base ?? '') . '../common/index_adv.php');
