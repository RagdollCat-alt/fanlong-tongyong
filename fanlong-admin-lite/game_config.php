<?php
require_once 'config.php';
checkLogin();
requirePermission('game_config','view');

$db = getDB();
$msg=''; $msg_type='';

// ===== POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('game_config','edit');
    $pa = $_POST['action'] ?? '';

    if ($pa === 'save_single') {
        $key   = trim($_POST['key']   ?? '');
        $value = trim($_POST['value'] ?? '');
        $desc  = trim($_POST['desc']  ?? '');
        if (!empty($key)) {
            $old=$db->prepare("SELECT * FROM game_config WHERE key=?"); $old->execute([$key]); $old=$old->fetch();
            $db->prepare("INSERT OR REPLACE INTO game_config (key,value,`desc`) VALUES (?,?,?)")->execute([$key,$value,$desc]);
            logAction('game_config','update',$key,$old,['value'=>$value]);
            setFlash('success','配置 '.$key.' 已更新');
            header('Location: game_config.php'); exit();
        }
    }

    if ($pa === 'save_batch') {
        $keys   = $_POST['keys']   ?? [];
        $values = $_POST['values'] ?? [];
        $count  = 0;
        foreach($keys as $i=>$k) {
            if (empty($k)) continue;
            $v = $values[$i] ?? '';
            $old=$db->prepare("SELECT value FROM game_config WHERE key=?"); $old->execute([$k]); $old_val=$old->fetchColumn();
            if ($old_val !== $v) {
                $db->prepare("UPDATE game_config SET value=? WHERE key=?")->execute([$v,$k]);
                logAction('game_config','update',$k,['value'=>$old_val],['value'=>$v]);
                $count++;
            }
        }
        setFlash('success',"已更新 $count 项配置");
        header('Location: game_config.php'); exit();
    }

    if ($pa === 'delete') {
        if (!isSuperAdmin()){ setFlash('danger','仅超级管理员可删除配置'); header('Location: game_config.php'); exit(); }
        $key=$_POST['del_key']??'';
        $old=$db->prepare("SELECT * FROM game_config WHERE key=?"); $old->execute([$key]); $old=$old->fetch();
        $db->prepare("DELETE FROM game_config WHERE key=?")->execute([$key]);
        logAction('game_config','delete',$key,$old);
        setFlash('success','配置项已删除'); header('Location: game_config.php'); exit();
    }
}

// 配置分组（根据key前缀猜测）
$configs = $db->query("SELECT key, value, `desc` FROM game_config ORDER BY key")->fetchAll();
$groups = [];
foreach($configs as $c){
    $prefix = strstr($c['key'],'_',true) ?: 'other';
    $groups[$prefix][] = $c;
}

$page_title='游戏配置'; $page_icon='fas fa-sliders'; $page_subtitle='全局参数设置';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="text-muted small mb-0">共 <?php echo count($configs); ?> 项配置，修改后机器人执行「重载配置」即时生效</p>
  <div class="d-flex gap-2">
    <?php if(can('game_config','edit')): ?>
    <button class="btn btn-sm btn-outline-success" type="submit" form="batchForm"><i class="fas fa-save me-1"></i>批量保存</button>
    <?php endif; ?>
  </div>
</div>

<form id="batchForm" method="POST">
  <input type="hidden" name="action" value="save_batch">
  <?php foreach($groups as $group_name => $items): ?>
  <div class="card mb-4">
    <div class="card-header"><i class="fas fa-tag me-2"></i><?php echo htmlspecialchars($group_name); ?> 相关</div>
    <div class="card-body p-0">
      <table class="table table-hover align-middle mb-0">
        <thead><tr class="table-active">
          <th class="ps-4" style="width:30%">配置键</th>
          <th style="width:30%">当前值</th>
          <th>说明</th>
          <th class="pe-4" style="width:80px">操作</th>
        </tr></thead>
        <tbody>
        <?php foreach($items as $c): ?>
        <tr>
          <td class="ps-4"><code class="small"><?php echo htmlspecialchars($c['key']); ?></code>
            <input type="hidden" name="keys[]" value="<?php echo htmlspecialchars($c['key']); ?>">
          </td>
          <td>
            <?php if(can('game_config','edit')): ?>
            <input type="text" class="form-control form-control-sm font-monospace"
                   name="values[]" value="<?php echo htmlspecialchars($c['value']); ?>">
            <?php else: ?>
            <code><?php echo htmlspecialchars($c['value']); ?></code>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?php echo htmlspecialchars($c['desc'] ?? ''); ?></td>
          <td class="pe-4">
            <?php if(isSuperAdmin()): ?>
            <button type="button" class="btn btn-xs btn-sm btn-outline-danger py-0 px-2"
                    onclick="confirmDeleteConfig('<?php echo htmlspecialchars($c['key'],ENT_QUOTES); ?>')">删除</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
</form>

<!-- 独立删除表单 -->
<form id="deleteConfigForm" method="POST" style="display:none">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="del_key" id="deleteConfigKey" value="">
</form>

<!-- 删除确认弹窗 -->
<div class="modal fade" id="deleteConfigModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0 pb-1">
        <h6 class="modal-title fw-bold text-danger"><i class="fas fa-triangle-exclamation me-2"></i>确认删除</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2 small">
        确认删除配置 <strong id="deleteConfigModalKey"></strong>？<br>
        <span class="text-muted">此操作不可恢复。</span>
      </div>
      <div class="modal-footer border-0 pt-1">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-sm btn-danger" id="deleteConfigConfirmBtn">确认删除</button>
      </div>
    </div>
  </div>
</div>
<script>
function confirmDeleteConfig(key) {
  document.getElementById('deleteConfigModalKey').textContent = '「' + key + '」';
  document.getElementById('deleteConfigConfirmBtn').onclick = function() {
    document.getElementById('deleteConfigKey').value = key;
    document.getElementById('deleteConfigForm').submit();
  };
  new bootstrap.Modal(document.getElementById('deleteConfigModal')).show();
}
</script>

<?php require_once 'footer.php'; ?>
