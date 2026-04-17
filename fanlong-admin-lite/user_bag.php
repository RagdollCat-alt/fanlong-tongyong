<?php
require_once 'config.php';
checkLogin();
requirePermission('user_bag', 'view');

$db = getDB();
$msg = ''; $msg_type = '';

// ===== POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'update') {
        requirePermission('user_bag', 'edit');
        $user_id   = trim($_POST['user_id']   ?? '');
        $item_name = trim($_POST['item_name'] ?? '');
        $count     = max(0, intval($_POST['count'] ?? 0));
        try {
            $old = $db->prepare("SELECT * FROM user_bag WHERE user_id=? AND item_name=?");
            $old->execute([$user_id,$item_name]); $old=$old->fetch();
            // 查物品类型（用于同步 item_instances）
            $itype_r = $db->prepare("SELECT type FROM items WHERE name=?");
            $itype_r->execute([$item_name]);
            $itype = $itype_r->fetchColumn();
            if ($count === 0) {
                $db->prepare("DELETE FROM user_bag WHERE user_id=? AND item_name=?")->execute([$user_id,$item_name]);
                // 同步删除 item_instances（装备类）
                if ($itype === 'equip') {
                    $db->prepare("DELETE FROM item_instances WHERE item_name=? AND user_id=?")->execute([$item_name,$user_id]);
                }
                logAction('user_bag','delete',"$user_id/$item_name",$old);
                setFlash('success','物品已从背包移除');
            } else {
                $exists = $db->prepare("SELECT count FROM user_bag WHERE user_id=? AND item_name=?");
                $exists->execute([$user_id,$item_name]);
                $old_count_row = $exists->fetch();
                if ($old_count_row !== false) {
                    $old_count = intval($old_count_row['count']);
                    $db->prepare("UPDATE user_bag SET count=? WHERE user_id=? AND item_name=?")->execute([$count,$user_id,$item_name]);
                    // 数量增加时补充 item_instances（装备类）
                    if ($itype === 'equip' && $count > $old_count) {
                        $add = $count - $old_count;
                        $now = time();
                        for ($i = 0; $i < $add; $i++) {
                            $db->prepare("INSERT INTO item_instances (item_name,user_id,currency_given,created_at) VALUES (?,?,0,?)")
                               ->execute([$item_name,$user_id,$now]);
                        }
                    }
                    logAction('user_bag','update',"$user_id/$item_name",$old,['count'=>$count]);
                } else {
                    $db->prepare("INSERT INTO user_bag (user_id,item_name,count) VALUES (?,?,?)")->execute([$user_id,$item_name,$count]);
                    // 新增装备时同步写 item_instances
                    if ($itype === 'equip') {
                        $now = time();
                        for ($i = 0; $i < $count; $i++) {
                            $db->prepare("INSERT INTO item_instances (item_name,user_id,currency_given,created_at) VALUES (?,?,0,?)")
                               ->execute([$item_name,$user_id,$now]);
                        }
                    }
                    logAction('user_bag','create',"$user_id/$item_name",null,['count'=>$count]);
                }
                setFlash('success','背包已更新');
            }
            header('Location: user_bag.php'); exit();
        } catch(Exception $e){ $msg='操作失败：'.$e->getMessage(); $msg_type='danger'; }
    }

    if ($post_action === 'delete') {
        requirePermission('user_bag','delete');
        $user_id   = $_POST['user_id']   ?? '';
        $item_name = $_POST['item_name'] ?? '';
        $old = $db->prepare("SELECT * FROM user_bag WHERE user_id=? AND item_name=?");
        $old->execute([$user_id,$item_name]); $old=$old->fetch();
        // 查物品类型，同步清理 item_instances
        $itype_r = $db->prepare("SELECT type FROM items WHERE name=?");
        $itype_r->execute([$item_name]);
        $itype = $itype_r->fetchColumn();
        $db->prepare("DELETE FROM user_bag WHERE user_id=? AND item_name=?")->execute([$user_id,$item_name]);
        if ($itype === 'equip') {
            $db->prepare("DELETE FROM item_instances WHERE item_name=? AND user_id=?")->execute([$item_name,$user_id]);
        }
        logAction('user_bag','delete',"$user_id/$item_name",$old);
        setFlash('success','背包物品已删除');
        header('Location: user_bag.php'); exit();
    }
}

// 过滤查询
$filter_user = trim($_GET['user'] ?? '');
$filter_item = trim($_GET['item'] ?? '');

$sql = "SELECT b.user_id, u.name, b.item_name, b.count
        FROM user_bag b LEFT JOIN users u ON b.user_id=u.id WHERE 1=1";
$params = [];
if ($filter_user) { $sql .= " AND (b.user_id LIKE ? OR u.name LIKE ?)"; $params[]="%$filter_user%"; $params[]="%$filter_user%"; }
if ($filter_item) { $sql .= " AND b.item_name LIKE ?"; $params[]="%$filter_item%"; }
$sql .= " ORDER BY b.user_id, b.item_name";
$stmt = $db->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();

// 用于下拉的物品列表和用户列表
$items_list = $db->query("SELECT name FROM items ORDER BY name")->fetchAll(PDO::FETCH_COLUMN, 0);
$users_list = $db->query("SELECT id, name FROM users ORDER BY name")->fetchAll();

$page_title    = '背包管理';
$page_icon     = 'fas fa-bag-shopping';
$page_subtitle = '用户背包';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- 过滤 & 操作 -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label fw-semibold small">用户 QQ 号 / 角色名</label>
        <input type="text" class="form-control" name="user" value="<?php echo htmlspecialchars($filter_user); ?>" placeholder="搜索用户">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">物品名称</label>
        <input type="text" class="form-control" name="item" value="<?php echo htmlspecialchars($filter_item); ?>" placeholder="搜索物品">
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-search me-1"></i>搜索</button>
        <a href="user_bag.php" class="btn btn-outline-secondary">重置</a>
        <?php if(can('user_bag','edit')): ?>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addItemModal">
          <i class="fas fa-plus"></i>
        </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-list me-2"></i>共 <?php echo count($rows); ?> 条背包记录</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover datatable align-middle mb-0">
        <thead><tr class="table-active">
          <th class="ps-4">用户 QQ</th><th>角色名</th><th>物品名称</th>
          <th>数量</th><th class="pe-4">操作</th>
        </tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td class="ps-4"><code><?php echo htmlspecialchars($r['user_id']); ?></code></td>
          <td><?php echo htmlspecialchars($r['name']??'—'); ?></td>
          <td><?php echo htmlspecialchars($r['item_name']); ?></td>
          <td><span class="badge bg-primary rounded-pill"><?php echo $r['count']; ?></span></td>
          <td class="pe-4">
            <div class="d-flex gap-1">
              <?php if(can('user_bag','edit')): ?>
              <button class="btn btn-sm btn-outline-primary py-0 px-2"
                      onclick="openEditModal('<?php echo htmlspecialchars($r['user_id'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($r['item_name'],ENT_QUOTES); ?>',<?php echo $r['count']; ?>)">修改</button>
              <?php endif; ?>
              <?php if(can('user_bag','delete')): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('确认删除？')">
                <input type="hidden" name="action"    value="delete">
                <input type="hidden" name="user_id"   value="<?php echo htmlspecialchars($r['user_id']); ?>">
                <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($r['item_name']); ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-2">删除</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- 修改/新增弹窗 -->
<style>
/* 编辑模式：锁定字段卡片 */
.locked-fields {
  background: var(--s-raise, oklch(0.19 0.008 258));
  border: 1px solid var(--b-weak, oklch(0.22 0.008 258));
  border-radius: 10px;
  padding: 2px 0;
  overflow: hidden;
}
.locked-field-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 14px;
}
.locked-field-row + .locked-field-row {
  border-top: 1px solid var(--b-weak, oklch(0.22 0.008 258));
}
.locked-field-icon {
  width: 28px; height: 28px;
  border-radius: 7px;
  background: var(--brand-dim, oklch(0.59 0.22 22 / 0.13));
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  color: var(--brand, oklch(0.59 0.22 22));
  font-size: 0.75rem;
}
.locked-field-meta {
  flex: 1;
  min-width: 0;
}
.locked-field-label {
  font-size: 0.68rem;
  color: var(--t-2, oklch(0.58 0.006 258));
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 1px;
}
.locked-field-value {
  font-size: 0.84rem;
  color: var(--t-1, oklch(0.92 0.004 258));
  font-weight: 500;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.locked-badge {
  flex-shrink: 0;
  display: flex; align-items: center; gap: 4px;
  font-size: 0.68rem;
  color: var(--t-2, oklch(0.58 0.006 258));
  background: var(--b-weak, oklch(0.22 0.008 258));
  padding: 2px 7px; border-radius: 100px;
}
</style>

<div class="modal fade" id="addItemModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold" id="itemModalTitle">背包操作</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="update">
        <div class="modal-body">

          <!-- ① 编辑模式：锁定显示（不可更改用户/物品，通过隐藏域提交值） -->
          <div id="editLockDisplay" class="d-none mb-3">
            <div class="locked-fields">
              <div class="locked-field-row">
                <div class="locked-field-icon"><i class="fas fa-user"></i></div>
                <div class="locked-field-meta">
                  <div class="locked-field-label">用户</div>
                  <div class="locked-field-value" id="lockUserDisplay">—</div>
                </div>
                <div class="locked-badge"><i class="fas fa-lock"></i>锁定</div>
                <input type="hidden" name="user_id" id="hiddenEditUserId">
              </div>
              <div class="locked-field-row">
                <div class="locked-field-icon"><i class="fas fa-box"></i></div>
                <div class="locked-field-meta">
                  <div class="locked-field-label">物品</div>
                  <div class="locked-field-value" id="lockItemDisplay">—</div>
                </div>
                <div class="locked-badge"><i class="fas fa-lock"></i>锁定</div>
                <input type="hidden" name="item_name" id="hiddenEditItemName">
              </div>
            </div>
          </div>

          <!-- ② 新增模式：Select2 下拉选择 -->
          <div id="addSelectDisplay">
            <div class="mb-3">
              <label class="form-label fw-semibold small">选择用户</label>
              <select class="form-select select2" name="user_id" id="editUserId" required>
                <option value="">-- 请选择用户 --</option>
                <?php foreach($users_list as $u): ?>
                <option value="<?php echo htmlspecialchars($u['id']); ?>">
                  <?php echo htmlspecialchars($u['name']??$u['id']); ?> (<?php echo htmlspecialchars($u['id']); ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold small">选择物品</label>
              <select class="form-select select2" name="item_name" id="editItemName" required>
                <option value="">-- 请选择物品 --</option>
                <?php foreach($items_list as $il): ?>
                <option value="<?php echo htmlspecialchars($il); ?>"><?php echo htmlspecialchars($il); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold small">数量 <span class="text-muted">(填0则删除该物品)</span></label>
            <input type="number" class="form-control" name="count" id="editCount" required min="0" value="1">
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-primary btn-sm px-4">保存</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditModal(userId, itemName, count) {
  var sel1 = document.getElementById('editUserId');
  var sel2 = document.getElementById('editItemName');

  // 找到用户显示文本
  var userOption = Array.from(sel1.options).find(function(o){ return o.value === userId; });
  var userText   = userOption ? userOption.text : userId;

  // 填充锁定显示
  document.getElementById('lockUserDisplay').textContent  = userText;
  document.getElementById('lockItemDisplay').textContent  = itemName;
  document.getElementById('hiddenEditUserId').value       = userId;
  document.getElementById('hiddenEditItemName').value     = itemName;

  // 移除下拉框的 name，防止与隐藏域冲突提交
  sel1.removeAttribute('name');
  sel2.removeAttribute('name');

  // 切换到编辑模式 UI
  document.getElementById('editLockDisplay').classList.remove('d-none');
  document.getElementById('addSelectDisplay').classList.add('d-none');

  document.getElementById('editCount').value = count;
  document.getElementById('itemModalTitle').textContent = '修改背包物品';
  new bootstrap.Modal(document.getElementById('addItemModal')).show();
}

// 新增模式：恢复下拉选择
document.querySelector('[data-bs-target="#addItemModal"]')?.addEventListener('click', function(){
  var sel1 = document.getElementById('editUserId');
  var sel2 = document.getElementById('editItemName');

  // 恢复 name 属性
  sel1.setAttribute('name', 'user_id');
  sel2.setAttribute('name', 'item_name');
  sel1.value = ''; sel2.value = '';
  if(typeof $ !== 'undefined') { $(sel1).trigger('change'); $(sel2).trigger('change'); }

  // 切换到新增模式 UI
  document.getElementById('editLockDisplay').classList.add('d-none');
  document.getElementById('addSelectDisplay').classList.remove('d-none');

  document.getElementById('editCount').value = 1;
  document.getElementById('itemModalTitle').textContent = '新增背包物品';
});
</script>

<?php require_once 'footer.php'; ?>
