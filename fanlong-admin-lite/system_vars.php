<?php
require_once 'config.php';
checkLogin();
requirePermission('system_vars','view');

$db = getDB();
$msg=''; $msg_type='';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('system_vars','edit');
    $key   = trim($_POST['key']   ?? '');
    $value = trim($_POST['value'] ?? '');
    if (!empty($key)) {
        $old=$db->prepare("SELECT value FROM system_vars WHERE key=?"); $old->execute([$key]); $old_val=$old->fetchColumn();
        $db->prepare("INSERT OR REPLACE INTO system_vars (key,value) VALUES (?,?)")->execute([$key,$value]);
        logAction('system_vars','update',$key,['value'=>$old_val],['value'=>$value]);
        setFlash('success','系统变量 '.$key.' 已更新');
        header('Location: system_vars.php'); exit();
    }
}

$vars = $db->query("SELECT key, value FROM system_vars ORDER BY key")->fetchAll();

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
        <th class="ps-4" style="width:35%">键名</th>
        <th>当前值</th><th class="pe-4" style="width:100px">操作</th>
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
        <td class="pe-4">
          <?php if(can('system_vars','edit')): ?>
          <button class="btn btn-sm btn-outline-primary py-0 px-2"
                  onclick="editVar('<?php echo htmlspecialchars($v['key'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($v['value'],ENT_QUOTES); ?>')">
            编辑
          </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($vars)): ?>
      <tr><td colspan="3" class="text-center py-5 text-muted">暂无系统变量</td></tr>
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
            <textarea class="form-control font-monospace" name="value" id="varValue" rows="6"></textarea>
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
function editVar(key, value) {
  document.getElementById('varKey').value   = key;
  document.getElementById('varValue').value = value;
  new bootstrap.Modal(document.getElementById('varModal')).show();
}
</script>

<?php require_once 'footer.php'; ?>
