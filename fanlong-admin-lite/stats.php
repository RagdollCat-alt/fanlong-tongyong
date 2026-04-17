<?php
require_once 'config.php';
checkLogin();
requirePermission('stats', 'view');

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$uid    = $_GET['user_id'] ?? '';
$msg = ''; $msg_type = '';

$stat_fields = ['stat_face','stat_charm','stat_intel','stat_biz','stat_talk','stat_body','stat_art','stat_obed'];

// ===== POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('stats', 'edit');
    $user_id = $_POST['user_id'] ?? '';
    $vals = [];
    foreach ($stat_fields as $f) $vals[$f] = max(0, intval($_POST[$f] ?? 0));

    try {
        $old = $db->prepare("SELECT * FROM user_stats WHERE user_id=?"); $old->execute([$user_id]); $old=$old->fetch();
        if ($old) {
            $sets = implode(',', array_map(fn($f)=>"$f=?", $stat_fields));
            $db->prepare("UPDATE user_stats SET $sets WHERE user_id=?")
               ->execute([...array_values($vals), $user_id]);
        } else {
            $cols = implode(',', $stat_fields);
            $ph   = implode(',', array_fill(0, count($stat_fields), '?'));
            $db->prepare("INSERT INTO user_stats (user_id,$cols) VALUES (?,$ph)")
               ->execute([$user_id, ...array_values($vals)]);
        }
        logAction('stats','update',$user_id,$old,$vals);
        setFlash('success','属性已更新');
        header('Location: stats.php'); exit();
    } catch(Exception $e){ $msg='保存失败：'.$e->getMessage(); $msg_type='danger'; }
}

// 所有属性数据
$rows = $db->query("SELECT u.id, u.name, s.* FROM users u JOIN user_stats s ON u.id=s.user_id ORDER BY s.stat_face DESC")->fetchAll();
// 没有属性记录的用户
$users_no_stats = $db->query("SELECT u.id, u.name FROM users u WHERE NOT EXISTS (SELECT 1 FROM user_stats s WHERE s.user_id=u.id) ORDER BY u.name")->fetchAll();

// 编辑单用户
$edit_row = null;
if ($action === 'edit' && !empty($uid)) {
    $r = $db->prepare("SELECT u.id,u.name,s.* FROM users u LEFT JOIN user_stats s ON u.id=s.user_id WHERE u.id=?");
    $r->execute([$uid]); $edit_row = $r->fetch();
}

// 统计
$avg = $db->query("SELECT " . implode(',',array_map(fn($f)=>"AVG($f) AS avg_$f, MAX($f) AS max_$f",$stat_fields)) . " FROM user_stats")->fetch();

$page_title    = '属性管理';
$page_icon     = 'fas fa-chart-bar';
$page_subtitle = '八维属性';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- 属性均值统计 -->
<div class="row g-3 mb-4">
<?php foreach($stat_fields as $sf):
  $avg_val = round($avg["avg_$sf"]??0,1);
  $max_val = intval($avg["max_$sf"]??0);
  $color_map=['stat_face'=>'#ec4899','stat_charm'=>'#f59e0b','stat_intel'=>'#3b82f6','stat_biz'=>'#22c55e','stat_talk'=>'#8b5cf6','stat_body'=>'#ef4444','stat_art'=>'#14b8a6','stat_obed'=>'#6b7280'];
  $c=$color_map[$sf]??'#667eea';
?>
<div class="col-lg-3 col-sm-6">
  <div class="card">
    <div class="card-body py-3 px-4">
      <div class="d-flex align-items-center gap-3">
        <div style="width:40px;height:40px;border-radius:10px;background:<?php echo $c; ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="fas fa-star" style="color:<?php echo $c; ?>"></i>
        </div>
        <div>
          <div class="fw-bold"><?php echo t($sf,$sf); ?></div>
          <div class="small text-muted">均值 <span class="fw-semibold" style="color:<?php echo $c; ?>"><?php echo $avg_val; ?></span> · 最高 <?php echo $max_val; ?></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php if ($edit_row): ?>
<!-- ===== 编辑属性 ===== -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-pen me-2"></i>编辑属性：<?php echo htmlspecialchars($edit_row['name']??$edit_row['id']); ?></span>
    <a href="stats.php" class="btn btn-sm btn-outline-secondary">返回</a>
  </div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($edit_row['id']); ?>">
      <div class="row g-3">
        <?php foreach($stat_fields as $sf): $val=intval($edit_row[$sf]??0); ?>
        <div class="col-md-3">
          <label class="form-label fw-semibold small"><?php echo t($sf,$sf); ?></label>
          <input type="number" class="form-control" name="<?php echo $sf; ?>"
                 value="<?php echo $val; ?>" min="0" max="9999">
        </div>
        <?php endforeach; ?>
      </div>
      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-2"></i>保存</button>
        <a href="stats.php" class="btn btn-outline-secondary">取消</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ===== 全部属性列表 ===== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-table me-2"></i>所有用户属性</span>
    <?php if(can('stats','edit') && !empty($users_no_stats)): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addStatsModal">
      <i class="fas fa-plus me-1"></i>为用户新增属性记录
    </button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover datatable align-middle mb-0">
        <thead><tr class="table-active">
          <th class="ps-4">用户</th>
          <?php foreach($stat_fields as $sf): ?><th><?php echo t($sf,$sf); ?></th><?php endforeach; ?>
          <th>合计</th><th class="pe-4">操作</th>
        </tr></thead>
        <tbody>
        <?php foreach($rows as $r):
          $total=0; foreach($stat_fields as $sf) $total+=intval($r[$sf]??0);
        ?>
        <tr>
          <td class="ps-4">
            <div class="fw-semibold"><?php echo htmlspecialchars($r['name']??'—'); ?></div>
            <div class="text-muted small"><?php echo htmlspecialchars($r['id']); ?></div>
          </td>
          <?php foreach($stat_fields as $sf): $v=intval($r[$sf]??0); ?>
          <td><span class="badge rounded-pill bg-body-secondary text-body border"><?php echo $v; ?></span></td>
          <?php endforeach; ?>
          <td><span class="fw-bold" style="color:#667eea"><?php echo $total; ?></span></td>
          <td class="pe-4">
            <?php if(can('stats','edit')): ?>
            <a href="stats.php?action=edit&user_id=<?php echo urlencode($r['id']); ?>" class="btn btn-sm btn-outline-primary py-0 px-2">编辑</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if(can('stats','edit') && !empty($users_no_stats)): ?>
<!-- 新增属性弹窗 -->
<div class="modal fade" id="addStatsModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold">为用户创建属性记录</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">选择一个尚无属性记录的用户，系统将创建全零属性后跳转编辑页面。</p>
        <label class="form-label fw-semibold small">选择用户</label>
        <select class="form-select select2" id="newStatsUser">
          <option value="">-- 请选择 --</option>
          <?php foreach($users_no_stats as $u): ?>
          <option value="<?php echo urlencode($u['id']); ?>">
            <?php echo htmlspecialchars($u['name']??$u['id']); ?> (<?php echo htmlspecialchars($u['id']); ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="goEditStats()">前往编辑</button>
      </div>
    </div>
  </div>
</div>
<script>
function goEditStats(){
  var v = document.getElementById('newStatsUser').value;
  if(!v){ alert('请先选择用户'); return; }
  window.location.href = 'stats.php?action=edit&user_id=' + v;
}
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
