<?php
require_once 'config.php';
checkLogin();
requirePermission('user_equip', 'view');

$db = getDB();
$msg = ''; $msg_type = '';

$slot_fields = ['hair','top','bottom','head','neck','inner1','inner2','acc1','acc2','acc3','acc4'];

// 默认备用槽位名（先从 game_terms 读，否则用这里的）
$slot_fallback = [
    'hair'=>'发型','top'=>'上衣','bottom'=>'下装','head'=>'头饰','neck'=>'颈饰',
    'inner1'=>'内饰1','inner2'=>'内饰2','acc1'=>'配饰1','acc2'=>'配饰2','acc3'=>'配饰3','acc4'=>'配饰4'
];

// ===== POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('user_equip','edit');
    $user_id = trim($_POST['user_id'] ?? '');
    $slots = [];
    foreach ($slot_fields as $sf) $slots[$sf] = trim($_POST[$sf] ?? '');

    try {
        $old = $db->prepare("SELECT * FROM user_equip WHERE user_id=?"); $old->execute([$user_id]); $old=$old->fetch();
        if ($old) {
            $sets = implode(',', array_map(fn($f)=>"$f=?", $slot_fields));
            $db->prepare("UPDATE user_equip SET $sets WHERE user_id=?")->execute([...array_values($slots),$user_id]);
        } else {
            $cols = 'user_id,'.implode(',', $slot_fields);
            $ph   = '?,'.implode(',', array_fill(0,count($slot_fields),'?'));
            $db->prepare("INSERT INTO user_equip ($cols) VALUES ($ph)")->execute([$user_id,...array_values($slots)]);
        }
        logAction('user_equip','update',$user_id,$old,$slots);
        setFlash('success','装备已更新');
        header('Location: equip.php'); exit();
    } catch(Exception $e){ $msg='保存失败：'.$e->getMessage(); $msg_type='danger'; }
}

// 编辑
$edit_row = null;
$edit_uid = $_GET['user_id'] ?? '';
if (!empty($edit_uid)) {
    $r = $db->prepare("SELECT u.id,u.name,e.* FROM users u LEFT JOIN user_equip e ON u.id=e.user_id WHERE u.id=?");
    $r->execute([$edit_uid]); $edit_row=$r->fetch();
}

// 全部装备列表
$rows = $db->query("SELECT u.id, u.name, e.* FROM users u LEFT JOIN user_equip e ON u.id=e.user_id WHERE e.user_id IS NOT NULL ORDER BY u.name")->fetchAll();
// 没有装备记录的用户（用于新增）
$users_no_equip = $db->query("SELECT u.id, u.name FROM users u WHERE NOT EXISTS (SELECT 1 FROM user_equip e WHERE e.user_id=u.id) ORDER BY u.name")->fetchAll();

// 装备物品列表（type=equip，按机器人逻辑：accessory/interior 为分组槽）
$equip_items = $db->query("SELECT name,slot FROM items WHERE type='equip' AND slot!='' AND slot IS NOT NULL ORDER BY slot,name")->fetchAll();

// 槽位分组映射（user_equip 列名 → items.slot 对应值）
$slot_item_map = [
    'hair'   => ['hair'],
    'top'    => ['top'],
    'bottom' => ['bottom'],
    'head'   => ['head'],
    'neck'   => ['neck'],
    'inner1' => ['interior'],
    'inner2' => ['interior'],
    'acc1'   => ['accessory'],
    'acc2'   => ['accessory'],
    'acc3'   => ['accessory'],
    'acc4'   => ['accessory'],
];

$page_title    = '装备穿戴';
$page_icon     = 'fas fa-shirt';
$page_subtitle = '用户装备管理';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($edit_row): ?>
<!-- ===== 编辑装备 ===== -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-pen me-2"></i>编辑装备：<?php echo htmlspecialchars($edit_row['name']??$edit_row['id']); ?></span>
    <a href="equip.php" class="btn btn-sm btn-outline-secondary">返回</a>
  </div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($edit_row['id']); ?>">
      <div class="row g-3">
      <?php
      $_slot_vis = getSlotFieldsVisibility();
      foreach($slot_fields as $sf):
        $label = tSlot($sf, $slot_fallback[$sf] ?? $sf);
        $cur_val = $edit_row[$sf] ?? '';
        $_sv = $_slot_vis[$sf] ?? 1;
        // 该槽位的可选装备（按分组映射匹配 items.slot）
        $allowed_slots = $slot_item_map[$sf] ?? [$sf];
        $slot_items = array_filter($equip_items, fn($i)=>in_array($i['slot'], $allowed_slots));
      ?>
      <div class="col-md-4 <?php echo !$_sv ? 'opacity-50' : ''; ?>">
        <label class="form-label fw-semibold small d-flex align-items-center gap-1">
          <?php echo $label; ?>
          <?php if(!$_sv): ?>
          <span class="badge text-bg-warning" style="font-size:.65rem;font-weight:500">对玩家隐藏</span>
          <?php endif; ?>
        </label>
        <select class="form-select" name="<?php echo $sf; ?>">
          <option value="">(未装备)</option>
          <?php foreach($slot_items as $si): ?>
          <option value="<?php echo htmlspecialchars($si['name']); ?>" <?php echo $cur_val===$si['name']?'selected':''; ?>>
            <?php echo htmlspecialchars($si['name']); ?>
          </option>
          <?php endforeach; ?>
          <?php if(!empty($cur_val) && !in_array($cur_val, array_column($equip_items,'name'))): ?>
          <option value="<?php echo htmlspecialchars($cur_val); ?>" selected><?php echo htmlspecialchars($cur_val); ?> (手动)</option>
          <?php endif; ?>
        </select>
        <?php if(!empty($cur_val)): ?>
        <?php
          // 查询首穿货币奖励状态
          $inst_r = $db->prepare("SELECT currency_given FROM item_instances WHERE item_name=? AND user_id=? ORDER BY currency_given ASC LIMIT 1");
          $inst_r->execute([$cur_val, $edit_row['id']]);
          $inst = $inst_r->fetch();
        ?>
        <div class="form-text">
          当前：<?php echo htmlspecialchars($cur_val); ?>
          <?php if ($inst !== false): ?>
            <?php if ($inst['currency_given']): ?>
            <span class="badge bg-success ms-1 small">首穿已发放</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark ms-1 small">首穿待发放</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="badge bg-secondary ms-1 small">无实例记录</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-2"></i>保存</button>
        <a href="equip.php" class="btn btn-outline-secondary">取消</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ===== 装备列表 ===== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list me-2"></i>用户装备总览</span>
    <?php if(can('user_equip','edit') && !empty($users_no_equip)): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addEquipModal">
      <i class="fas fa-plus me-1"></i>为用户新增装备记录
    </button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover datatable align-middle mb-0">
        <thead><tr class="table-active">
          <th class="ps-4">用户</th>
          <?php
          $_slot_vis2 = getSlotFieldsVisibility();
          foreach($slot_fields as $sf):
            $_sv2 = $_slot_vis2[$sf] ?? 1;
          ?>
          <th class="<?php echo !$_sv2 ? 'opacity-50' : ''; ?>">
            <?php echo tSlot($sf,$slot_fallback[$sf]??$sf); ?>
            <?php if(!$_sv2): ?><span class="badge text-bg-warning ms-1" style="font-size:.6rem">隐</span><?php endif; ?>
          </th>
          <?php endforeach; ?>
          <th class="pe-4">操作</th>
        </tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td class="ps-4">
            <div class="fw-semibold"><?php echo htmlspecialchars($r['name']??'—'); ?></div>
            <div class="text-muted small"><?php echo htmlspecialchars($r['id']); ?></div>
          </td>
          <?php foreach($slot_fields as $sf): ?>
          <td>
            <?php if(!empty($r[$sf])): ?>
            <span class="badge bg-body-secondary text-body border small"><?php echo htmlspecialchars($r[$sf]); ?></span>
            <?php else: ?>
            <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td class="pe-4">
            <?php if(can('user_equip','edit')): ?>
            <a href="equip.php?user_id=<?php echo urlencode($r['id']); ?>" class="btn btn-sm btn-outline-primary py-0 px-2">编辑</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if(can('user_equip','edit') && !empty($users_no_equip)): ?>
<!-- 新增装备弹窗 -->
<div class="modal fade" id="addEquipModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold">为用户创建装备记录</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">选择一个尚无装备记录的用户，系统将创建空白记录后跳转编辑页面。</p>
        <label class="form-label fw-semibold small">选择用户</label>
        <select class="form-select select2" id="newEquipUser">
          <option value="">-- 请选择 --</option>
          <?php foreach($users_no_equip as $u): ?>
          <option value="<?php echo urlencode($u['id']); ?>">
            <?php echo htmlspecialchars($u['name']??$u['id']); ?> (<?php echo htmlspecialchars($u['id']); ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="goEditEquip()">前往编辑</button>
      </div>
    </div>
  </div>
</div>
<script>
function goEditEquip(){
  var v = document.getElementById('newEquipUser').value;
  if(!v){ alert('请先选择用户'); return; }
  window.location.href = 'equip.php?user_id=' + v;
}
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
