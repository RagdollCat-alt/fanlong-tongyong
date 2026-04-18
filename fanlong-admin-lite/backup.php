<?php
require_once 'config.php';
checkLogin();
requirePermission('backup','execute');

$db = getDB();
$msg=''; $msg_type='';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // 导出整个数据库（SQL dump）
    if ($pa === 'export_sql') {
        logAction('backup','execute','export_sql');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="fanlong_backup_'.date('Ymd_His').'.sql"');

        $tables = getAllTables();
        echo "-- 繁笼机器人数据库备份\n";
        echo "-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
        echo "-- 数据库路径: " . DB_PATH . "\n\n";

        foreach($tables as $tbl) {
            try {
                $cols = $db->query("PRAGMA table_info(`$tbl`)")->fetchAll();
                $create = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$tbl'")->fetchColumn();
                echo "\n-- ===== 表: $tbl =====\n";
                echo "DROP TABLE IF EXISTS `$tbl`;\n";
                echo $create . ";\n\n";

                $rows = $db->query("SELECT * FROM `$tbl`")->fetchAll();
                if (!empty($rows)) {
                    $col_names = implode(',', array_map(fn($c)=>"`{$c['name']}`", $cols));
                    echo "INSERT INTO `$tbl` ($col_names) VALUES\n";
                    $vals_arr = [];
                    foreach($rows as $r) {
                        $vals = array_map(function($v){ return $v===null?'NULL':"'".addslashes(strval($v))."'"; }, array_values($r));
                        $vals_arr[] = '(' . implode(',',$vals) . ')';
                    }
                    echo implode(",\n", $vals_arr) . ";\n";
                }
            } catch(Exception $e){ echo "\n-- 导出失败: $tbl: " . $e->getMessage() . "\n"; }
        }
        exit();
    }

    // 下载原始 .db 文件
    if ($pa === 'export_db') {
        if (!file_exists(DB_PATH)) { $msg='数据库文件不存在'; $msg_type='danger'; }
        else {
            logAction('backup','execute','export_db');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="fanlong_'.date('Ymd_His').'.db"');
            header('Content-Length: ' . filesize(DB_PATH));
            readfile(DB_PATH);
            exit();
        }
    }

    // 导出单张表为 CSV
    if ($pa === 'export_csv') {
        $tbl = $_POST['table'] ?? '';
        $allowed = getAllTables();
        if (!in_array($tbl, $allowed)) { $msg='非法表名'; $msg_type='danger'; }
        else {
            logAction('backup','execute',"export_csv/$tbl");
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$tbl.'_'.date('Ymd_His').'.csv"');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            $rows = $db->query("SELECT * FROM `$tbl`")->fetchAll();
            if (!empty($rows)) {
                echo implode(',', array_map(fn($k)=>'"'.str_replace('"','""',$k).'"', array_keys($rows[0]))) . "\n";
                foreach($rows as $r) {
                    echo implode(',', array_map(fn($v)=>$v===null?'NULL':'"'.str_replace('"','""',strval($v)).'"', array_values($r))) . "\n";
                }
            }
            exit();
        }
    }
}

$tables = getAllTables();
$db_size = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
$db_mtime = file_exists(DB_PATH) ? date('Y-m-d H:i:s', filemtime(DB_PATH)) : '未知';

$page_title='数据备份'; $page_icon='fas fa-floppy-disk'; $page_subtitle='数据导出与备份';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-4">
      <i class="fas fa-database fa-2x mb-3" style="color:#667eea"></i>
      <div class="fw-bold fs-5"><?php echo formatBytes($db_size); ?></div>
      <div class="text-muted small">数据库文件大小</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-4">
      <i class="fas fa-table fa-2x mb-3 text-success"></i>
      <div class="fw-bold fs-5"><?php echo count($tables); ?> 张</div>
      <div class="text-muted small">数据表数量</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-4">
      <i class="fas fa-clock fa-2x mb-3 text-warning"></i>
      <div class="fw-bold"><?php echo $db_mtime; ?></div>
      <div class="text-muted small">最后修改时间</div>
    </div></div>
  </div>
</div>

<div class="row g-4">
  <!-- 全量备份 -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-download me-2"></i>全量备份（SQL 格式）</div>
      <div class="card-body">
        <p class="text-muted small">导出所有表的建表语句和数据，可用于完整恢复数据库。</p>
        <form method="POST">
          <input type="hidden" name="action" value="export_sql">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-download me-2"></i>下载完整 SQL 备份
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- 下载原始 .db 文件 -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-database me-2"></i>下载数据库文件（.db）</div>
      <div class="card-body">
        <p class="text-muted small">直接下载 SQLite 原始数据库文件，可用 DB Browser for SQLite 打开查看。</p>
        <form method="POST">
          <input type="hidden" name="action" value="export_db">
          <button type="submit" class="btn btn-outline-primary">
            <i class="fas fa-database me-2"></i>下载 .db 文件
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- 单表 CSV 导出 -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-file-csv me-2"></i>单表导出（CSV 格式）</div>
      <div class="card-body">
        <p class="text-muted small">导出指定表为 CSV，可用 Excel 打开查看。</p>
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="action" value="export_csv">
          <select class="form-select" name="table" required>
            <option value="">选择表...</option>
            <?php foreach($tables as $t): ?>
            <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-success text-nowrap">
            <i class="fas fa-file-csv me-1"></i>导出 CSV
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- 数据库文件信息 -->
<div class="card mt-4">
  <div class="card-header"><i class="fas fa-info-circle me-2"></i>数据库文件信息</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-6">
        <dl class="row small mb-0">
          <dt class="col-4 text-muted">文件路径</dt>
          <dd class="col-8"><code><?php echo htmlspecialchars(DB_PATH); ?></code></dd>
          <dt class="col-4 text-muted">SQLite 版本</dt>
          <dd class="col-8"><?php echo $db->query('SELECT sqlite_version()')->fetchColumn(); ?></dd>
          <dt class="col-4 text-muted">外键约束</dt>
          <dd class="col-8"><?php echo $db->query('PRAGMA foreign_keys')->fetchColumn()?'<span class="badge bg-success">已启用</span>':'<span class="badge bg-warning">未启用</span>'; ?></dd>
        </dl>
      </div>
      <div class="col-md-6">
        <div class="alert alert-info border-0 rounded-3 small mb-0">
          <i class="fas fa-lightbulb me-2"></i>
          <strong>恢复建议：</strong>将 SQL 备份文件通过 SQLite 客户端（如 DB Browser for SQLite）导入，或使用 sqlite3 命令行工具：<br>
          <code>sqlite3 new.db &lt; backup.sql</code>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>
