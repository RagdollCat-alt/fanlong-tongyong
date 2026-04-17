<?php
require_once 'config.php';
checkLogin();
requirePermission('history_stats', 'view');

$db = getDB();

// 过滤参数
$filter_user  = trim($_GET['user']       ?? '');
$filter_group = trim($_GET['group']      ?? '');
$filter_from  = trim($_GET['date_from']  ?? '');
$filter_to    = trim($_GET['date_to']    ?? '');
$page         = max(1, intval($_GET['p'] ?? 1));
$page_size    = 50;

// 基础查询
$sql    = "SELECT h.*, u.name as user_name FROM history_stats h LEFT JOIN users u ON h.user_id=u.id WHERE 1=1";
$params = [];
if ($filter_user)  { $sql .= " AND (h.user_id LIKE ? OR u.name LIKE ?)"; $params[] = "%$filter_user%"; $params[] = "%$filter_user%"; }
if ($filter_group) { $sql .= " AND h.group_id=?"; $params[] = $filter_group; }
if ($filter_from)  { $sql .= " AND h.date >= ?"; $params[] = $filter_from; }
if ($filter_to)    { $sql .= " AND h.date <= ?"; $params[] = $filter_to; }

$cnt_stmt = $db->prepare(str_replace("SELECT h.*, u.name as user_name","SELECT COUNT(*)",$sql));
$cnt_stmt->execute($params);
$total       = $cnt_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $page_size));

$sql .= " ORDER BY h.date DESC, h.word_count DESC LIMIT $page_size OFFSET " . (($page-1)*$page_size);
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

// 统计概览
$summary = $db->query("SELECT COUNT(*) as record_count, SUM(word_count) as total_words, SUM(line_count) as total_lines, COUNT(DISTINCT user_id) as unique_users, COUNT(DISTINCT date) as unique_days FROM history_stats")->fetch();

// TOP 10 发言量用户
$top_users = $db->query("SELECT h.user_id, u.name, SUM(h.word_count) as total_words, SUM(h.line_count) as total_lines, COUNT(*) as active_days FROM history_stats h LEFT JOIN users u ON h.user_id=u.id GROUP BY h.user_id ORDER BY total_words DESC LIMIT 10")->fetchAll();

// 所有群号
$all_groups = $db->query("SELECT DISTINCT group_id FROM history_stats WHERE group_id!='' ORDER BY group_id")->fetchAll(PDO::FETCH_COLUMN, 0);

$page_title    = '历史统计';
$page_icon     = 'fas fa-chart-area';
$page_subtitle = '玩家每日发言记录';
require_once 'header.php';
?>

<!-- 说明 -->
<div class="alert alert-info border-0 rounded-3 mb-4 py-2 small">
  <i class="fas fa-chart-area me-2"></i>
  <strong>历史统计说明：</strong>
  机器人每日自动记录群内玩家的发言字数和行数，用于活跃度分析与家族贡献计算。此页面为只读，数据由机器人自动生成。
</div>

<!-- 概览卡片 -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-primary"><?php echo number_format($summary['unique_users']); ?></div>
      <div class="text-muted small">参与用户数</div>
    </div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-success"><?php echo number_format($summary['unique_days']); ?></div>
      <div class="text-muted small">统计天数</div>
    </div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-warning"><?php echo number_format($summary['total_words']); ?></div>
      <div class="text-muted small">累计总字数</div>
    </div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-info"><?php echo number_format($summary['record_count']); ?></div>
      <div class="text-muted small">记录总条数</div>
    </div></div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- TOP 10 -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-trophy me-2 text-warning"></i>发言量 TOP 10</div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0 small">
          <thead><tr class="table-active"><th class="ps-3">#</th><th>用户</th><th>总字数</th><th>活跃天数</th></tr></thead>
          <tbody>
          <?php foreach($top_users as $i=>$tu): ?>
          <tr>
            <td class="ps-3">
              <?php if($i===0): ?><span class="badge bg-warning text-dark">🥇</span>
              <?php elseif($i===1): ?><span class="badge bg-secondary">🥈</span>
              <?php elseif($i===2): ?><span class="badge" style="background:#cd7f32">🥉</span>
              <?php else: ?><span class="text-muted"><?php echo $i+1; ?></span><?php endif; ?>
            </td>
            <td>
              <div class="fw-semibold"><?php echo htmlspecialchars($tu['name']??$tu['user_id']); ?></div>
              <div class="text-muted" style="font-size:.72rem"><?php echo htmlspecialchars($tu['user_id']); ?></div>
            </td>
            <td class="fw-semibold text-primary"><?php echo number_format($tu['total_words']); ?></td>
            <td class="text-muted"><?php echo $tu['active_days']; ?> 天</td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 过滤 -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-filter me-2"></i>筛选记录</div>
      <div class="card-body">
        <form method="GET" class="row g-2">
          <div class="col-md-5">
            <label class="form-label fw-semibold small">用户（QQ号/角色名）</label>
            <input type="text" class="form-control form-control-sm" name="user" value="<?php echo htmlspecialchars($filter_user); ?>" placeholder="搜索用户">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">群组</label>
            <select class="form-select form-select-sm" name="group">
              <option value="">全部群</option>
              <?php foreach($all_groups as $g): ?>
              <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $filter_group===$g?'selected':''; ?>><?php echo htmlspecialchars($g); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><!-- 占位 --></div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">开始日期</label>
            <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo htmlspecialchars($filter_from); ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">结束日期</label>
            <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo htmlspecialchars($filter_to); ?>">
          </div>
          <div class="col-md-4 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="fas fa-search me-1"></i>查询</button>
            <a href="history_stats.php" class="btn btn-sm btn-outline-secondary">重置</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- 明细列表 -->
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <span><i class="fas fa-list me-2"></i>明细记录（共 <?php echo number_format($total); ?> 条）</span>
    <span class="text-muted small">第 <?php echo $page; ?>/<?php echo $total_pages; ?> 页</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead><tr class="table-active">
          <th class="ps-4">日期</th><th>用户</th><th>群组</th>
          <th>字数</th><th class="pe-4">行数</th>
        </tr></thead>
        <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td class="ps-4 fw-semibold"><?php echo htmlspecialchars($r['date']); ?></td>
          <td>
            <div><?php echo htmlspecialchars($r['user_name']??$r['user_id']); ?></div>
            <div class="text-muted" style="font-size:.72rem"><?php echo htmlspecialchars($r['user_id']); ?></div>
          </td>
          <td class="text-muted"><?php echo htmlspecialchars($r['group_id']??'—'); ?></td>
          <td>
            <span class="badge bg-primary rounded-pill"><?php echo number_format($r['word_count']); ?></span>
          </td>
          <td class="pe-4 text-muted"><?php echo number_format($r['line_count']); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($rows)): ?>
        <tr><td colspan="5" class="text-center py-5 text-muted">暂无统计数据</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if($total_pages > 1): ?>
  <div class="card-footer d-flex justify-content-center">
    <nav><ul class="pagination pagination-sm mb-0">
      <?php if($page>1): ?><li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$page-1])); ?>">上一页</a></li><?php endif; ?>
      <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
      <li class="page-item <?php echo $i===$page?'active':''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$i])); ?>"><?php echo $i; ?></a></li>
      <?php endfor; ?>
      <?php if($page<$total_pages): ?><li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$page+1])); ?>">下一页</a></li><?php endif; ?>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
