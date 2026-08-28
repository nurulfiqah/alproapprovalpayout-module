<?php
// Shared head include for pages opting into the "modern" (OKR-style) look —
// wrap page content in <div class="aap-modern">...</div> after including this.
// A page one level deeper than aap/ (e.g. admin/aap_admin.php) must set
// $aap_base = '../' before including this, so the stylesheet link still
// resolves; root-level pages leave it unset.
$aap_base = $aap_base ?? '';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?php echo $aap_base; ?>css/aap-modern.css?v=<?php echo time(); ?>" rel="stylesheet">
