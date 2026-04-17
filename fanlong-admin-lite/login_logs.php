<?php
require_once 'config.php';
checkLogin();
requirePermission('logs','view');

$db = getDB();

$filter_user = trim($_GET['user'] ?? '');
$filter_date = trim($_GET['date'] ?? '');
$page        = max(1, intval($_GET['p'] ?? 1));
$page_size   = 50;

$sql = "SELECT l.*, u.name FROM login_logs l LEFT JOIN users u ON l.user_id=u.id WHERE 1=1";
$params = [];
if ($filter_user) { $sql .= " AND (l.user_id LIKE ? OR u.name LIKE ?)"; $params[] = "%$filter_user%"; $params[] = "%$filter_user%"; }
if ($filter_date) { $sql .= " AND DATE(l.created_at)=?"; $params[] = $filter_date; }

$cnt_stmt = $db->prepare(str_replace("SELECT l.*, u.name","SELECT COUNT(*)",$sql));
$cnt_stmt->execute($params); $total = $cnt_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $page_size));

$sql .= " ORDER BY l.id DESC LIMIT $page_size OFFSET " . (($page-1)*$page_size);
$stmt = $db->prepare($sql); $stmt->execute($params);
$logs = $stmt->fetchAll();

// 今日统计
$today = date('Y-m-d');
$today_cnt = $db->prepare("SELECT COUNT(*) FROM login_logs WHERE DATE(created_at)=?"); $today_cnt->execute([$today]); $today_cnt=$today_cnt->fetchColumn();
$total_cnt  = $db->query("SELECT COUNT(*) FROM login_logs")->fetchColumn();
$unique_adm = $db->query("SELECT COUNT(DISTINCT user_id) FROM login_logs")->fetchColumn();

$page_title='登录记录'; $page_icon='fas fa-right-to-bracket'; $page_subtitle='管理员登录历史';
require_once 'header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-primary"><?php echo $total_cnt; ?></div>
      <div class="text-muted small">总登录次数</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-success"><?php echo $today_cnt; ?></div>
      <div class="text-muted small">今日登录次数</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-warning"><?php echo $unique_adm; ?></div>
      <div class="text-muted small">不同登录账号</div>
    </div></div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label fw-semibold small">用户 QQ / 角色名</label>
        <input type="text" class="form-control form-control-sm" name="user" value="<?php echo htmlspecialchars($filter_user); ?>" placeholder="搜索">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold small">日期</label>
        <input type="date" class="form-control form-control-sm" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
      </div>
      <div class="col-md d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>查询</button>
        <a href="login_logs.php" class="btn btn-sm btn-outline-secondary">重置</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list me-2"></i>共 <?php echo number_format($total); ?> 条记录</span>
    <span class="text-muted small">第 <?php echo $page; ?>/<?php echo $total_pages; ?> 页</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead><tr class="table-active">
          <th class="ps-4">管理员 QQ</th><th>角色名</th><th>IP 地址</th>
          <th>浏览器</th><th class="pe-4">时间</th>
        </tr></thead>
        <tbody>
        <?php foreach($logs as $log): ?>
        <tr>
          <td class="ps-4"><code><?php echo htmlspecialchars($log['user_id']); ?></code></td>
          <td><?php echo htmlspecialchars($log['name'] ?? '—'); ?></td>
          <td><code class="small"><?php echo htmlspecialchars($log['ip'] ?? '—'); ?></code></td>
          <td>
            <span class="text-muted d-inline-block text-truncate" style="max-width:250px" title="<?php echo htmlspecialchars($log['user_agent']??''); ?>">
              <?php
              $ua = $log['user_agent'] ?? '';
              // 简化UA显示
              if (strpos($ua,'Chrome')!==false && strpos($ua,'Edg')===false) echo 'Chrome';
              elseif(strpos($ua,'Edg')!==false) echo 'Edge';
              elseif(strpos($ua,'Firefox')!==false) echo 'Firefox';
              elseif(strpos($ua,'Safari')!==false) echo 'Safari';
              else echo htmlspecialchars(substr($ua,0,40));
              ?>
            </span>
          </td>
          <td class="pe-4 text-muted"><?php echo htmlspecialchars($log['created_at'] ?? ''); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($logs)): ?>
        <tr><td colspan="5" class="text-center py-5 text-muted">暂无登录记录</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if($total_pages > 1): ?>
  <div class="card-footer d-flex justify-content-center">
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php if($page > 1): ?><li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$page-1])); ?>">上一页</a></li><?php endif; ?>
        <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
        <li class="page-item <?php echo $i===$page?'active':''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$i])); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
        <?php if($page < $total_pages): ?><li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$page+1])); ?>">下一页</a></li><?php endif; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
