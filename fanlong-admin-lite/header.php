<?php
require_once 'config.php';
checkLogin();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="zh-CN" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo APP_NAME; ?> · <?php echo $page_title ?? '管理面板'; ?></title>
<script>(function(){var t=localStorage.getItem('fl_theme')||'dark';document.documentElement.setAttribute('data-bs-theme',t);})();</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
<style>
/* ============================================================
   FANLONG ADMIN — DESIGN SYSTEM v3
   Brand: Cinnabar · Surfaces: Cool graphite · Mode: Dark-first
   ============================================================ */

/* --- Tokens --- */
:root {
  --brand:       oklch(0.59 0.22 22);
  --brand-h:     oklch(0.64 0.20 22);
  --brand-dim:   oklch(0.59 0.22 22 / 0.13);
  --brand-glow:  oklch(0.59 0.22 22 / 0.30);

  --sidebar-w:   248px;
  --sidebar-sm:  62px;
  --topbar-h:    56px;

  --r-xs: 5px;
  --r-sm: 7px;
  --r-md: 10px;
  --r-lg: 13px;
  --r-xl: 18px;
}

/* --- Dark surfaces --- */
[data-bs-theme="dark"] {
  --s-body:    oklch(0.11 0.005 258);
  --s-sidebar: oklch(0.08 0.006 258);
  --s-card:    oklch(0.15 0.007 258);
  --s-raise:   oklch(0.19 0.008 258);
  --s-input:   oklch(0.18 0.007 258);
  --b-weak:    oklch(0.22 0.008 258);
  --b-mid:     oklch(0.28 0.010 258);
  --t-1:       oklch(0.92 0.004 258);
  --t-2:       oklch(0.58 0.006 258);
  --t-3:       oklch(0.38 0.005 258);
  color-scheme: dark;
}

/* --- Light surfaces (sidebar stays dark) --- */
[data-bs-theme="light"] {
  --s-body:    oklch(0.965 0.003 258);
  --s-sidebar: oklch(0.08 0.006 258);
  --s-card:    oklch(1.00 0.000 0);
  --s-raise:   oklch(0.95 0.003 258);
  --s-input:   oklch(0.96 0.003 258);
  --b-weak:    oklch(0.88 0.005 258);
  --b-mid:     oklch(0.78 0.008 258);
  --t-1:       oklch(0.14 0.005 258);
  --t-2:       oklch(0.42 0.007 258);
  --t-3:       oklch(0.60 0.005 258);
  color-scheme: light;
}

/* --- Reset & base --- */
*, *::before, *::after { box-sizing: border-box; }

body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Noto Sans SC',
               'Microsoft YaHei', 'Segoe UI', sans-serif;
  background: var(--s-body);
  color: var(--t-1);
  font-size: 14px;
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
}

/* ============================================================
   SIDEBAR
   ============================================================ */
#sidebar {
  position: fixed; inset: 0 auto 0 0;
  width: var(--sidebar-w);
  background: var(--s-sidebar);
  border-right: 1px solid oklch(0.18 0.007 258);
  display: flex; flex-direction: column;
  z-index: 1040;
  transition: width 0.24s cubic-bezier(0.4, 0, 0.2, 1);
  overflow: hidden;
}
#sidebar.collapsed { width: var(--sidebar-sm); }

/* Brand */
.sb-brand {
  display: flex; align-items: center; gap: 11px;
  padding: 16px 13px 13px;
  border-bottom: 1px solid oklch(0.16 0.007 258);
  flex-shrink: 0;
}
.sb-brand-icon {
  width: 34px; height: 34px; min-width: 34px;
  background: var(--brand);
  border-radius: var(--r-md);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.95rem; color: #fff;
  box-shadow: 0 4px 14px var(--brand-glow);
  flex-shrink: 0;
}
.sb-brand-text { overflow: hidden; transition: opacity 0.2s, width 0.24s; }
.sb-brand-name {
  font-size: 0.88rem; font-weight: 700;
  color: oklch(0.91 0.004 258); white-space: nowrap; line-height: 1.25;
}
.sb-brand-ver {
  font-size: 0.66rem; color: oklch(0.40 0.005 258); white-space: nowrap;
}

/* Nav scroll area */
.sb-nav {
  flex: 1; overflow-y: auto; padding: 8px 0 6px;
  scrollbar-width: none;
}
.sb-nav::-webkit-scrollbar { display: none; }

/* Section label */
.sb-section {
  padding: 13px 16px 4px;
  font-size: 0.63rem; font-weight: 700; letter-spacing: 0.10em;
  text-transform: uppercase;
  color: oklch(0.36 0.006 258);
  white-space: nowrap; overflow: hidden;
  transition: opacity 0.2s, width 0.24s;
}

/* Nav item */
.sb-item a {
  display: flex; align-items: center; gap: 10px;
  padding: 7px 10px;
  margin: 1px 6px;
  border-radius: var(--r-md);
  color: oklch(0.55 0.006 258);
  text-decoration: none;
  font-size: 0.845rem;
  white-space: nowrap;
  transition: background 0.15s, color 0.15s;
}
.sb-item a:hover {
  background: oklch(0.20 0.007 258);
  color: oklch(0.82 0.005 258);
}
.sb-item a.active {
  background: var(--brand-dim);
  color: oklch(0.82 0.13 22);
  font-weight: 600;
}
.sb-item a.active .sb-icon { color: var(--brand); }
.sb-icon { width: 17px; text-align: center; font-size: 0.88rem; flex-shrink: 0; }
.sb-label { overflow: hidden; transition: opacity 0.2s, width 0.24s; }

/* Footer */
.sb-footer {
  padding: 11px 14px;
  border-top: 1px solid oklch(0.16 0.007 258);
  flex-shrink: 0;
}
.sb-footer a {
  display: flex; align-items: center; gap: 9px;
  color: oklch(0.38 0.005 258); text-decoration: none;
  font-size: 0.795rem; white-space: nowrap;
  transition: color 0.15s;
}
.sb-footer a:hover { color: oklch(0.65 0.005 258); }

/* Collapsed state */
#sidebar.collapsed .sb-brand-text,
#sidebar.collapsed .sb-section,
#sidebar.collapsed .sb-label { opacity: 0; width: 0; pointer-events: none; }
#sidebar.collapsed .sb-item a {
  padding: 8px 6px; justify-content: center; margin: 1px 5px;
}
#sidebar.collapsed .sb-icon { width: auto; }
#sidebar.collapsed .sb-footer a span { display: none; }
#sidebar.collapsed .sb-footer a { justify-content: center; padding: 8px 0; }

/* ============================================================
   MAIN CONTENT
   ============================================================ */
#main-content {
  margin-left: var(--sidebar-w);
  min-height: 100vh;
  transition: margin-left 0.24s cubic-bezier(0.4, 0, 0.2, 1);
  background: var(--s-body);
  display: flex; flex-direction: column;
}
#main-content.expanded { margin-left: var(--sidebar-sm); }

/* ============================================================
   TOPBAR
   ============================================================ */
.topbar {
  height: var(--topbar-h);
  position: sticky; top: 0; z-index: 100;
  background: var(--s-body);
  border-bottom: 1px solid var(--b-weak);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 22px; gap: 12px;
  flex-shrink: 0;
}

.topbar-left {}
.topbar-title {
  font-size: 1.02rem; font-weight: 700; margin: 0;
  color: var(--t-1); line-height: 1.2;
}
.topbar-right { display: flex; align-items: center; gap: 5px; }

.tb-btn {
  width: 32px; height: 32px;
  border-radius: var(--r-md);
  border: 1px solid var(--b-weak);
  background: transparent; color: var(--t-2);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: all 0.15s; font-size: 0.82rem;
  text-decoration: none; line-height: 1;
}
.tb-btn:hover {
  background: var(--s-raise);
  color: var(--t-1);
  border-color: var(--b-mid);
}
.tb-btn:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: 2px;
}

.admin-chip {
  display: flex; align-items: center; gap: 7px;
  background: var(--s-raise);
  border: 1px solid var(--b-weak);
  border-radius: var(--r-xl); padding: 3px 10px 3px 4px;
}
.admin-avatar {
  width: 25px; height: 25px; border-radius: var(--r-sm);
  background: var(--brand);
  color: #fff; display: flex; align-items: center; justify-content: center;
  font-size: 0.75rem; font-weight: 700; flex-shrink: 0;
}
.admin-name { font-size: 0.79rem; font-weight: 600; line-height: 1.2; color: var(--t-1); }
.admin-role { font-size: 0.63rem; color: var(--t-2); }

/* Live indicator */
.live-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: oklch(0.65 0.18 155);
  animation: live-pulse 2.4s infinite;
  display: inline-block;
}
@keyframes live-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%       { opacity: 0.40; transform: scale(0.72); }
}

/* Breadcrumb */
.breadcrumb {
  margin: 0 !important; font-size: 0.71rem !important;
}
.breadcrumb-item, .breadcrumb-item a {
  color: var(--t-3) !important; text-decoration: none;
}
.breadcrumb-item.active { color: var(--t-3) !important; }
.breadcrumb-item + .breadcrumb-item::before { color: var(--t-3) !important; }

/* ============================================================
   PAGE BODY
   ============================================================ */
.page-body {
  padding: 20px 22px;
  flex: 1;
}

/* ============================================================
   FLASH
   ============================================================ */
.flash-area { padding: 10px 22px 0; }

/* ============================================================
   CARDS
   ============================================================ */
.card {
  background: var(--s-card) !important;
  border: 1px solid var(--b-weak) !important;
  border-radius: var(--r-lg) !important;
  box-shadow: none !important;
}
.card-header {
  background: transparent !important;
  border-bottom: 1px solid var(--b-weak) !important;
  border-radius: var(--r-lg) var(--r-lg) 0 0 !important;
  padding: 13px 18px !important;
  font-weight: 600 !important;
  font-size: 0.84rem !important;
  color: var(--t-1) !important;
}
.card-body { color: var(--t-1); }
.card-footer {
  background: transparent !important;
  border-top: 1px solid var(--b-weak) !important;
}

/* ============================================================
   STAT GRID (dashboard)
   ============================================================ */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(178px, 1fr));
  gap: 11px;
  margin-bottom: 28px;
}
.stat-card-link {
  text-decoration: none; color: inherit; display: block;
}
.stat-card-inner {
  background: var(--s-card);
  border: 1px solid var(--b-weak);
  border-radius: var(--r-lg);
  padding: 17px 16px;
  display: flex; align-items: center; gap: 13px;
  transition: background 0.18s, border-color 0.18s, transform 0.18s, box-shadow 0.18s;
}
.stat-card-link:hover .stat-card-inner {
  background: var(--s-raise);
  border-color: var(--b-mid);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px oklch(0 0 0 / 0.28);
}
.stat-icon-wrap {
  width: 42px; height: 42px; border-radius: var(--r-md);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.15rem; flex-shrink: 0;
}
.stat-num {
  font-size: 1.55rem; font-weight: 700; line-height: 1;
  font-variant-numeric: tabular-nums;
  color: var(--t-1); margin-bottom: 3px;
}
.stat-label { font-size: 0.73rem; color: var(--t-2); }

/* ============================================================
   TABLES
   ============================================================ */
.table {
  color: var(--t-1) !important;
  border-color: var(--b-weak) !important;
  margin-bottom: 0 !important;
}
.table > thead > tr > th {
  background: transparent !important;
  color: var(--t-2) !important;
  font-weight: 600 !important;
  font-size: 0.72rem !important;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  padding: 10px 14px !important;
  border-bottom: 1px solid var(--b-mid) !important;
  white-space: nowrap;
  border-top: none !important;
}
.table > tbody > tr > td {
  padding: 10px 14px !important;
  border-bottom: 1px solid var(--b-weak) !important;
  border-top: none !important;
  font-size: 0.845rem;
  vertical-align: middle !important;
  color: var(--t-1) !important;
}
.table > tbody > tr:last-child > td { border-bottom: none !important; }
.table-hover > tbody > tr { transition: background 0.12s; }
.table-hover > tbody > tr:hover > td {
  background: var(--s-raise) !important;
}
/* Remove Bootstrap's table-active bg from thead */
.table-active > * { --bs-table-bg: transparent !important; }
.table-active th, .table-active td {
  background: transparent !important;
  color: var(--t-2) !important;
}

/* ============================================================
   BADGES
   ============================================================ */
.badge {
  font-size: 0.70rem !important;
  font-weight: 600 !important;
  padding: 3px 7px !important;
  border-radius: var(--r-xs) !important;
  letter-spacing: 0.01em;
}
.badge.rounded-pill { border-radius: 100px !important; }

.badge.bg-success  {
  background: oklch(0.65 0.18 152 / 0.16) !important;
  color: oklch(0.67 0.17 152) !important;
}
.badge.bg-danger   {
  background: oklch(0.59 0.22 22 / 0.16) !important;
  color: oklch(0.70 0.18 22) !important;
}
.badge.bg-warning  {
  background: oklch(0.80 0.15 83 / 0.18) !important;
  color: oklch(0.68 0.14 83) !important;
}
.badge.bg-info     {
  background: oklch(0.63 0.12 228 / 0.18) !important;
  color: oklch(0.69 0.11 228) !important;
}
.badge.bg-primary  {
  background: var(--brand-dim) !important;
  color: oklch(0.70 0.14 22) !important;
}
.badge.bg-secondary {
  background: oklch(0.26 0.006 258) !important;
  color: oklch(0.76 0.005 258) !important;   /* contrast ≈5.9:1 */
}
.badge.bg-dark {
  background: oklch(0.20 0.006 258) !important;
  color: oklch(0.78 0.005 258) !important;   /* contrast ≈6.5:1 */
}
.badge.text-dark { color: inherit !important; }

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
  border-radius: var(--r-md) !important;
  font-weight: 500 !important;
  font-size: 0.835rem !important;
  transition: all 0.16s !important;
  line-height: 1.4 !important;
}
.btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

.btn-primary {
  background: var(--brand) !important;
  border-color: var(--brand) !important;
  color: #fff !important;
}
.btn-primary:hover, .btn-primary:focus {
  background: var(--brand-h) !important;
  border-color: var(--brand-h) !important;
  box-shadow: 0 4px 14px var(--brand-glow) !important;
  color: #fff !important;
}
.btn-outline-primary {
  color: oklch(0.70 0.14 22) !important;
  border-color: oklch(0.59 0.22 22 / 0.45) !important;
  background: transparent !important;
}
.btn-outline-primary:hover {
  background: var(--brand-dim) !important;
  border-color: var(--brand) !important;
  color: oklch(0.76 0.12 22) !important;
}

/* Outline variants (warning, info, etc.) */
.btn-outline-warning  { border-color: oklch(0.80 0.15 83 / 0.5) !important; color: oklch(0.68 0.14 83) !important; }
.btn-outline-warning:hover  { background: oklch(0.80 0.15 83 / 0.12) !important; border-color: oklch(0.80 0.15 83) !important; color: oklch(0.68 0.14 83) !important; }
.btn-outline-success  { border-color: oklch(0.65 0.18 152 / 0.5) !important; color: oklch(0.67 0.17 152) !important; }
.btn-outline-success:hover  { background: oklch(0.65 0.18 152 / 0.12) !important; border-color: oklch(0.65 0.18 152) !important; color: oklch(0.67 0.17 152) !important; }
.btn-outline-danger   { border-color: oklch(0.59 0.22 22 / 0.5) !important; color: oklch(0.70 0.18 22) !important; }
.btn-outline-danger:hover   { background: oklch(0.59 0.22 22 / 0.12) !important; border-color: var(--brand) !important; color: oklch(0.70 0.18 22) !important; }
.btn-outline-info     { border-color: oklch(0.63 0.12 228 / 0.5) !important; color: oklch(0.69 0.11 228) !important; }
.btn-outline-info:hover     { background: oklch(0.63 0.12 228 / 0.12) !important; border-color: oklch(0.63 0.12 228) !important; color: oklch(0.69 0.11 228) !important; }
.btn-outline-secondary { border-color: var(--b-mid) !important; color: var(--t-1) !important; }
.btn-outline-secondary:hover { background: var(--s-raise) !important; color: var(--t-1) !important; border-color: var(--b-mid) !important; }

/* Sizes */
.btn-sm  { padding: 4px 10px !important; font-size: 0.775rem !important; }
.btn-xs  { padding: 2px 7px  !important; font-size: 0.72rem  !important; }
.btn-lg  { padding: 10px 20px !important; font-size: 0.92rem  !important; }

/* ============================================================
   SOLID-COLOUR BUTTONS — DARK-MODE CONTRAST FIXES
   Bootstrap dark-mode inverts text utilities (text-dark →
   light-gray, text-white on pale bg), causing poor legibility.
   These overrides set explicit bg + text for each colour.
   ============================================================ */

/* Warning: amber bg — must keep very dark text */
[data-bs-theme="dark"] .btn-warning {
  background-color: oklch(0.80 0.155 83) !important;
  border-color:     oklch(0.74 0.165 83) !important;
  color:            oklch(0.12 0.005 258) !important;
}
[data-bs-theme="dark"] .btn-warning:hover,
[data-bs-theme="dark"] .btn-warning:active {
  background-color: oklch(0.76 0.160 83) !important;
  border-color:     oklch(0.70 0.170 83) !important;
  color:            oklch(0.08 0.003 258) !important;
}

/* Info: darkened cyan — white text now readable */
[data-bs-theme="dark"] .btn-info {
  background-color: oklch(0.58 0.12 228) !important;
  border-color:     oklch(0.52 0.13 228) !important;
  color: #fff !important;
}
[data-bs-theme="dark"] .btn-info:hover,
[data-bs-theme="dark"] .btn-info:active {
  background-color: oklch(0.53 0.13 228) !important;
  border-color:     oklch(0.47 0.14 228) !important;
  color: #fff !important;
}

/* Success: deeper green — white text */
[data-bs-theme="dark"] .btn-success {
  background-color: oklch(0.56 0.17 152) !important;
  border-color:     oklch(0.50 0.18 152) !important;
  color: #fff !important;
}
[data-bs-theme="dark"] .btn-success:hover,
[data-bs-theme="dark"] .btn-success:active {
  background-color: oklch(0.51 0.18 152) !important;
  border-color:     oklch(0.45 0.19 152) !important;
  color: #fff !important;
}

/* Danger: deeper red — white text (distinct from brand hue) */
[data-bs-theme="dark"] .btn-danger {
  background-color: oklch(0.55 0.22 14) !important;
  border-color:     oklch(0.49 0.22 14) !important;
  color: #fff !important;
}
[data-bs-theme="dark"] .btn-danger:hover,
[data-bs-theme="dark"] .btn-danger:active {
  background-color: oklch(0.50 0.22 14) !important;
  border-color:     oklch(0.44 0.23 14) !important;
  color: #fff !important;
}

/* ============================================================
   BTN-OUTLINE-SECONDARY — PER-THEME CONTRAST TUNING
   Base rule (above) uses adaptive tokens --b-mid / --t-1;
   these per-theme rules push border visibility further.
   ============================================================ */

/* Dark mode: border barely shows at --b-mid; push to L≈0.40 */
[data-bs-theme="dark"] .btn-outline-secondary {
  border-color: oklch(0.40 0.010 258) !important;
  color:        oklch(0.88 0.004 258) !important;
}
[data-bs-theme="dark"] .btn-outline-secondary:hover {
  background:   oklch(0.22 0.008 258) !important;
  border-color: oklch(0.50 0.012 258) !important;
  color:        oklch(0.96 0.003 258) !important;
}

/* Light mode: --b-mid on white is still too faint; use mid-gray */
[data-bs-theme="light"] .btn-outline-secondary {
  border-color: oklch(0.58 0.010 258) !important;
  color:        oklch(0.26 0.008 258) !important;
}
[data-bs-theme="light"] .btn-outline-secondary:hover {
  background:   oklch(0.93 0.004 258) !important;
  border-color: oklch(0.46 0.012 258) !important;
  color:        oklch(0.16 0.006 258) !important;
}

/* ============================================================
   FORMS
   ============================================================ */
.form-control, .form-select {
  background: var(--s-input) !important;
  border: 1px solid var(--b-weak) !important;
  border-radius: var(--r-md) !important;
  color: var(--t-1) !important;
  font-size: 0.86rem !important;
  padding: 7px 11px !important;
  transition: border-color 0.16s, box-shadow 0.16s !important;
}
.form-control:focus, .form-select:focus {
  border-color: var(--brand) !important;
  box-shadow: 0 0 0 3px var(--brand-dim) !important;
  background: var(--s-input) !important;
  color: var(--t-1) !important;
}
.form-control::placeholder { color: var(--t-3) !important; }
textarea.form-control { line-height: 1.6 !important; }

.form-label {
  font-size: 0.78rem !important;
  font-weight: 600 !important;
  color: var(--t-2) !important;
  margin-bottom: 5px !important;
}
.form-text { font-size: 0.72rem !important; color: var(--t-3) !important; }

.input-group-text {
  background: var(--s-raise) !important;
  border-color: var(--b-weak) !important;
  color: var(--t-2) !important;
  font-size: 0.84rem !important;
}

.form-check-input {
  background-color: var(--s-raise) !important;
  border-color: var(--b-mid) !important;
}
.form-check-input:checked {
  background-color: var(--brand) !important;
  border-color: var(--brand) !important;
}
.form-check-label { font-size: 0.84rem; color: var(--t-1); }

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
  border-radius: var(--r-md) !important;
  font-size: 0.84rem !important;
  border: 1px solid transparent !important;
  padding: 9px 14px !important;
}
.alert-success {
  background: oklch(0.65 0.18 152 / 0.11) !important;
  border-color: oklch(0.65 0.18 152 / 0.30) !important;
  color: oklch(0.70 0.15 152) !important;
}
.alert-danger {
  background: oklch(0.59 0.22 22 / 0.11) !important;
  border-color: oklch(0.59 0.22 22 / 0.30) !important;
  color: oklch(0.74 0.15 22) !important;
}
.alert-warning {
  background: oklch(0.80 0.15 83 / 0.11) !important;
  border-color: oklch(0.80 0.15 83 / 0.30) !important;
  color: oklch(0.65 0.13 83) !important;
}
.alert-info {
  background: oklch(0.63 0.12 228 / 0.11) !important;
  border-color: oklch(0.63 0.12 228 / 0.30) !important;
  color: oklch(0.67 0.10 228) !important;
}
.btn-close {
  filter: invert(0.6) !important;
  opacity: 0.7;
}
[data-bs-theme="light"] .btn-close { filter: none !important; }

/* ============================================================
   LIST GROUPS
   ============================================================ */
.list-group-item {
  background: transparent !important;
  border-color: var(--b-weak) !important;
  color: var(--t-1) !important;
  font-size: 0.845rem;
  padding: 9px 16px;
}
.list-group-item:hover { background: var(--s-raise) !important; }
.list-group-flush .list-group-item { border-radius: 0 !important; }

/* ============================================================
   MODALS
   ============================================================ */
.modal-content {
  background: var(--s-card) !important;
  border: 1px solid var(--b-mid) !important;
  border-radius: var(--r-xl) !important;
  box-shadow: 0 20px 60px oklch(0 0 0 / 0.50) !important;
}
.modal-header {
  border-bottom: 1px solid var(--b-weak) !important;
  padding: 15px 20px !important;
}
.modal-body { padding: 18px 20px !important; }
.modal-footer {
  border-top: 1px solid var(--b-weak) !important;
  padding: 12px 20px !important;
}
.modal-title { font-size: 0.93rem !important; font-weight: 700 !important; color: var(--t-1) !important; }
.modal-backdrop { --bs-backdrop-bg: oklch(0 0 0 / 0.60) !important; }

/* ============================================================
   CODE / INLINE CODE
   ============================================================ */
code {
  font-size: 0.81rem;
  color: oklch(0.72 0.14 22) !important;
  background: oklch(0.59 0.22 22 / 0.10) !important;
  padding: 1px 5px; border-radius: 4px;
}
pre { background: var(--s-raise) !important; border-radius: var(--r-md); padding: 14px; }
pre code { background: transparent !important; }

/* ============================================================
   PAGINATION
   ============================================================ */
.page-link {
  background: var(--s-card) !important;
  border-color: var(--b-weak) !important;
  color: var(--t-2) !important;
  font-size: 0.80rem !important;
  transition: all 0.14s !important;
}
.page-link:hover { background: var(--s-raise) !important; color: var(--t-1) !important; border-color: var(--b-mid) !important; }
.page-item.active .page-link {
  background: var(--brand) !important; border-color: var(--brand) !important; color: #fff !important;
}
.page-item.disabled .page-link { opacity: 0.4; }

/* ============================================================
   DROPDOWNS
   ============================================================ */
.dropdown-menu {
  background: var(--s-card) !important;
  border: 1px solid var(--b-mid) !important;
  border-radius: var(--r-lg) !important;
  box-shadow: 0 8px 24px oklch(0 0 0 / 0.40) !important;
  padding: 5px !important;
}
.dropdown-item {
  color: var(--t-1) !important; border-radius: var(--r-sm) !important;
  font-size: 0.84rem !important; padding: 7px 12px !important;
}
.dropdown-item:hover { background: var(--s-raise) !important; }
.dropdown-divider { border-color: var(--b-weak) !important; }

/* ============================================================
   SELECT2
   ============================================================ */
.select2-container--bootstrap-5 .select2-selection {
  background: var(--s-input) !important;
  border-color: var(--b-weak) !important;
  border-radius: var(--r-md) !important;
  color: var(--t-1) !important;
  min-height: 36px !important;
}
.select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { color: var(--t-1) !important; }
.select2-dropdown {
  background: var(--s-card) !important;
  border-color: var(--b-mid) !important;
  border-radius: var(--r-lg) !important;
  box-shadow: 0 8px 24px oklch(0 0 0 / 0.40) !important;
}
.select2-results__option { font-size: 0.84rem !important; color: var(--t-1) !important; }
.select2-results__option--highlighted[aria-selected] {
  background: var(--brand-dim) !important; color: var(--t-1) !important;
}
.select2-search--dropdown .select2-search__field {
  background: var(--s-raise) !important;
  border-color: var(--b-weak) !important;
  color: var(--t-1) !important;
  border-radius: var(--r-sm) !important;
}

/* ============================================================
   DATATABLES
   ============================================================ */
.dataTables_wrapper .dataTables_length select,
.dataTables_wrapper .dataTables_filter input {
  background: var(--s-input) !important;
  border: 1px solid var(--b-weak) !important;
  color: var(--t-1) !important;
  border-radius: var(--r-md) !important;
  padding: 5px 9px;
  font-size: 0.80rem !important;
}
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_length label,
.dataTables_wrapper .dataTables_filter label {
  color: var(--t-2) !important; font-size: 0.78rem !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button {
  border-radius: var(--r-sm) !important;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 992px) {
  #sidebar { width: var(--sidebar-sm); }
  #main-content { margin-left: var(--sidebar-sm); }
  #sidebar .sb-brand-text,
  #sidebar .sb-section,
  #sidebar .sb-label { opacity: 0; width: 0; pointer-events: none; }
  #sidebar .sb-item a { padding: 8px 6px; justify-content: center; margin: 1px 5px; }
  #sidebar .sb-footer a { justify-content: center; }
  #sidebar .sb-footer a span { display: none; }
  .page-body { padding: 14px; }
  .topbar { padding: 0 14px; }
}
</style>
</head>
<body>

<!-- ============================================================
     SIDEBAR
     ============================================================ -->
<div id="sidebar">
  <div class="sb-brand">
    <div class="sb-brand-icon"><i class="fas fa-dragon"></i></div>
    <div class="sb-brand-text">
      <div class="sb-brand-name">繁笼后台</div>
      <div class="sb-brand-ver">管理系统 v<?php echo APP_VERSION; ?></div>
    </div>
  </div>

  <nav class="sb-nav" aria-label="主导航">
    <?php $cur = basename($_SERVER['PHP_SELF']); ?>

    <ul class="list-unstyled mb-0">
      <li class="sb-item">
        <a href="index.php" class="<?php echo $cur==='index.php'?'active':''; ?>">
          <i class="fas fa-gauge-high sb-icon"></i><span class="sb-label">仪表盘</span>
        </a>
      </li>
    </ul>

    <?php if(can('users')||can('stats')||can('user_bag')||can('user_equip')): ?>
    <div class="sb-section">用户数据</div>
    <ul class="list-unstyled mb-0">
      <?php if(can('users')): ?>
      <li class="sb-item"><a href="users.php" class="<?php echo $cur==='users.php'?'active':''; ?>">
        <i class="fas fa-users sb-icon"></i><span class="sb-label">用户列表</span></a></li>
      <?php endif; ?>
      <?php if(can('stats')): ?>
      <li class="sb-item"><a href="stats.php" class="<?php echo $cur==='stats.php'?'active':''; ?>">
        <i class="fas fa-chart-bar sb-icon"></i><span class="sb-label">属性管理</span></a></li>
      <?php endif; ?>
      <?php if(can('user_bag')): ?>
      <li class="sb-item"><a href="user_bag.php" class="<?php echo $cur==='user_bag.php'?'active':''; ?>">
        <i class="fas fa-bag-shopping sb-icon"></i><span class="sb-label">用户背包</span></a></li>
      <?php endif; ?>
      <?php if(can('user_equip')): ?>
      <li class="sb-item"><a href="equip.php" class="<?php echo $cur==='equip.php'?'active':''; ?>">
        <i class="fas fa-shirt sb-icon"></i><span class="sb-label">装备穿戴</span></a></li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if(can('items')||can('drama_archives')||can('history_stats')||can('item_instances')): ?>
    <div class="sb-section">游戏数据</div>
    <ul class="list-unstyled mb-0">
      <?php if(can('items')): ?>
      <li class="sb-item"><a href="items.php" class="<?php echo $cur==='items.php'?'active':''; ?>">
        <i class="fas fa-shop sb-icon"></i><span class="sb-label">商店管理</span></a></li>
      <?php endif; ?>
      <?php if(can('drama_archives')): ?>
      <li class="sb-item"><a href="drama_archives.php" class="<?php echo $cur==='drama_archives.php'?'active':''; ?>">
        <i class="fas fa-book-open sb-icon"></i><span class="sb-label">剧情档案</span></a></li>
      <?php endif; ?>
      <?php if(can('history_stats')): ?>
      <li class="sb-item"><a href="history_stats.php" class="<?php echo $cur==='history_stats.php'?'active':''; ?>">
        <i class="fas fa-chart-area sb-icon"></i><span class="sb-label">历史统计</span></a></li>
      <?php endif; ?>
      <?php if(can('item_instances')): ?>
      <li class="sb-item"><a href="item_instances.php" class="<?php echo $cur==='item_instances.php'?'active':''; ?>">
        <i class="fas fa-boxes-stacked sb-icon"></i><span class="sb-label">物品实例</span></a></li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if(can('game_config')||can('game_terms')||can('custom_replies')||can('system_vars')): ?>
    <div class="sb-section">系统配置</div>
    <ul class="list-unstyled mb-0">
      <?php if(can('game_config')): ?>
      <li class="sb-item"><a href="game_config.php" class="<?php echo $cur==='game_config.php'?'active':''; ?>">
        <i class="fas fa-sliders sb-icon"></i><span class="sb-label">游戏配置</span></a></li>
      <?php endif; ?>
      <?php if(can('game_terms')): ?>
      <li class="sb-item"><a href="terms.php" class="<?php echo $cur==='terms.php'?'active':''; ?>">
        <i class="fas fa-language sb-icon"></i><span class="sb-label">术语翻译</span></a></li>
      <?php endif; ?>
      <?php if(can('custom_replies')): ?>
      <li class="sb-item"><a href="custom_replies.php" class="<?php echo $cur==='custom_replies.php'?'active':''; ?>">
        <i class="fas fa-comments sb-icon"></i><span class="sb-label">自定义回复</span></a></li>
      <?php endif; ?>
      <?php if(can('system_vars')): ?>
      <li class="sb-item"><a href="system_vars.php" class="<?php echo $cur==='system_vars.php'?'active':''; ?>">
        <i class="fas fa-terminal sb-icon"></i><span class="sb-label">系统变量</span></a></li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if(can('tables')||can('backup')||can('broadcast','execute')): ?>
    <div class="sb-section">高级工具</div>
    <ul class="list-unstyled mb-0">
      <?php if(can('broadcast','execute')): ?>
      <li class="sb-item"><a href="broadcast.php" class="<?php echo $cur==='broadcast.php'?'active':''; ?>">
        <i class="fas fa-bullhorn sb-icon"></i><span class="sb-label">批量发送</span></a></li>
      <?php endif; ?>
      <?php if(can('tables')): ?>
      <li class="sb-item"><a href="tables.php" class="<?php echo $cur==='tables.php'?'active':''; ?>">
        <i class="fas fa-database sb-icon"></i><span class="sb-label">数据表浏览</span></a></li>
      <?php endif; ?>
      <?php if(can('backup','execute')): ?>
      <li class="sb-item"><a href="backup.php" class="<?php echo $cur==='backup.php'?'active':''; ?>">
        <i class="fas fa-floppy-disk sb-icon"></i><span class="sb-label">数据备份</span></a></li>
      <?php endif; ?>
      <?php if(isSuperAdmin()): ?>
      <li class="sb-item"><a href="cleanup.php" class="<?php echo $cur==='cleanup.php'?'active':''; ?>">
        <i class="fas fa-broom sb-icon"></i><span class="sb-label">数据清理</span></a></li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if(can('admins')||can('logs')): ?>
    <div class="sb-section">权限与日志</div>
    <ul class="list-unstyled mb-0">
      <?php if(can('admins')): ?>
      <li class="sb-item"><a href="admins.php" class="<?php echo $cur==='admins.php'?'active':''; ?>">
        <i class="fas fa-user-shield sb-icon"></i><span class="sb-label">管理员管理</span></a></li>
      <?php endif; ?>
      <?php if(can('logs')): ?>
      <li class="sb-item"><a href="login_logs.php" class="<?php echo $cur==='login_logs.php'?'active':''; ?>">
        <i class="fas fa-right-to-bracket sb-icon"></i><span class="sb-label">登录记录</span></a></li>
      <li class="sb-item"><a href="admin_logs.php" class="<?php echo $cur==='admin_logs.php'?'active':''; ?>">
        <i class="fas fa-clipboard-list sb-icon"></i><span class="sb-label">操作日志</span></a></li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>
  </nav>

  <div class="sb-footer">
    <a href="logout.php" onclick="return confirm('确认退出登录？')">
      <i class="fas fa-right-from-bracket sb-icon"></i><span>退出登录</span>
    </a>
  </div>
</div>

<!-- ============================================================
     MAIN CONTENT
     ============================================================ -->
<div id="main-content">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <h1 class="topbar-title">
        <?php if(isset($page_icon)): ?><i class="<?php echo $page_icon; ?> me-2" style="color:var(--brand);font-size:.95rem;"></i><?php endif; ?><?php echo htmlspecialchars($page_title ?? '管理面板'); ?>
      </h1>
      <?php if(isset($page_subtitle)): ?>
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="index.php">首页</a></li>
          <li class="breadcrumb-item active"><?php echo htmlspecialchars($page_subtitle); ?></li>
        </ol>
      </nav>
      <?php endif; ?>
    </div>
    <div class="topbar-right">
      <span class="d-flex align-items-center gap-2 me-1" style="font-size:.72rem;color:var(--t-3);">
        <span class="live-dot"></span>
        <span id="lastUpdate"><?php echo date('H:i:s'); ?></span>
      </span>
      <button class="tb-btn" id="themeToggle" title="切换主题">
        <i class="fas fa-moon" id="themeIcon"></i>
      </button>
      <a href="change_password.php" class="tb-btn" title="修改密码">
        <i class="fas fa-key"></i>
      </a>
      <div class="admin-chip d-none d-sm-flex">
        <div class="admin-avatar"><?php echo mb_substr($_SESSION['admin_id'] ?? 'A', 0, 1); ?></div>
        <div>
          <div class="admin-name"><?php echo htmlspecialchars($_SESSION['admin_nickname'] ?? $_SESSION['admin_id'] ?? ''); ?></div>
          <div class="admin-role"><?php echo isSuperAdmin() ? '超级管理员' : '管理员'; ?></div>
        </div>
      </div>
      <button class="tb-btn" id="sidebarToggle" title="折叠侧边栏">
        <i class="fas fa-bars"></i>
      </button>
    </div>
  </div>

  <!-- Flash -->
  <?php if($flash): ?>
  <div id="flashMsg" class="flash-area">
    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show mb-0">
      <i class="fas fa-<?php echo $flash['type']==='success'?'circle-check':($flash['type']==='danger'?'circle-exclamation':'info-circle'); ?> me-2"></i><?php echo htmlspecialchars($flash['message']); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Page content -->
  <div class="page-body">
