<?php
require_once 'config.php';
checkLogin();
requirePermission('custom_replies','view');

$db = getDB();
$msg=''; $msg_type='';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';
    if ($pa === 'save') {
        $is_add = empty($_POST['orig_key']);
        requirePermission('custom_replies',$is_add?'add':'edit');
        $key   = trim($_POST['key']   ?? '');
        $value = trim($_POST['value'] ?? '');
        if (empty($key)){ $msg='关键词不能为空'; $msg_type='danger'; }
        else {
            try {
                $old=null;
                if (!$is_add){ $r=$db->prepare("SELECT * FROM custom_replies WHERE key=?"); $r->execute([$_POST['orig_key']]); $old=$r->fetch(); }
                $db->prepare("INSERT OR REPLACE INTO custom_replies (key,value) VALUES (?,?)")->execute([$key,$value]);
                logAction('custom_replies',$is_add?'create':'update',$key,$old,['value'=>$value]);
                setFlash('success','自定义回复已保存'); header('Location: custom_replies.php'); exit();
            } catch(Exception $e){ $msg='保存失败：'.$e->getMessage(); $msg_type='danger'; }
        }
    }
    if ($pa === 'delete') {
        requirePermission('custom_replies','delete');
        $k=$_POST['del_key']??'';
        $old=$db->prepare("SELECT * FROM custom_replies WHERE key=?"); $old->execute([$k]); $old=$old->fetch();
        $db->prepare("DELETE FROM custom_replies WHERE key=?")->execute([$k]);
        logAction('custom_replies','delete',$k,$old);
        setFlash('success','已删除'); header('Location: custom_replies.php'); exit();
    }
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT key, value FROM custom_replies WHERE 1=1";
$params=[];
if($search){ $sql.=" AND (key LIKE ? OR value LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
$sql.=" ORDER BY key";
$stmt=$db->prepare($sql); $stmt->execute($params); $replies=$stmt->fetchAll();

$page_title='自定义回复'; $page_icon='fas fa-comments'; $page_subtitle='关键词回复配置';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2">
    <input type="text" class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="搜索关键词或回复内容..." style="min-width:220px">
    <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-search"></i></button>
    <?php if($search): ?><a href="custom_replies.php" class="btn btn-sm btn-outline-secondary">清除</a><?php endif; ?>
  </form>
  <?php if(can('custom_replies','add')): ?>
  <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#replyModal" onclick="clearForm()">
    <i class="fas fa-plus me-1"></i>新增回复
  </button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-list me-2"></i>自定义回复（<?php echo count($replies); ?> 条）</div>
  <div class="card-body p-0">
    <table class="table table-hover datatable align-middle mb-0">
      <thead><tr class="table-active">
        <th class="ps-4" style="width:30%">关键词 (key)</th>
        <th>回复内容</th><th class="pe-4" style="width:120px">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach($replies as $r): ?>
      <tr>
        <td class="ps-4"><code><?php echo htmlspecialchars($r['key']); ?></code></td>
        <td>
          <span class="d-inline-block text-truncate" style="max-width:400px" title="<?php echo htmlspecialchars($r['value']); ?>">
            <?php echo htmlspecialchars($r['value']); ?>
          </span>
        </td>
        <td class="pe-4">
          <div class="d-flex gap-1">
            <?php if(can('custom_replies','edit')): ?>
            <button class="btn btn-sm btn-outline-primary py-0 px-2"
                    onclick="editReply('<?php echo htmlspecialchars($r['key'],ENT_QUOTES); ?>','<?php echo htmlspecialchars(str_replace(["\r","\n"],['\\r','\\n'],$r['value']),ENT_QUOTES); ?>')">
              编辑
            </button>
            <?php endif; ?>
            <?php if(can('custom_replies','delete')): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('确认删除？')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="del_key" value="<?php echo htmlspecialchars($r['key']); ?>">
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

<!-- 弹窗 -->
<div class="modal fade" id="replyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold" id="replyModalTitle">新增自定义回复</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="orig_key" id="origKey">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold small">关键词 (key) *</label>
            <input type="text" class="form-control font-monospace" name="key" id="replyKey" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">回复内容</label>
            <textarea class="form-control" name="value" id="replyValue" rows="5" placeholder="支持换行，{name} 等变量"></textarea>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-primary px-4">保存</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function clearForm(){
  document.getElementById('origKey').value='';
  document.getElementById('replyKey').value='';
  document.getElementById('replyValue').value='';
  document.getElementById('replyModalTitle').textContent='新增自定义回复';
}
function editReply(key,value){
  document.getElementById('origKey').value=''+key;
  document.getElementById('replyKey').value=key;
  document.getElementById('replyValue').value=value.replace(/\\r/g,'\r').replace(/\\n/g,'\n');
  document.getElementById('replyModalTitle').textContent='编辑自定义回复';
  new bootstrap.Modal(document.getElementById('replyModal')).show();
}
</script>

<?php require_once 'footer.php'; ?>
