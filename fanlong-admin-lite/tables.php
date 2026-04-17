<?php
require_once 'config.php';
checkLogin();
requirePermission('tables','view');

$db = getDB();

// AJAX：返回表格数据（分页）
if (isset($_GET['ajax_table'])) {
    header('Content-Type: application/json; charset=utf-8');
    $tbl  = $_GET['ajax_table'];
    $page = max(1, intval($_GET['page'] ?? 1));
    $size = 50;
    $off  = ($page-1)*$size;
    // 安全：只允许已知的表
    $allowed = getAllTables();
    if (!in_array($tbl, $allowed)) { echo json_encode(['error'=>'非法表名']); exit(); }
    try {
        $total = $db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        $rows  = $db->query("SELECT * FROM `$tbl` LIMIT $size OFFSET $off")->fetchAll();
        echo json_encode(['total'=>$total,'rows'=>$rows,'page'=>$page,'size'=>$size]);
    } catch(Exception $e){ echo json_encode(['error'=>$e->getMessage()]); }
    exit();
}

// 获取所有表信息
$all_tables = getAllTables();
$table_info = [];
foreach($all_tables as $tbl) {
    try {
        $cols  = $db->query("PRAGMA table_info(`$tbl`)")->fetchAll();
        $cnt   = $db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        $size  = 0;
        try { $size = $db->prepare("SELECT SUM(pgsize) FROM dbstat WHERE name=?")->execute([$tbl]) ? $db->prepare("SELECT SUM(pgsize) FROM dbstat WHERE name=?")->execute([$tbl]) : 0;
              $s=$db->prepare("SELECT SUM(pgsize) FROM dbstat WHERE name=?"); $s->execute([$tbl]); $size=$s->fetchColumn(); } catch(Exception $e){}
        $table_info[] = ['name'=>$tbl,'cols'=>$cols,'count'=>$cnt,'size'=>intval($size)];
    } catch(Exception $e){ $table_info[]=['name'=>$tbl,'cols'=>[],'count'=>0,'size'=>0]; }
}

// 对表做中文说明
$table_labels = [
    'users'          => '用户档案',
    'user_stats'     => '用户属性',
    'user_bag'       => '用户背包',
    'user_equip'     => '装备穿戴',
    'items'          => '商店管理',
    'game_config'    => '游戏配置',
    'game_terms'     => '术语翻译',
    'custom_replies' => '自定义回复',
    'system_vars'    => '系统变量',
    'admins'         => '管理员',
    'login_logs'      => '登录日志',
    'admin_logs'      => '操作日志',
    'drama_archives'  => '剧情档案',
    'history_stats'   => '历史统计',
    'item_instances'  => '物品实例',
];

// 管理页跳转
$mgmt_links = [
    'users'=>'users.php','user_stats'=>'stats.php','user_bag'=>'user_bag.php','user_equip'=>'equip.php',
    'items'=>'items.php','game_config'=>'game_config.php','game_terms'=>'terms.php',
    'custom_replies'=>'custom_replies.php','system_vars'=>'system_vars.php',
    'admins'=>'admins.php',
    'login_logs'=>'login_logs.php','admin_logs'=>'admin_logs.php',
    'drama_archives'=>'drama_archives.php','history_stats'=>'history_stats.php',
    'item_instances'=>'item_instances.php',
];

$page_title='数据表浏览'; $page_icon='fas fa-database'; $page_subtitle='全部数据库表';
require_once 'header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-primary"><?php echo count($all_tables); ?></div>
      <div class="text-muted small">数据表数量</div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card text-center"><div class="card-body py-3">
      <?php $total_rows=array_sum(array_column($table_info,'count')); ?>
      <div class="fs-4 fw-bold text-success"><?php echo number_format($total_rows); ?></div>
      <div class="text-muted small">总行数</div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card text-center"><div class="card-body py-3">
      <?php $db_size = file_exists(DB_PATH)?filesize(DB_PATH):0; ?>
      <div class="fs-4 fw-bold text-warning"><?php echo formatBytes($db_size); ?></div>
      <div class="text-muted small">数据库大小</div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-info"><?php echo $db->query('SELECT sqlite_version()')->fetchColumn(); ?></div>
      <div class="text-muted small">SQLite 版本</div>
    </div></div>
  </div>
</div>

<div class="accordion" id="tablesAccordion">
<?php foreach($table_info as $idx => $ti): ?>
<div class="card mb-3 border">
  <div class="card-header" id="h<?php echo $idx; ?>">
    <button class="btn d-flex justify-content-between align-items-center w-100 py-2 px-3 text-start"
            type="button" data-bs-toggle="collapse" data-bs-target="#c<?php echo $idx; ?>"
            aria-expanded="<?php echo $idx===0?'true':'false'; ?>">
      <span class="d-flex align-items-center gap-3">
        <strong class="font-monospace"><?php echo htmlspecialchars($ti['name']); ?></strong>
        <?php if(isset($table_labels[$ti['name']])): ?>
        <span class="badge bg-body-secondary text-body border"><?php echo $table_labels[$ti['name']]; ?></span>
        <?php endif; ?>
        <span class="badge bg-primary rounded-pill"><?php echo number_format($ti['count']); ?> 行</span>
        <?php if($ti['size']>0): ?><span class="badge bg-secondary rounded-pill small"><?php echo formatBytes($ti['size']); ?></span><?php endif; ?>
      </span>
      <span class="text-muted small"><?php echo count($ti['cols']); ?> 字段</span>
    </button>
  </div>
  <div id="c<?php echo $idx; ?>" class="collapse <?php echo $idx===0?'show':''; ?>" data-bs-parent="#tablesAccordion">
    <!-- 表结构 -->
    <div class="p-3 border-bottom">
      <h6 class="fw-semibold small mb-2 text-muted text-uppercase">表结构</h6>
      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 small">
          <thead><tr class="table-active"><th>#</th><th>字段名</th><th>类型</th><th>非空</th><th>默认值</th><th>主键</th></tr></thead>
          <tbody>
          <?php foreach($ti['cols'] as $ci=>$col): ?>
          <tr>
            <td class="text-muted"><?php echo $ci+1; ?></td>
            <td><code><?php echo htmlspecialchars($col['name']); ?></code>
              <?php $display=t($col['name'],''); if($display && $display!==$col['name']): ?><span class="text-muted ms-1">(<?php echo htmlspecialchars($display); ?>)</span><?php endif; ?>
            </td>
            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($col['type']); ?></span></td>
            <td><?php echo $col['notnull']?"<span class='badge bg-secondary'>否</span>":"<span class='badge bg-success'>是</span>"; ?></td>
            <td><?php echo $col['dflt_value']!==null?"<code class='small'>".htmlspecialchars($col['dflt_value'])."</code>":'<span class="text-muted">—</span>'; ?></td>
            <td><?php echo $col['pk']?"<span class='badge bg-warning text-dark'>PK</span>":'—'; ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <!-- 数据预览 -->
    <div class="p-3 border-bottom">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-semibold small mb-0 text-muted text-uppercase">数据预览（最多显示10行）</h6>
        <?php if($ti['count']>10): ?>
        <button class="btn btn-xs btn-sm btn-outline-primary py-0 px-2"
                onclick="loadFullTable('<?php echo htmlspecialchars($ti['name']); ?>')">
          查看全部 <?php echo $ti['count']; ?> 行
        </button>
        <?php endif; ?>
      </div>
      <?php
      try {
          $preview = $db->query("SELECT * FROM `{$ti['name']}` LIMIT 10")->fetchAll();
          if (!empty($preview)):
      ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover small mb-0">
          <thead><tr class="table-active"><?php foreach(array_keys($preview[0]) as $h): ?><th><?php echo htmlspecialchars($h); ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach($preview as $row): ?>
          <tr>
            <?php foreach($row as $cell): ?>
            <td>
              <?php if($cell===null): ?><span class="text-muted">NULL</span>
              <?php elseif(is_string($cell) && strlen($cell)>60): ?>
              <span title="<?php echo htmlspecialchars($cell); ?>">
                <?php echo htmlspecialchars(substr($cell,0,60)); ?><span class="text-muted">…</span>
              </span>
              <?php else: echo htmlspecialchars(strval($cell)); endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="text-muted small">表中暂无数据</div><?php endif;
      } catch(Exception $e){ echo '<div class="text-warning small">无法读取数据</div>'; } ?>
    </div>
    <!-- 操作 -->
    <div class="p-3 bg-body-secondary d-flex gap-2">
      <?php if(isset($mgmt_links[$ti['name']])): ?>
      <a href="<?php echo $mgmt_links[$ti['name']]; ?>" class="btn btn-sm btn-primary">
        <i class="fas fa-external-link-alt me-1"></i>进入管理页
      </a>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($ti['name']); ?>').then(()=>showToast('success','已复制表名'))">
        <i class="fas fa-copy me-1"></i>复制表名
      </button>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- 全量数据弹窗 -->
<div class="modal fade" id="fullTableModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold">表数据：<span id="fullTableName"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="fullTableContent" class="p-4">
          <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        </div>
      </div>
      <div class="modal-footer border-0 justify-content-between">
        <div id="fullTablePagination" class="d-flex gap-2 align-items-center"></div>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<script>
var fullTableCurrent = {name:'', page:1};
function loadFullTable(tbl, page){
  page = page || 1;
  fullTableCurrent = {name:tbl, page:page};
  document.getElementById('fullTableName').textContent = tbl;
  document.getElementById('fullTableContent').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
  new bootstrap.Modal(document.getElementById('fullTableModal')).show();
  fetch('tables.php?ajax_table='+encodeURIComponent(tbl)+'&page='+page)
    .then(r=>r.json())
    .then(data=>{
      if(data.error){ document.getElementById('fullTableContent').innerHTML='<div class="alert alert-danger m-3">'+data.error+'</div>'; return; }
      var html='<div class="table-responsive"><table class="table table-sm table-hover small mb-0"><thead><tr class="table-active">';
      if(data.rows.length>0){ Object.keys(data.rows[0]).forEach(k=>html+='<th>'+k+'</th>'); }
      html+='</tr></thead><tbody>';
      data.rows.forEach(row=>{
        html+='<tr>';
        Object.values(row).forEach(v=>{ html+='<td>'+(v===null?'<span class="text-muted">NULL</span>':String(v).length>80?String(v).substring(0,80)+'<span class="text-muted">…</span>':v)+'</td>'; });
        html+='</tr>';
      });
      html+='</tbody></table></div>';
      document.getElementById('fullTableContent').innerHTML=html;
      // Pagination
      var total=data.total, size=data.size, pages=Math.ceil(total/size);
      var pag='<span class="text-muted small">共 '+total+' 行 · 第 '+page+'/'+pages+' 页</span>';
      if(page>1) pag+='<button class="btn btn-sm btn-outline-secondary" onclick="loadFullTable(\''+tbl+'\','+(page-1)+')">上一页</button>';
      if(page<pages) pag+='<button class="btn btn-sm btn-outline-secondary" onclick="loadFullTable(\''+tbl+'\','+(page+1)+')">下一页</button>';
      document.getElementById('fullTablePagination').innerHTML=pag;
    });
}
</script>

<?php require_once 'footer.php'; ?>
