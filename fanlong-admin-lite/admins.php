<?php
require_once 'config.php';
checkLogin();
requirePermission('admins', 'view');

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$uid    = $_GET['id']     ?? '';
$msg    = ''; $msg_type = '';

global $PERMISSION_MATRIX, $MODULE_NAMES, $ACTION_NAMES;

// ===== POST 处理 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    // 新增管理员
    if ($post_action === 'add') {
        requirePermission('admins', 'add');
        $new_id    = trim($_POST['user_id'] ?? '');
        $level     = intval($_POST['level'] ?? 1);
        $nickname  = trim($_POST['nickname'] ?? '');
        // 超级管理员才能设level=999
        if ($level >= 999 && !isSuperAdmin()) $level = 1;

        if (empty($new_id)) {
            $msg = 'QQ 号不能为空'; $msg_type = 'danger';
        } else {
            try {
                $exists = $db->prepare("SELECT COUNT(*) FROM admins WHERE user_id=?");
                $exists->execute([$new_id]);
                if ($exists->fetchColumn()) { $msg='该QQ号已是管理员'; $msg_type='warning'; }
                else {
                    $default_perms = json_encode(getDefaultPermissions(), JSON_UNESCAPED_UNICODE);
                    $db->prepare("INSERT INTO admins (user_id,level,nickname,permissions,added_by) VALUES (?,?,?,?,?)")
                       ->execute([$new_id, $level, $nickname ?: $new_id, $default_perms, $_SESSION['admin_id']]);
                    logAction('admins','create',$new_id,null,['level'=>$level,'nickname'=>$nickname]);
                    setFlash('success','管理员 '.$new_id.' 已添加');
                    header('Location: admins.php'); exit();
                }
            } catch(Exception $e){ $msg='添加失败：'.$e->getMessage(); $msg_type='danger'; }
        }
    }

    // 保存权限 & 昵称 & 级别
    if ($post_action === 'save_perms') {
        requirePermission('admins', 'edit');
        $edit_id  = $_POST['edit_id'] ?? '';
        $nickname = trim($_POST['nickname'] ?? '');
        $level    = intval($_POST['level'] ?? 1);
        if ($level >= 999 && !isSuperAdmin()) $level = 1;

        // 构建权限数组
        $new_perms = [];
        foreach ($PERMISSION_MATRIX as $mod => $available_actions) {
            $new_perms[$mod] = [];
            foreach ($available_actions as $act) {
                if (!empty($_POST["perm_{$mod}_{$act}"])) {
                    $new_perms[$mod][] = $act;
                }
            }
        }

        try {
            $old = $db->prepare("SELECT level,permissions,nickname FROM admins WHERE user_id=?");
            $old->execute([$edit_id]); $old = $old->fetch();
            $db->prepare("UPDATE admins SET level=?,nickname=?,permissions=? WHERE user_id=?")
               ->execute([$level, $nickname ?: $edit_id, json_encode($new_perms, JSON_UNESCAPED_UNICODE), $edit_id]);
            logAction('admins','update',$edit_id,$old,['level'=>$level,'nickname'=>$nickname,'permissions'=>$new_perms]);
            setFlash('success','权限已保存');
            header('Location: admins.php'); exit();
        } catch(Exception $e){ $msg='保存失败：'.$e->getMessage(); $msg_type='danger'; }
    }

    // 删除管理员
    if ($post_action === 'delete') {
        requirePermission('admins', 'delete');
        $del_id = $_POST['del_id'] ?? '';
        if ($del_id === $_SESSION['admin_id']) { $msg='不能删除自己'; $msg_type='warning'; }
        else {
            try {
                $old = $db->prepare("SELECT * FROM admins WHERE user_id=?");
                $old->execute([$del_id]); $old=$old->fetch();
                $db->prepare("DELETE FROM admins WHERE user_id=?")->execute([$del_id]);
                logAction('admins','delete',$del_id,$old);
                setFlash('success','管理员已删除');
                header('Location: admins.php'); exit();
            } catch(Exception $e){ $msg='删除失败：'.$e->getMessage(); $msg_type='danger'; }
        }
    }

    // 重置密码（超级管理员）
    if ($post_action === 'reset_pwd') {
        if(!isSuperAdmin()){ $msg='仅超级管理员可重置密码'; $msg_type='danger'; }
        else {
            $reset_id = $_POST['reset_id'] ?? '';
            $new_pwd  = $_POST['new_pwd']  ?? '';
            if(strlen($new_pwd)<8){ $msg='密码至少8位'; $msg_type='danger'; }
            else {
                $hash = password_hash($new_pwd, PASSWORD_DEFAULT);
                $db->prepare("UPDATE admins SET password_hash=? WHERE user_id=?")->execute([$hash,$reset_id]);
                logAction('admins','reset_password',$reset_id);
                setFlash('success',$reset_id.' 密码已重置');
                header('Location: admins.php'); exit();
            }
        }
    }
}

// 编辑某个管理员的权限
$edit_admin = null;
if ($action === 'edit' && !empty($uid)) {
    requirePermission('admins', 'edit');
    $r = $db->prepare("SELECT * FROM admins WHERE user_id=?");
    $r->execute([$uid]); $edit_admin = $r->fetch();
    if (!$edit_admin) { $msg='找不到该管理员'; $msg_type='danger'; $action='list'; }
}

// 所有管理员列表
$admins = $db->query("SELECT user_id, level, nickname, last_login, added_by, created_at FROM admins ORDER BY level DESC, created_at ASC")->fetchAll();

$page_title    = '管理员管理';
$page_icon     = 'fas fa-user-shield';
$page_subtitle = '权限分配';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible">
  <i class="fas fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($msg); ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'edit' && $edit_admin): ?>
<!-- ===== 编辑权限面板 ===== -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-sliders me-2"></i>编辑管理员权限：<?php echo htmlspecialchars($edit_admin['user_id']); ?></span>
    <a href="admins.php" class="btn btn-sm btn-outline-secondary">返回列表</a>
  </div>
  <div class="card-body">
    <?php $edit_perms = safeJsonDecode($edit_admin['permissions'] ?? '{}'); ?>
    <form method="POST">
      <input type="hidden" name="action" value="save_perms">
      <input type="hidden" name="edit_id" value="<?php echo htmlspecialchars($edit_admin['user_id']); ?>">

      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label fw-semibold small">昵称</label>
          <input type="text" class="form-control" name="nickname"
                 value="<?php echo htmlspecialchars($edit_admin['nickname'] ?? $edit_admin['user_id']); ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold small">权限等级</label>
          <select class="form-select" name="level">
            <option value="1"  <?php echo intval($edit_admin['level'])<999?'selected':''; ?>>普通管理员</option>
            <?php if(isSuperAdmin()): ?>
            <option value="999" <?php echo intval($edit_admin['level'])>=999?'selected':''; ?>>超级管理员</option>
            <?php endif; ?>
          </select>
          <div class="form-text">超级管理员自动拥有全部权限</div>
        </div>
      </div>

      <!-- 权限矩阵 -->
      <?php if(intval($edit_admin['level'])<999): ?>
      <h6 class="fw-bold mb-3"><i class="fas fa-table-cells me-2"></i>细粒度权限矩阵</h6>
      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
          <thead>
            <tr class="table-active">
              <th style="width:180px">模块</th>
              <?php foreach(['view'=>'查看','add'=>'新增','edit'=>'编辑','delete'=>'删除','execute'=>'执行'] as $a=>$an): ?>
              <th class="text-center" style="width:90px"><?php echo $an; ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach($PERMISSION_MATRIX as $mod => $avail): ?>
          <tr>
            <td class="fw-semibold"><?php echo $MODULE_NAMES[$mod] ?? $mod; ?></td>
            <?php foreach(['view','add','edit','delete','execute'] as $act): ?>
            <td class="text-center">
              <?php if(in_array($act,$avail)): ?>
                <?php $checked=in_array($act,$edit_perms[$mod]??[])?'checked':''; ?>
                <input type="checkbox" class="form-check-input"
                       name="perm_<?php echo $mod; ?>_<?php echo $act; ?>" value="1" <?php echo $checked; ?>>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mb-3">
        <button type="button" class="btn btn-sm btn-outline-secondary me-2" onclick="toggleAll(true)">全选</button>
        <button type="button" class="btn btn-sm btn-outline-secondary me-2" onclick="toggleAll(false)">全清</button>
        <button type="button" class="btn btn-sm btn-outline-info" onclick="setViewOnly()">仅查看</button>
      </div>
      <?php else: ?>
      <div class="alert alert-info border-0 rounded-3"><i class="fas fa-info-circle me-2"></i>超级管理员自动拥有全部权限，无需单独配置。</div>
      <?php endif; ?>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-2"></i>保存权限</button>
        <a href="admins.php" class="btn btn-outline-secondary">取消</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ===== 管理员列表 ===== -->
<div class="row g-4">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list me-2"></i>管理员列表</span>
        <?php if(can('admins','add')): ?>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
          <i class="fas fa-plus me-1"></i>添加管理员
        </button>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead><tr class="table-active">
              <th class="ps-4">QQ 号</th><th>昵称</th><th>级别</th>
              <th>最后登录</th><th>添加者</th><th class="pe-4">操作</th>
            </tr></thead>
            <tbody>
            <?php foreach($admins as $a): ?>
            <tr>
              <td class="ps-4"><code><?php echo htmlspecialchars($a['user_id']); ?></code></td>
              <td><?php echo htmlspecialchars($a['nickname'] ?? $a['user_id']); ?></td>
              <td>
                <?php if(intval($a['level'])>=999): ?>
                <span class="badge" style="background:linear-gradient(135deg,#f59e0b,#ef4444)">超级管理员</span>
                <?php else: ?>
                <span class="badge bg-secondary">普通管理员</span>
                <?php endif; ?>
              </td>
              <td class="text-muted small"><?php echo htmlspecialchars($a['last_login'] ?? '从未'); ?></td>
              <td class="text-muted small"><?php echo htmlspecialchars($a['added_by'] ?? '—'); ?></td>
              <td class="pe-4">
                <div class="d-flex gap-1 flex-wrap">
                  <?php if(can('admins','edit')): ?>
                  <a href="admins.php?action=edit&id=<?php echo urlencode($a['user_id']); ?>"
                     class="btn btn-xs btn-sm btn-outline-primary py-0 px-2">权限</a>
                  <?php endif; ?>
                  <?php if(isSuperAdmin() && $a['user_id']!==$_SESSION['admin_id']): ?>
                  <button type="button" class="btn btn-xs btn-sm btn-outline-info py-0 px-2"
                          data-bs-toggle="modal" data-bs-target="#resetPwdModal"
                          data-id="<?php echo htmlspecialchars($a['user_id']); ?>">重置密码</button>
                  <?php endif; ?>
                  <?php if(can('admins','delete') && $a['user_id']!==$_SESSION['admin_id']): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('确认删除管理员 <?php echo htmlspecialchars($a['user_id']); ?>？')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="del_id" value="<?php echo htmlspecialchars($a['user_id']); ?>">
                    <button class="btn btn-xs btn-sm btn-outline-danger py-0 px-2">删除</button>
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
  </div>

  <!-- 权限说明 -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="fas fa-circle-info me-2"></i>权限等级说明</div>
      <div class="card-body">
        <div class="mb-3 p-3 rounded-3" style="background:linear-gradient(135deg,#f59e0b22,#ef444422)">
          <div class="fw-bold mb-1"><span class="badge" style="background:linear-gradient(135deg,#f59e0b,#ef4444)">超级管理员</span></div>
          <ul class="small text-muted mb-0 ps-3">
            <li>绕过所有权限检查</li>
            <li>可添加/删除/修改管理员</li>
            <li>可重置其他管理员密码</li>
            <li>可执行数据清理、备份</li>
          </ul>
        </div>
        <div class="p-3 rounded-3 bg-body-secondary">
          <div class="fw-bold mb-1"><span class="badge bg-secondary">普通管理员</span></div>
          <ul class="small text-muted mb-0 ps-3">
            <li>只能使用被分配的权限</li>
            <li>无法访问权限矩阵</li>
            <li>侧边栏按权限显示/隐藏</li>
          </ul>
        </div>
        <hr>
        <p class="small text-muted mb-0">新管理员默认仅有各模块的"查看"权限，需手动勾选分配其他操作权限。</p>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- 添加管理员弹窗 -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="fas fa-user-plus me-2"></i>添加管理员</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold small">QQ 号 <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="user_id" required placeholder="输入 QQ 号">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">昵称</label>
            <input type="text" class="form-control" name="nickname" placeholder="选填，默认显示QQ号">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">权限等级</label>
            <select class="form-select" name="level">
              <option value="1">普通管理员（默认全只读，需手动分配）</option>
              <?php if(isSuperAdmin()): ?>
              <option value="999">超级管理员（全部权限）</option>
              <?php endif; ?>
            </select>
          </div>
          <div class="alert alert-info border-0 rounded-3 small py-2">
            <i class="fas fa-info-circle me-1"></i>新管理员首次登录时将被要求设置密码。
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-primary px-4"><i class="fas fa-plus me-1"></i>添加</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- 重置密码弹窗 -->
<div class="modal fade" id="resetPwdModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold">重置密码</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="reset_pwd">
        <input type="hidden" name="reset_id" id="resetPwdId">
        <div class="modal-body">
          <p class="small text-muted">为管理员 <strong id="resetPwdName"></strong> 设置新密码</p>
          <input type="password" class="form-control" name="new_pwd" required placeholder="新密码（至少8位）" minlength="8">
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">取消</button>
          <button type="submit" class="btn btn-warning btn-sm px-3">重置</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('resetPwdModal')?.addEventListener('show.bs.modal', function(e){
  var id = e.relatedTarget.dataset.id;
  document.getElementById('resetPwdId').value = id;
  document.getElementById('resetPwdName').textContent = id;
});
function toggleAll(v){
  document.querySelectorAll('.perm-check').forEach(cb=>cb.checked=v);
}
function setViewOnly(){
  document.querySelectorAll('.perm-check').forEach(cb=>{
    cb.checked = cb.name.endsWith('_view');
  });
}
// 权限矩阵复选框加class便于批量操作
document.querySelectorAll('input[type=checkbox][name^=perm_]').forEach(cb=>cb.classList.add('perm-check'));
</script>

<?php require_once 'footer.php'; ?>
