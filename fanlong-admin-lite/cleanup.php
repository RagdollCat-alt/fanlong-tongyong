<?php
require_once 'config.php';
checkLogin();
if (!isSuperAdmin()) { include '403.php'; exit(); }

$db  = getDB();
$msg = ''; $msg_type = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';

    if ($op === 'orphan_stats') {
        // 删除没有对应用户的 user_stats 记录
        $cnt = $db->query("SELECT COUNT(*) FROM user_stats WHERE user_id NOT IN (SELECT id FROM users)")->fetchColumn();
        $db->exec("DELETE FROM user_stats WHERE user_id NOT IN (SELECT id FROM users)");
        logAction('cleanup','execute','orphan_stats',['count'=>$cnt]);
        $results[]=['type'=>'success','msg'=>"已删除 $cnt 条孤立属性记录"];
    }

    if ($op === 'orphan_bag') {
        $cnt = $db->query("SELECT COUNT(*) FROM user_bag WHERE user_id NOT IN (SELECT id FROM users)")->fetchColumn();
        $db->exec("DELETE FROM user_bag WHERE user_id NOT IN (SELECT id FROM users)");
        logAction('cleanup','execute','orphan_bag',['count'=>$cnt]);
        $results[]=['type'=>'success','msg'=>"已删除 $cnt 条孤立背包记录"];
    }

    if ($op === 'orphan_equip') {
        $cnt = $db->query("SELECT COUNT(*) FROM user_equip WHERE user_id NOT IN (SELECT id FROM users)")->fetchColumn();
        $db->exec("DELETE FROM user_equip WHERE user_id NOT IN (SELECT id FROM users)");
        logAction('cleanup','execute','orphan_equip',['count'=>$cnt]);
        $results[]=['type'=>'success','msg'=>"已删除 $cnt 条孤立装备记录"];
    }

    if ($op === 'old_logs') {
        $days = max(7, intval($_POST['log_days'] ?? 30));
        $s=$db->query("SELECT COUNT(*) FROM admin_logs WHERE created_at < datetime('now','-$days days')"); $cnt=$s->fetchColumn();
        $db->exec("DELETE FROM admin_logs WHERE created_at < datetime('now','-$days days')");
        logAction('cleanup','execute','old_logs',['days'=>$days,'count'=>$cnt]);
        $results[]=['type'=>'success','msg'=>"已清理 $days 天前的 $cnt 条操作日志"];
    }

    if ($op === 'old_login_logs') {
        $days = max(7, intval($_POST['login_log_days'] ?? 30));
        $s=$db->query("SELECT COUNT(*) FROM login_logs WHERE created_at < datetime('now','-$days days')"); $cnt=$s->fetchColumn();
        $db->exec("DELETE FROM login_logs WHERE created_at < datetime('now','-$days days')");
        logAction('cleanup','execute','old_login_logs',['days'=>$days,'count'=>$cnt]);
        $results[]=['type'=>'success','msg'=>"已清理 $days 天前的 $cnt 条登录日志"];
    }

    if ($op === 'vacuum') {
        $before = file_exists(DB_PATH)?filesize(DB_PATH):0;
        $db->exec("VACUUM");
        $after  = file_exists(DB_PATH)?filesize(DB_PATH):0;
        logAction('cleanup','execute','vacuum');
        $results[]=['type'=>'success','msg'=>"VACUUM 完成，释放空间：".formatBytes($before-$after)];
    }

    if ($op === 'zero_bag') {
        $cnt = $db->query("SELECT COUNT(*) FROM user_bag WHERE count <= 0")->fetchColumn();
        $db->exec("DELETE FROM user_bag WHERE count <= 0");
        logAction('cleanup','execute','zero_bag',['count'=>$cnt]);
        $results[]=['type'=>'success','msg'=>"已删除 $cnt 条数量为0的背包记录"];
    }
}

// 孤立数据统计
$stats = [];
try {
    $stats['orphan_stats']  = $db->query("SELECT COUNT(*) FROM user_stats WHERE user_id NOT IN (SELECT id FROM users)")->fetchColumn();
    $stats['orphan_bag']    = $db->query("SELECT COUNT(*) FROM user_bag WHERE user_id NOT IN (SELECT id FROM users)")->fetchColumn();
    $stats['orphan_equip']  = $db->query("SELECT COUNT(*) FROM user_equip WHERE user_id NOT IN (SELECT id FROM users)")->fetchColumn();
    $stats['zero_bag']      = $db->query("SELECT COUNT(*) FROM user_bag WHERE count <= 0")->fetchColumn();
    $stats['log_count']     = $db->query("SELECT COUNT(*) FROM admin_logs")->fetchColumn();
    $stats['login_log_cnt'] = $db->query("SELECT COUNT(*) FROM login_logs")->fetchColumn();
    $stats['db_size']       = file_exists(DB_PATH)?filesize(DB_PATH):0;
} catch(Exception $e){ $stats=array_fill_keys(['orphan_stats','orphan_bag','orphan_equip','zero_bag','log_count','login_log_cnt','db_size'],0); }

$page_title='数据清理'; $page_icon='fas fa-broom'; $page_subtitle='数据库维护（仅超管可用）';
require_once 'header.php';
?>

<?php foreach($results as $r): ?>
<div class="alert alert-<?php echo $r['type']; ?> border-0 rounded-3 alert-dismissible">
  <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($r['msg']); ?>
  <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<div class="alert alert-warning border-0 rounded-3 mb-4">
  <i class="fas fa-triangle-exclamation me-2"></i><strong>操作不可撤销，建议先进行数据备份！</strong>
</div>

<div class="row g-4">
  <!-- 孤立数据清理 -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><i class="fas fa-trash-can me-2 text-danger"></i>孤立数据清理</div>
      <div class="card-body">
        <p class="text-muted small mb-3">清理没有对应用户档案的数据（用户被删除后遗留）</p>

        <div class="list-group list-group-flush">
          <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold small">孤立属性记录</div>
              <div class="text-muted" style="font-size:.75rem">user_stats 中无对应 users 记录</div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge <?php echo $stats['orphan_stats']>0?'bg-danger':'bg-success'; ?> rounded-pill"><?php echo $stats['orphan_stats']; ?></span>
              <?php if($stats['orphan_stats']>0): ?>
              <form method="POST" onsubmit="return confirm('确认清理？')"><input type="hidden" name="op" value="orphan_stats"><button class="btn btn-sm btn-danger py-0 px-2">清理</button></form>
              <?php endif; ?>
            </div>
          </div>
          <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold small">孤立背包记录</div>
              <div class="text-muted" style="font-size:.75rem">user_bag 中无对应 users 记录</div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge <?php echo $stats['orphan_bag']>0?'bg-danger':'bg-success'; ?> rounded-pill"><?php echo $stats['orphan_bag']; ?></span>
              <?php if($stats['orphan_bag']>0): ?>
              <form method="POST" onsubmit="return confirm('确认清理？')"><input type="hidden" name="op" value="orphan_bag"><button class="btn btn-sm btn-danger py-0 px-2">清理</button></form>
              <?php endif; ?>
            </div>
          </div>
          <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold small">孤立装备记录</div>
              <div class="text-muted" style="font-size:.75rem">user_equip 中无对应 users 记录</div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge <?php echo $stats['orphan_equip']>0?'bg-danger':'bg-success'; ?> rounded-pill"><?php echo $stats['orphan_equip']; ?></span>
              <?php if($stats['orphan_equip']>0): ?>
              <form method="POST" onsubmit="return confirm('确认清理？')"><input type="hidden" name="op" value="orphan_equip"><button class="btn btn-sm btn-danger py-0 px-2">清理</button></form>
              <?php endif; ?>
            </div>
          </div>
          <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold small">数量为0的背包物品</div>
              <div class="text-muted" style="font-size:.75rem">count <= 0 的背包记录</div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge <?php echo $stats['zero_bag']>0?'bg-warning text-dark':'bg-success'; ?> rounded-pill"><?php echo $stats['zero_bag']; ?></span>
              <?php if($stats['zero_bag']>0): ?>
              <form method="POST" onsubmit="return confirm('确认清理？')"><input type="hidden" name="op" value="zero_bag"><button class="btn btn-sm btn-warning text-dark py-0 px-2">清理</button></form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <!-- 日志清理 -->
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-clock-rotate-left me-2 text-warning"></i>日志清理</div>
      <div class="card-body">
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div><div class="fw-semibold small">操作日志</div><div class="text-muted" style="font-size:.75rem">当前 <?php echo $stats['log_count']; ?> 条</div></div>
          </div>
          <form method="POST" class="d-flex gap-2" onsubmit="return confirm('确认清理？')">
            <input type="hidden" name="op" value="old_logs">
            <div class="input-group input-group-sm">
              <span class="input-group-text">保留最近</span>
              <input type="number" class="form-control" name="log_days" value="30" min="7">
              <span class="input-group-text">天</span>
            </div>
            <button class="btn btn-sm btn-warning text-dark text-nowrap">清理旧日志</button>
          </form>
        </div>
        <div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div><div class="fw-semibold small">登录日志</div><div class="text-muted" style="font-size:.75rem">当前 <?php echo $stats['login_log_cnt']; ?> 条</div></div>
          </div>
          <form method="POST" class="d-flex gap-2" onsubmit="return confirm('确认清理？')">
            <input type="hidden" name="op" value="old_login_logs">
            <div class="input-group input-group-sm">
              <span class="input-group-text">保留最近</span>
              <input type="number" class="form-control" name="login_log_days" value="30" min="7">
              <span class="input-group-text">天</span>
            </div>
            <button class="btn btn-sm btn-warning text-dark text-nowrap">清理旧日志</button>
          </form>
        </div>
      </div>
    </div>

    <!-- VACUUM -->
    <div class="card">
      <div class="card-header"><i class="fas fa-compress-alt me-2 text-info"></i>数据库碎片整理</div>
      <div class="card-body">
        <p class="text-muted small">执行 VACUUM 命令，回收删除数据占用的空间，整理数据库碎片。</p>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <span class="small text-muted">当前大小</span>
          <span class="fw-semibold"><?php echo formatBytes($stats['db_size']); ?></span>
        </div>
        <form method="POST" onsubmit="return confirm('执行VACUUM可能需要一些时间，确认？')">
          <input type="hidden" name="op" value="vacuum">
          <button class="btn btn-info text-white w-100"><i class="fas fa-compress-alt me-2"></i>执行 VACUUM</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>
