<?php
require_once 'config.php';
checkLogin();
requirePermission('logs','view');

$db = getDB();

$filter_admin  = trim($_GET['admin']  ?? '');
$filter_module = trim($_GET['module'] ?? '');
$filter_action = trim($_GET['action'] ?? '');
$filter_date   = trim($_GET['date']   ?? '');
$page          = max(1, intval($_GET['p'] ?? 1));
$page_size     = 50;

$sql = "SELECT * FROM admin_logs WHERE 1=1";
$params = [];
if ($filter_admin)  { $sql .= " AND admin_id LIKE ?"; $params[] = "%$filter_admin%"; }
if ($filter_module) { $sql .= " AND module=?"; $params[] = $filter_module; }
if ($filter_action) { $sql .= " AND action=?"; $params[] = $filter_action; }
if ($filter_date)   { $sql .= " AND DATE(created_at)=?"; $params[] = $filter_date; }

$count_sql = str_replace("SELECT *", "SELECT COUNT(*)", $sql);
$cnt_stmt  = $db->prepare($count_sql); $cnt_stmt->execute($params);
$total = $cnt_stmt->fetchColumn();

$sql .= " ORDER BY id DESC LIMIT $page_size OFFSET " . (($page-1)*$page_size);
$stmt = $db->prepare($sql); $stmt->execute($params);
$logs = $stmt->fetchAll();

$total_pages = max(1, ceil($total / $page_size));

// 下拉选项
$modules = $db->query("SELECT DISTINCT module FROM admin_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN,0);
$actions = $db->query("SELECT DISTINCT action FROM admin_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN,0);

global $MODULE_NAMES, $ACTION_NAMES;
$action_colors = [
    'login'=>'success','logout'=>'secondary','create'=>'primary','update'=>'warning',
    'delete'=>'danger','execute'=>'info','change_password'=>'dark','reset_password'=>'dark',
    'toggle'=>'info','restore'=>'success','add'=>'primary','edit'=>'warning',
];
$action_icons  = [
    'login'=>'right-to-bracket','logout'=>'right-from-bracket','create'=>'plus-circle',
    'update'=>'pen','delete'=>'trash','execute'=>'play','change_password'=>'key','reset_password'=>'unlock',
    'toggle'=>'rotate','restore'=>'rotate-left','add'=>'plus','edit'=>'pen',
];

$page_title='操作日志'; $page_icon='fas fa-clipboard-list'; $page_subtitle='管理员所有操作记录';
require_once 'header.php';
?>

<!-- 过滤 -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label fw-semibold small">管理员</label>
        <input type="text" class="form-control form-control-sm" name="admin" value="<?php echo htmlspecialchars($filter_admin); ?>" placeholder="QQ号">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold small">模块</label>
        <select class="form-select form-select-sm" name="module">
          <option value="">全部</option>
          <?php foreach($modules as $m): ?>
          <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $filter_module===$m?'selected':''; ?>>
            <?php echo htmlspecialchars($MODULE_NAMES[$m] ?? $m); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold small">操作类型</label>
        <select class="form-select form-select-sm" name="action">
          <option value="">全部</option>
          <?php foreach($actions as $a): ?>
          <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $filter_action===$a?'selected':''; ?>>
            <?php echo htmlspecialchars($ACTION_NAMES[$a] ?? $a); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold small">日期</label>
        <input type="date" class="form-control form-control-sm" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="fas fa-search me-1"></i>查询</button>
        <a href="admin_logs.php" class="btn btn-sm btn-outline-secondary">重置</a>
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
          <th class="ps-4" style="width:50px">ID</th>
          <th style="width:100px">管理员</th>
          <th style="width:100px">模块</th>
          <th style="width:100px">操作</th>
          <th style="width:120px">对象ID</th>
          <th>修改前</th>
          <th>修改后</th>
          <th style="width:80px">IP</th>
          <th class="pe-4" style="width:140px">时间</th>
        </tr></thead>
        <tbody>
        <?php foreach($logs as $log):
          $a = strtolower($log['action']);
          $color = $action_colors[$a] ?? 'secondary';
          $icon  = $action_icons[$a]  ?? 'circle';
        ?>
        <tr>
          <td class="ps-4 text-muted"><?php echo $log['id']; ?></td>
          <td><code><?php echo htmlspecialchars($log['admin_id']); ?></code></td>
          <td><span class="badge bg-body-secondary text-body border"><?php echo htmlspecialchars($MODULE_NAMES[$log['module']] ?? $log['module']); ?></span></td>
          <td><span class="badge bg-<?php echo $color; ?>"><i class="fas fa-<?php echo $icon; ?> me-1"></i><?php echo htmlspecialchars($ACTION_NAMES[$a] ?? $log['action']); ?></span></td>
          <td class="text-muted"><?php echo htmlspecialchars($log['target_id'] ?? '—'); ?></td>
          <td>
            <?php if($log['old_data']): ?>
            <button class="btn btn-xs btn-outline-secondary py-0 px-1 small"
                    onclick="showData(this,'<?php echo htmlspecialchars(str_replace("'","\\'",htmlspecialchars_decode($log['old_data']??'')),ENT_QUOTES); ?>')">
              查看
            </button>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if($log['new_data']): ?>
            <button class="btn btn-xs btn-outline-primary py-0 px-1 small"
                    onclick="showData(this,'<?php echo htmlspecialchars(str_replace("'","\\'",htmlspecialchars_decode($log['new_data']??'')),ENT_QUOTES); ?>')">
              查看
            </button>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-muted"><?php echo htmlspecialchars($log['ip'] ?? '—'); ?></td>
          <td class="pe-4 text-muted"><?php echo htmlspecialchars($log['created_at'] ?? ''); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($logs)): ?>
        <tr><td colspan="9" class="text-center py-5 text-muted">暂无符合条件的日志</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if($total_pages > 1): ?>
  <div class="card-footer d-flex justify-content-center">
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php if($page > 1): ?>
        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$page-1])); ?>">上一页</a></li>
        <?php endif; ?>
        <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
        <li class="page-item <?php echo $i===$page?'active':''; ?>">
          <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$i])); ?>"><?php echo $i; ?></a>
        </li>
        <?php endfor; ?>
        <?php if($page < $total_pages): ?>
        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET,['p'=>$page+1])); ?>">下一页</a></li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<!-- 数据详情弹窗 -->
<div class="modal fade" id="dataModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold">数据详情</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <pre id="dataContent" class="bg-body-secondary p-3 rounded-3 small" style="max-height:400px;overflow:auto;white-space:pre-wrap;word-break:break-all;"></pre>
      </div>
    </div>
  </div>
</div>

<script>
function showData(btn, raw){
  try {
    var obj = JSON.parse(raw);
    document.getElementById('dataContent').textContent = JSON.stringify(obj, null, 2);
  } catch(e) {
    document.getElementById('dataContent').textContent = raw;
  }
  new bootstrap.Modal(document.getElementById('dataModal')).show();
}
</script>

<?php require_once 'footer.php'; ?>
