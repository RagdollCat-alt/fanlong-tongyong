<?php
require_once 'config.php';
checkLogin();
requirePermission('system_vars','view');

$db = getDB();
$msg=''; $msg_type='';

// 自动升级：为 system_vars 添加 desc 列
try { $db->query("SELECT desc FROM system_vars LIMIT 1"); }
catch(Exception $e) { $db->exec("ALTER TABLE system_vars ADD COLUMN desc TEXT DEFAULT ''"); }

// 内置说明默认值（仅在 desc 为空时填入）
$VAR_DESCRIPTIONS = [
    'db_version'           => '数据库结构版本号，升级时自动递增，请勿手动修改',
    'init_done'            => '初始化完成标记（1 = 已完成首次建库）',
    'daily_reset_date'     => '上次每日重置执行日期（格式 YYYY-MM-DD），防止同一天重复重置',
    'last_daily_reset'     => '上次每日重置的完整时间戳',
    'broadcast_last_run'   => '上次定时广播任务执行时间',
    'shop_refresh_date'    => '上次商店刷新日期',
    'total_signed_today'   => '今日签到人数（每日重置清零）',
];
foreach ($VAR_DESCRIPTIONS as $k => $d) {
    $db->prepare("UPDATE system_vars SET desc=? WHERE key=? AND (desc IS NULL OR desc='')")->execute([$d, $k]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('system_vars','edit');
    $key   = trim($_POST['key']   ?? '');
    $value = trim($_POST['value'] ?? '');
    $desc  = trim($_POST['desc']  ?? '');
    if (!empty($key)) {
        $old=$db->prepare("SELECT value FROM system_vars WHERE key=?"); $old->execute([$key]); $old_val=$old->fetchColumn();
        $db->prepare("UPDATE system_vars SET value=?, desc=? WHERE key=?")->execute([$value, $desc, $key]);
        logAction('system_vars','update',$key,['value'=>$old_val],['value'=>$value]);
        setFlash('success','系统变量 '.$key.' 已更新');
        header('Location: system_vars.php'); exit();
    }
}

$vars = $db->query("SELECT key, value, COALESCE(desc,'') AS desc FROM system_vars ORDER BY key")->fetchAll();

$page_title='系统变量'; $page_icon='fas fa-terminal'; $page_subtitle='全局系统变量';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="alert alert-warning border-0 rounded-3 mb-4 small">
  <i class="fas fa-triangle-exclamation me-2"></i>系统变量由机器人运行时维护，通常无需手动修改。修改前请确认了解该变量的含义。
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-list me-2"></i>系统变量（<?php echo count($vars); ?> 项）</div>
  <div class="card-body p-0">
    <table class="table table-hover datatable align-middle mb-0">
      <thead><tr class="table-active">
        <th class="ps-4" style="width:28%">键名</th>
        <th style="width:25%">当前值</th>
        <th>说明</th>
        <th class="pe-4" style="width:100px">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach($vars as $v): ?>
      <tr>
        <td class="ps-4"><code><?php echo htmlspecialchars($v['key']); ?></code></td>
        <td>
          <span class="font-monospace small" title="<?php echo htmlspecialchars($v['value']); ?>">
            <?php echo htmlspecialchars(strlen($v['value'])>80?substr($v['value'],0,80).'...':$v['value']); ?>
          </span>
        </td>
        <td class="text-muted small"><?php echo $v['desc'] !== '' ? htmlspecialchars($v['desc']) : '<span class="text-muted opacity-50">—</span>'; ?></td>
        <td class="pe-4">
          <?php if(can('system_vars','edit')): ?>
          <button class="btn btn-sm btn-outline-primary py-0 px-2"
                  onclick="editVar('<?php echo htmlspecialchars($v['key'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($v['value'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($v['desc'],ENT_QUOTES); ?>')">
            编辑
          </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($vars)): ?>
      <tr><td colspan="4" class="text-center py-5 text-muted">暂无系统变量</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 编辑弹窗 -->
<div class="modal fade" id="varModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold">编辑系统变量</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold small">键名</label>
            <input type="text" class="form-control font-monospace" name="key" id="varKey" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">值</label>
            <textarea class="form-control font-monospace" name="value" id="varValue" rows="5"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">备注说明 <span class="text-muted fw-normal">（仅管理员可见，方便记录用途）</span></label>
            <input type="text" class="form-control" name="desc" id="varDesc" placeholder="可留空">
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-warning px-4"><i class="fas fa-save me-1"></i>保存修改</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function editVar(key, value, desc) {
  document.getElementById('varKey').value   = key;
  document.getElementById('varValue').value = value;
  document.getElementById('varDesc').value  = desc || '';
  new bootstrap.Modal(document.getElementById('varModal')).show();
}
</script>

<?php require_once 'footer.php'; ?>
