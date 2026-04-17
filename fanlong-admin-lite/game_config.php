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
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addConfigModal"><i class="fas fa-plus me-1"></i>新增配置</button>
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

<!-- 新增配置弹窗 -->
<div class="modal fade" id="addConfigModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold">新增配置项</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="save_single">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold small">配置键 *</label>
            <input type="text" class="form-control font-monospace" name="key" required placeholder="如：daily_train_limit">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">值</label>
            <input type="text" class="form-control font-monospace" name="value" placeholder="配置值">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">说明</label>
            <input type="text" class="form-control" name="desc" placeholder="配置说明（选填）">
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-primary px-4">保存</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- 独立删除表单（不嵌套在 batchForm 内，避免 HTML 嵌套表单问题）-->
<form id="deleteConfigForm" method="POST" style="display:none">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="del_key" id="deleteConfigKey" value="">
</form>
<script>
function confirmDeleteConfig(key) {
  if (confirm('确认删除配置「' + key + '」？')) {
    document.getElementById('deleteConfigKey').value = key;
    document.getElementById('deleteConfigForm').submit();
  }
}
</script>

<?php require_once 'footer.php'; ?>
