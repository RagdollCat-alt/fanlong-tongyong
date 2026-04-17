<?php
require_once 'config.php';
checkLogin();
requirePermission('item_instances','view');

$db = getDB();

// POST 操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    if ($pa === 'reset_currency') {
        requirePermission('item_instances','edit');
        $iid = intval($_POST['instance_id'] ?? 0);
        $old = $db->prepare("SELECT * FROM item_instances WHERE instance_id=?");
        $old->execute([$iid]); $old = $old->fetch();
        $db->prepare("UPDATE item_instances SET currency_given=0 WHERE instance_id=?")->execute([$iid]);
        logAction('item_instances','update',$iid,$old,['currency_given'=>0]);
        setFlash('success','已重置货币发放状态为「待发放」');
        header('Location: item_instances.php?' . http_build_query($_GET)); exit();
    }

    if ($pa === 'delete') {
        requirePermission('item_instances','delete');
        $iid = intval($_POST['instance_id'] ?? 0);
        $old = $db->prepare("SELECT * FROM item_instances WHERE instance_id=?");
        $old->execute([$iid]); $old = $old->fetch();
        $db->prepare("DELETE FROM item_instances WHERE instance_id=?")->execute([$iid]);
        logAction('item_instances','delete',$iid,$old);
        setFlash('success','实例已删除');
        header('Location: item_instances.php?' . http_build_query($_GET)); exit();
    }
}

// 筛选参数
$f_item   = trim($_GET['item']   ?? '');
$f_user   = trim($_GET['user']   ?? '');
$f_given  = $_GET['given'] ?? '';   // '' / '0' / '1'

$where = []; $params = [];
if ($f_item !== '')  { $where[] = "ii.item_name LIKE ?"; $params[] = "%$f_item%"; }
if ($f_user !== '')  { $where[] = "(u.name LIKE ? OR ii.user_id LIKE ?)"; $params[] = "%$f_user%"; $params[] = "%$f_user%"; }
if ($f_given !== '') { $where[] = "ii.currency_given=?"; $params[] = intval($f_given); }

$sql = "SELECT ii.*, u.name
        FROM item_instances ii
        LEFT JOIN users u ON u.id = ii.user_id
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY ii.instance_id DESC
        LIMIT 500";

$stmt = $db->prepare($sql); $stmt->execute($params);
$instances = $stmt->fetchAll();

$page_title='物品实例'; $page_icon='fas fa-boxes-stacked'; $page_subtitle='首次穿戴货币追踪';
require_once 'header.php';
?>

<div class="alert alert-info border-0 rounded-3 mb-4 py-2 small">
  <i class="fas fa-info-circle me-2"></i>
  每件装备从商店购买时生成一个实例，首次穿戴时标记 <code>currency_given=1</code> 并发放货币奖励。
  B端直接给背包添加装备时也会同步创建实例，确保首穿奖励正常触发。
  若状态异常，超级管理员可手动重置。
</div>

<!-- 筛选栏 -->
<form method="GET" class="card mb-4">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">物品名</label>
        <input type="text" class="form-control form-control-sm" name="item" value="<?php echo htmlspecialchars($f_item); ?>" placeholder="模糊搜索">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">用户（昵称/ID）</label>
        <input type="text" class="form-control form-control-sm" name="user" value="<?php echo htmlspecialchars($f_user); ?>" placeholder="模糊搜索">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">货币状态</label>
        <select class="form-select form-select-sm" name="given">
          <option value="" <?php echo $f_given===''?'selected':''; ?>>全部</option>
          <option value="0" <?php echo $f_given==='0'?'selected':''; ?>>待发放</option>
          <option value="1" <?php echo $f_given==='1'?'selected':''; ?>>已发放</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">筛选</button>
        <a href="item_instances.php" class="btn btn-sm btn-outline-secondary ms-1">重置</a>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list me-2"></i>实例列表（<?php echo count($instances); ?> 条，最多显示 500）</span>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover datatable align-middle mb-0 small">
      <thead><tr class="table-active">
        <th class="ps-4">实例ID</th>
        <th>物品名</th>
        <th>用户</th>
        <th>货币奖励状态</th>
        <th>创建时间</th>
        <th class="pe-4">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach($instances as $row): ?>
      <tr>
        <td class="ps-4 text-muted small">#<?php echo $row['instance_id']; ?></td>
        <td>
          <a href="items.php?search=<?php echo urlencode($row['item_name']); ?>" class="text-decoration-none fw-semibold">
            <?php echo htmlspecialchars($row['item_name']); ?>
          </a>
        </td>
        <td>
          <?php if($row['name']): ?>
          <a href="users.php?uid=<?php echo urlencode($row['user_id']); ?>" class="text-decoration-none">
            <?php echo htmlspecialchars($row['name']); ?>
          </a>
          <span class="text-muted small ms-1">(<?php echo htmlspecialchars($row['user_id']); ?>)</span>
          <?php else: ?>
          <span class="text-muted"><?php echo htmlspecialchars($row['user_id']); ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($row['currency_given']): ?>
          <span class="badge bg-success"><i class="fas fa-check me-1"></i>已发放</span>
          <?php else: ?>
          <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>待发放</span>
          <?php endif; ?>
        </td>
        <td class="text-muted small">
          <?php
          $ts = intval($row['created_at']);
          echo $ts > 0 ? date('Y-m-d H:i:s', $ts) : htmlspecialchars($row['created_at'] ?? '—');
          ?>
        </td>
        <td class="pe-4">
          <?php if(isSuperAdmin()): ?>
          <div class="d-flex gap-1">
            <?php if($row['currency_given']): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('将此实例的货币状态重置为「待发放」？下次穿戴时将重新触发奖励。')">
              <input type="hidden" name="action" value="reset_currency">
              <input type="hidden" name="instance_id" value="<?php echo $row['instance_id']; ?>">
              <?php foreach($_GET as $k=>$v): ?>
              <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
              <?php endforeach; ?>
              <button class="btn btn-sm btn-outline-warning py-0 px-2">重置</button>
            </form>
            <?php endif; ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('确认删除此实例？')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="instance_id" value="<?php echo $row['instance_id']; ?>">
              <?php foreach($_GET as $k=>$v): ?>
              <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
              <?php endforeach; ?>
              <button class="btn btn-sm btn-outline-danger py-0 px-2">删除</button>
            </form>
          </div>
          <?php else: ?>
          <span class="text-muted small">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once 'footer.php'; ?>
