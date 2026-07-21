<?php
if (!function_exists('aiart_jp')) {
    function aiart_jp(string $hex): string {
        $out = '';
        foreach (preg_split('/\s+/', trim($hex)) as $cp) {
            if ($cp === '') continue;
            $out .= html_entity_decode('&#x' . $cp . ';', ENT_QUOTES, 'UTF-8');
        }
        return $out;
    }
}
if (!function_exists('admin_menu_can')) {
    function admin_menu_can($permission) {
        if ($permission === '') return true;
        if ($permission === '__owner_only__') {
            $role = (string)($_SESSION['admin_role'] ?? '');
            if ($role === 'super_owner') return true;
            if (in_array($role, ['owner', 'Owner', 'OWNER', 'オーナー'], true)) return true;
            if (!empty($_SESSION['is_owner']) || !empty($_SESSION['admin_is_owner'])) return true;
            if (class_exists('AdminAuthController') && method_exists('AdminAuthController', 'isOwner')) return AdminAuthController::isOwner();
            return false;
        }
        if (class_exists('AdminAuthController')) return AdminAuthController::can($permission);
        return ($_SESSION['admin_role'] ?? 'staff') === 'owner';
    }
}
if (!function_exists('admin_menu_visible_for_tenant_operation')) {
    function admin_menu_visible_for_tenant_operation($href, $tenantOperation) {
        if (!$tenantOperation) return true;
        $blocked = [
            '/admin/users',
            '/admin/managers',
            '/admin/line-config',
            '/admin/public-settings',
            '/admin/gacha-settings',
            '/admin/client-setup',
            '/admin/settings',
            '/admin/logs',
            '/admin/update',
        ];
        foreach ($blocked as $prefix) {
            if (strpos((string)$href, $prefix) === 0) return false;
        }
        return true;
    }
}
$current = $_SERVER['REQUEST_URI'] ?? '';
$role = $_SESSION['admin_role'] ?? 'staff';
$roleLabel = class_exists('AdminAuthController') ? AdminAuthController::roleLabel($role) : ($role === 'owner' ? aiart_jp('30AA 30FC 30CA 30FC') : ($role === 'admin' ? aiart_jp('7BA1 7406 8005') : aiart_jp('30B9 30BF 30C3 30D5')));
if (!class_exists('Settings') && defined('BASE_PATH') && is_file(BASE_PATH . '/config/settings.php')) {
    require_once BASE_PATH . '/config/settings.php';
}
$currentTenantInfo = null;
if (class_exists('Settings') && method_exists('Settings', 'currentTenant')) {
    $currentTenantInfo = Settings::currentTenant();
}
$currentTenantName = trim((string)($_SESSION['admin_tenant_name'] ?? ($currentTenantInfo['name'] ?? '')));
$currentTenantKey = trim((string)($_SESSION['admin_tenant_key'] ?? ($currentTenantInfo['tenant_key'] ?? '')));
$currentTenantId = (int)($_SESSION['admin_tenant_id'] ?? ($currentTenantInfo['id'] ?? 0));
$currentTenantIsDefault = !empty($currentTenantInfo['is_default']) || $currentTenantKey === 'default';
$currentTenantOperation = !$currentTenantIsDefault && $currentTenantKey !== '';
if ($currentTenantName === '') {
    $currentTenantName = $currentTenantIsDefault ? 'AI' . aiart_jp('30A2 30FC 30C8 6559 5BA4') : aiart_jp('672A 9078 629E');
}
$currentTenantModeLabel = $currentTenantIsDefault ? aiart_jp('6A19 6E96 30A2 30AB 30A6 30F3 30C8') : aiart_jp('73FE 5728 64CD 4F5C 4E2D');
$tenantSettingsHref = ($currentTenantOperation && $currentTenantId > 0) ? ('/admin/tenants/' . $currentTenantId . '/settings') : '/admin/tenants';
$tenantSettingsLabel = $currentTenantOperation ? aiart_jp('3053 306E 30AF 30E9 30A4 30A2 30F3 30C8 8A2D 5B9A') : aiart_jp('30AF 30E9 30A4 30A2 30F3 30C8 7BA1 7406');
$groups = [
  aiart_jp('30E1 30A4 30F3') => [
    ['/admin/dashboard', aiart_jp('30C0 30C3 30B7 30E5 30DC 30FC 30C9'), '', ''],
    ['/admin/manual', aiart_jp('4F7F 3044 65B9 30DE 30CB 30E5 30A2 30EB'), '', 'manual'],
  ],
  aiart_jp('6559 5BA4 904B 7528') => [
    ['/admin/calendar', aiart_jp('30AB 30EC 30F3 30C0 30FC'), '', 'calendar'],
    ['/admin/classes', aiart_jp('6559 5BA4 30FB 53C2 52A0 7BA1 7406'), '', 'classes'],
    ['/admin/reservations', aiart_jp('4E88 7D04 5C65 6B74'), '', 'reservations'],
    ['/admin/attendance', aiart_jp('51FA 5E2D 5C65 6B74'), '', 'attendance'],
    ['/admin/qrcode', 'QR' . aiart_jp('30B3 30FC 30C9'), '', 'qrcode'],
  ],
  'LINE' . aiart_jp('30FB 5236 4F5C') => [
    ['/admin/image-requests', aiart_jp('4F9D 983C 4E00 89A7'), '', 'image_requests'],
    ['/admin/broadcast', aiart_jp('4E00 6589 30E1 30C3 30BB 30FC 30B8'), '', 'broadcast'],
    ['/admin/gallery', aiart_jp('30AE 30E3 30E9 30EA 30FC'), '', 'gallery'],
    ['/admin/richmenu-segments', aiart_jp('30EA 30C3 30C1 30E1 30CB 30E5 30FC 8A2D 5B9A'), '', '__owner_only__'],
    ['/admin/gacha', aiart_jp('30AC 30C1 30E3 904B 7528'), '', 'gacha'],
  ],
  aiart_jp('58F2 4E0A 30FB 5206 6790') => [
    ['/admin/payments', aiart_jp('6C7A 6E08 5C65 6B74'), '', 'payments'],
    ['/admin/tickets', aiart_jp('30C1 30B1 30C3 30C8 5C65 6B74'), '', 'tickets'],
    ['/admin/cancellations', aiart_jp('30AD 30E3 30F3 30BB 30EB 5C65 6B74'), '', 'cancellations'],
    ['/admin/report', aiart_jp('7D71 8A08 30FB 51FA 529B'), '', 'reports'],
  ],
  aiart_jp('7BA1 7406') => [
    ['/admin/users', aiart_jp('30E6 30FC 30B6 30FC'), '', 'users'],
    ['/admin/managers', aiart_jp('7BA1 7406 8005 30A2 30AB 30A6 30F3 30C8'), '', '__owner_only__'],
    [$tenantSettingsHref, $tenantSettingsLabel, '', '__owner_only__'],
    ['/admin/line-config', 'LINE' . aiart_jp('8A2D 5B9A'), '', '__owner_only__'],
    ['/admin/public-settings', aiart_jp('516C 958B 30DA 30FC 30B8 8A2D 5B9A'), '', '__owner_only__'],
    ['/admin/gacha-settings', aiart_jp('30AC 30C1 30E3 8A2D 5B9A'), '', '__owner_only__'],
    ['/admin/client-setup', aiart_jp('6A2A 5C55 958B 30FB 521D 671F 8A2D 5B9A'), '', '__owner_only__'],
    ['/admin/tenants', aiart_jp('30AF 30E9 30A4 30A2 30F3 30C8 4E00 89A7'), '', '__owner_only__'],
    ['/admin/settings', 'API' . aiart_jp('8A2D 5B9A'), '', '__owner_only__'],
    ['/admin/logs', aiart_jp('64CD 4F5C 30ED 30B0'), '', '__owner_only__'],
    ['/admin/update', aiart_jp('30A2 30C3 30D7 30C7 30FC 30C8'), '', '__owner_only__'],
  ],
];
$defaultTitle = 'AI' . aiart_jp('30A2 30FC 30C8 6559 5BA4 20 7BA1 7406 753B 9762');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? $defaultTitle, ENT_QUOTES, 'UTF-8') ?></title>
<style>
:root{--bg:#f4f5f7;--sidebar:#fff;--surface:#fff;--border:#e2e4ea;--accent:#6c5ce7;--accent2:#8b7cf8;--text:#1a202c;--muted:#718096;--danger:#e53e3e;--success:#38a169;--warning:#d69e2e;--font:'Hiragino Sans','Noto Sans JP',Arial,sans-serif}
body.dark-mode{--bg:#0f1117;--sidebar:#16191f;--surface:#1e2128;--border:#2a2d36;--text:#e2e4ea;--muted:#8b93a3}*{box-sizing:border-box;margin:0;padding:0}body{font-family:var(--font);background:var(--bg);color:var(--text);display:flex;min-height:100vh;font-size:14px}.sidebar{width:240px;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0;transition:width .18s,transform .18s;z-index:40}.sidebar-logo{padding:18px 16px;border-bottom:1px solid var(--border)}.sidebar-logo h1{font-size:14px;color:var(--accent2);line-height:1.4}.sidebar-logo span{font-size:11px;color:var(--muted);display:block;margin-top:2px}.sidebar-tenant{margin-top:12px;padding:10px;border:1px solid rgba(124,106,247,.25);background:rgba(124,106,247,.08);border-radius:8px}.sidebar-tenant-label{font-size:10px;font-weight:800;color:var(--accent2);margin-bottom:4px}.sidebar-tenant-name{font-size:13px;font-weight:800;color:var(--text);line-height:1.35;word-break:break-word}.sidebar-tenant-key{font-size:11px;color:var(--muted);margin-top:2px;word-break:break-all}.tenant-context{display:flex;align-items:center;gap:8px;min-width:0;max-width:min(42vw,520px);padding:7px 10px;border:1px solid rgba(124,106,247,.28);background:rgba(124,106,247,.08);border-radius:999px;color:var(--text)}.tenant-context-label{font-size:11px;color:var(--accent2);font-weight:800;white-space:nowrap}.tenant-context-name{font-size:13px;font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tenant-context-key{font-size:11px;color:var(--muted);white-space:nowrap}nav{flex:1;padding:10px 0;overflow-y:auto}.nav-heading{font-size:10px;font-weight:700;color:var(--muted);padding:14px 16px 6px;letter-spacing:.08em}nav a{display:flex;align-items:center;gap:10px;min-height:38px;padding:9px 16px;color:var(--muted);text-decoration:none;font-size:13px;line-height:1.35}nav a:hover{color:var(--text);background:rgba(124,106,247,.1)}nav a.active{color:var(--accent2);background:rgba(124,106,247,.15);border-right:3px solid var(--accent)}nav a .nav-icon{width:18px;text-align:center;flex-shrink:0}.sidebar-footer{padding:12px 16px;border-top:1px solid var(--border);font-size:12px}.sidebar-footer a{color:var(--muted);text-decoration:none}.role-badge{font-size:10px;background:rgba(34,197,94,.18);color:#22c55e;padding:1px 6px;border-radius:10px}.main{flex:1;display:flex;flex-direction:column;min-width:0}.topbar{min-height:56px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;padding:0 24px;background:var(--bg);position:sticky;top:0;z-index:20}.topbar h2{font-size:16px;font-weight:700}.topbar .badge{font-size:11px;background:rgba(124,106,247,.18);color:var(--accent2);padding:2px 8px;border-radius:20px}.menu-toggle{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:38px;padding:0 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text);cursor:pointer;font-weight:700}.theme-toggle{border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);padding:4px 8px;cursor:pointer}.content{flex:1;padding:24px;overflow-y:auto}.card{background:var(--surface);border:1px solid var(--border);border-radius:8px}.card-header{padding:14px 20px;border-bottom:1px solid var(--border);font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px}.card-body{padding:20px}.responsive-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px}.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px}.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:16px}.stat-label{font-size:11px;color:var(--muted)}.stat-value{font-size:28px;font-weight:700;margin-top:4px}.stat-value.accent{color:var(--accent2)}.stat-value.danger{color:var(--danger)}.stat-value.success{color:var(--success)}.stat-value.warning{color:var(--warning)}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}th{font-size:11px;color:var(--muted);white-space:nowrap}.form-group{margin-bottom:16px}label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:500}input[type=text],input[type=email],input[type=password],input[type=date],input[type=time],input[type=number],select,textarea{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:8px 12px;color:var(--text);font-size:13px;font-family:var(--font)}textarea{resize:vertical;min-height:80px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:500;cursor:pointer;border:none;text-decoration:none}.btn-primary{background:var(--accent);color:#fff}.btn-secondary{background:var(--surface);border:1px solid var(--border);color:var(--text)}.btn-danger{background:rgba(239,68,68,.2);color:var(--danger);border:1px solid rgba(239,68,68,.3)}.alert{padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:16px}.alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#22c55e}.alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444}.filter-bar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}.pagination{display:flex;gap:4px;margin-top:16px;flex-wrap:wrap}body.sidebar-collapsed .sidebar{width:72px}body.sidebar-collapsed .sidebar-logo span,body.sidebar-collapsed .sidebar-tenant,body.sidebar-collapsed .nav-heading,body.sidebar-collapsed nav a span,body.sidebar-collapsed .sidebar-footer{display:none}body.sidebar-collapsed nav a{justify-content:center;padding:9px 0}.sidebar-backdrop{display:none}@media(max-width:1024px){html,body{width:100%;max-width:100%;overflow-x:hidden}body{display:block}.main{width:100%;max-width:100%;min-width:0}.sidebar{position:fixed;top:0;bottom:0;left:0;width:min(86vw,300px);transform:translateX(-100%);box-shadow:16px 0 32px rgba(0,0,0,.28)}body.nav-open .sidebar{transform:translateX(0)}body.sidebar-collapsed .sidebar{width:min(86vw,300px)}body.sidebar-collapsed .sidebar-logo span,body.sidebar-collapsed .sidebar-tenant,body.sidebar-collapsed .nav-heading,body.sidebar-collapsed nav a span,body.sidebar-collapsed .sidebar-footer{display:block}body.sidebar-collapsed nav a{justify-content:flex-start;padding:9px 16px}.sidebar-backdrop{display:block;position:fixed;inset:0;background:rgba(0,0,0,.45);opacity:0;visibility:hidden;z-index:30}body.nav-open .sidebar-backdrop{opacity:1;visibility:visible}.content{padding:14px;overflow-x:hidden;width:100%;max-width:100%}.topbar{padding:0 14px;width:100%;max-width:100%;gap:8px}.topbar h2{font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tenant-context{max-width:44vw;padding:6px 8px}.tenant-context-label,.tenant-context-key{display:none}.card,.stat-card{width:100%;max-width:100%;min-width:0}.card-header,.card-body{padding:14px}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.content > div[style*="grid"],.card-body > div[style*="grid"],form > div[style*="grid"]{grid-template-columns:1fr!important;max-width:100%!important}.content > div[style*="grid"] > *,.card-body > div[style*="grid"] > *,form > div[style*="grid"] > *{min-width:0!important}.table-wrap{overflow-x:auto;max-width:100%;-webkit-overflow-scrolling:touch}table{min-width:640px}input,select,textarea,button{max-width:100%}}@media(max-width:560px){.content{padding:12px}.responsive-grid,.stats-grid{grid-template-columns:1fr!important}.stat-card{padding:14px}.stat-value{font-size:24px}.btn{width:100%}.menu-toggle{padding:0 10px;font-size:13px}.tenant-context{max-width:38vw}.tenant-context-name{font-size:12px}.theme-toggle{display:none}}
</style>
<?= $extraHead ?? '' ?>
</head>
<body>
<aside class="sidebar" id="admin-sidebar">
  <div class="sidebar-logo">
    <h1>AI<?= aiart_jp('30A2 30FC 30C8 6559 5BA4') ?></h1>
    <span><?= aiart_jp('753B 50CF 751F 6210 30B7 30B9 30C6 30E0') ?></span>
    <div class="sidebar-tenant">
      <div class="sidebar-tenant-label"><?= htmlspecialchars($currentTenantModeLabel, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="sidebar-tenant-name"><?= htmlspecialchars($currentTenantName, ENT_QUOTES, 'UTF-8') ?></div>
      <?php if ($currentTenantKey !== ''): ?>
        <div class="sidebar-tenant-key"><?= aiart_jp('30AD 30FC') ?>: <?= htmlspecialchars($currentTenantKey, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
  </div>
  <nav aria-label="<?= aiart_jp('7BA1 7406 30E1 30CB 30E5 30FC') ?>">
    <?php foreach ($groups as $heading => $items): ?>
      <?php $visible = []; foreach ($items as $item) { if (admin_menu_can($item[3]) && admin_menu_visible_for_tenant_operation($item[0], $currentTenantOperation)) $visible[] = $item; } ?>
      <?php if (!$visible) continue; ?>
      <div class="nav-heading"><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></div>
      <?php foreach ($visible as $item): ?>
        <?php list($href, $label, $icon, $permission) = $item; $active = strpos($current, $href) === 0 ? 'active' : ''; ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="<?= $active ?>"><span class="nav-icon"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div style="margin-bottom:6px"><?= htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? aiart_jp('7BA1 7406 8005'), ENT_QUOTES, 'UTF-8') ?> <span class="role-badge"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
    <a href="/admin/logout"><?= aiart_jp('30ED 30B0 30A2 30A6 30C8') ?></a>
  </div>
</aside>
<div class="sidebar-backdrop" onclick="closeAdminNav()"></div>
<div class="main">
  <div class="topbar">
    <button type="button" class="menu-toggle" onclick="toggleAdminNav()"><?= aiart_jp('30E1 30CB 30E5 30FC') ?></button>
    <h2><?= htmlspecialchars($pageTitle ?? '', ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if (!empty($topbarBadge)): ?><span class="badge"><?= htmlspecialchars($topbarBadge, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
    <div class="tenant-context" title="<?= htmlspecialchars($currentTenantModeLabel . ': ' . $currentTenantName . ($currentTenantKey !== '' ? ' / ' . $currentTenantKey : ''), ENT_QUOTES, 'UTF-8') ?>">
      <span class="tenant-context-label"><?= htmlspecialchars($currentTenantModeLabel, ENT_QUOTES, 'UTF-8') ?></span>
      <span class="tenant-context-name"><?= htmlspecialchars($currentTenantName, ENT_QUOTES, 'UTF-8') ?></span>
      <?php if ($currentTenantKey !== ''): ?><span class="tenant-context-key"><?= htmlspecialchars($currentTenantKey, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
    </div>
    <button id="theme-toggle" class="theme-toggle" type="button" onclick="toggleTheme()" style="margin-left:auto"><?= aiart_jp('6697') ?></button>
  </div>
  <div class="content"><?= $content ?? '' ?></div>
</div>
<script>
function toggleAdminNav(){if(window.innerWidth<=1024){document.body.classList.toggle('nav-open');return;}document.body.classList.toggle('sidebar-collapsed');localStorage.setItem('adminSidebarCollapsed',document.body.classList.contains('sidebar-collapsed')?'1':'0');}
function closeAdminNav(){document.body.classList.remove('nav-open');}
function toggleTheme(){document.body.classList.toggle('dark-mode');localStorage.setItem('theme',document.body.classList.contains('dark-mode')?'dark':'light');}
(function(){if(localStorage.getItem('theme')==='dark')document.body.classList.add('dark-mode');if(localStorage.getItem('adminSidebarCollapsed')==='1'&&window.innerWidth>1024)document.body.classList.add('sidebar-collapsed');document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAdminNav();});document.addEventListener('click',function(e){if(window.innerWidth<=1024&&e.target.closest('.sidebar nav a'))closeAdminNav();});})();
</script>
</body>
</html>
