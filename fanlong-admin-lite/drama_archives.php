<?php
require_once 'config.php';
checkLogin();
requirePermission('drama_archives', 'view');

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$msg = ''; $msg_type = '';

// ===== POST 处理 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    if ($pa === 'save') {
        $is_add      = empty($_POST['orig_id']);
        requirePermission('drama_archives', $is_add ? 'add' : 'edit');
        $title        = trim($_POST['title']        ?? '');
        $date_str     = trim($_POST['date_str']     ?? '');
        $content      = trim($_POST['content']      ?? '');
        $participants = trim($_POST['participants'] ?? '');
        $note         = trim($_POST['note']         ?? '');
        $recorder     = trim($_POST['recorder']     ?? '');
        $group_id     = trim($_POST['group_id']     ?? '');

        if (empty($title)) { $msg = '标题不能为空'; $msg_type = 'danger'; }
        else {
            try {
                if ($is_add) {
                    $db->prepare("INSERT INTO drama_archives (title,date_str,content,participants,note,recorder,group_id) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$title,$date_str,$content,$participants,$note,$recorder,$group_id]);
                    $new_id = $db->lastInsertId();
                    logAction('drama_archives','create',$new_id,null,['title'=>$title,'recorder'=>$recorder]);
                    setFlash('success','剧情档案「'.$title.'」已创建');
                } else {
                    $oid = intval($_POST['orig_id']);
                    $old = $db->prepare("SELECT * FROM drama_archives WHERE id=?"); $old->execute([$oid]); $old=$old->fetch();
                    $db->prepare("UPDATE drama_archives SET title=?,date_str=?,content=?,participants=?,note=?,recorder=?,group_id=? WHERE id=?")
                       ->execute([$title,$date_str,$content,$participants,$note,$recorder,$group_id,$oid]);
                    logAction('drama_archives','update',$oid,$old,['title'=>$title]);
                    setFlash('success','剧情档案已更新');
                }
                header('Location: drama_archives.php'); exit();
            } catch(Exception $e){ $msg = '保存失败：'.$e->getMessage(); $msg_type = 'danger'; }
        }
    }

    if ($pa === 'soft_delete') {
        requirePermission('drama_archives','delete');
        $did = intval($_POST['del_id'] ?? 0);
        $db->prepare("UPDATE drama_archives SET is_deleted=1 WHERE id=?")->execute([$did]);
        logAction('drama_archives','delete',$did);
        setFlash('success','档案已归档删除（可在「显示已删除」中恢复）');
        header('Location: drama_archives.php'); exit();
    }

    if ($pa === 'restore') {
        requirePermission('drama_archives','edit');
        $rid = intval($_POST['restore_id'] ?? 0);
        $db->prepare("UPDATE drama_archives SET is_deleted=0 WHERE id=?")->execute([$rid]);
        logAction('drama_archives','restore',$rid);
        setFlash('success','档案已恢复');
        header('Location: drama_archives.php?show_deleted=1'); exit();
    }

    if ($pa === 'hard_delete') {
        requirePermission('drama_archives','delete');
        if (!isSuperAdmin()) { setFlash('danger','仅超级管理员可永久删除'); header('Location: drama_archives.php'); exit(); }
        $hid = intval($_POST['hard_id'] ?? 0);
        $old = $db->prepare("SELECT * FROM drama_archives WHERE id=?"); $old->execute([$hid]); $old=$old->fetch();
        $db->prepare("DELETE FROM drama_archives WHERE id=?")->execute([$hid]);
        logAction('drama_archives','delete',$hid,$old);
        setFlash('success','档案已永久删除');
        header('Location: drama_archives.php'); exit();
    }
}

// 获取编辑记录
$edit_row = null;
if (in_array($action, ['edit','view']) && $id > 0) {
    $r = $db->prepare("SELECT * FROM drama_archives WHERE id=?"); $r->execute([$id]); $edit_row = $r->fetch();
    if (!$edit_row) { $msg = '找不到该档案'; $msg_type = 'danger'; $action = 'list'; }
}

// 列表查询
$show_deleted = !empty($_GET['show_deleted']);
$filter_title    = trim($_GET['title']    ?? '');
$filter_recorder = trim($_GET['recorder'] ?? '');
$filter_group    = trim($_GET['group']    ?? '');
$page     = max(1, intval($_GET['p'] ?? 1));
$page_size = 20;

$archives = [];
if ($action === 'list') {
    $sql    = "SELECT id,title,date_str,participants,recorder,group_id,is_deleted,created_at FROM drama_archives WHERE is_deleted=?";
    $params = [$show_deleted ? 1 : 0];
    if ($filter_title)    { $sql .= " AND title LIKE ?";    $params[] = "%$filter_title%"; }
    if ($filter_recorder) { $sql .= " AND recorder LIKE ?"; $params[] = "%$filter_recorder%"; }
    if ($filter_group)    { $sql .= " AND group_id=?";      $params[] = $filter_group; }

    $cnt_stmt = $db->prepare(str_replace("SELECT id,title,date_str,participants,recorder,group_id,is_deleted,created_at","SELECT COUNT(*)",$sql));
    $cnt_stmt->execute($params);
    $total       = $cnt_stmt->fetchColumn();
    $total_pages = max(1, ceil($total / $page_size));

    $sql .= " ORDER BY id DESC LIMIT $page_size OFFSET " . (($page-1)*$page_size);
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $archives = $stmt->fetchAll();
}

// 所有群号（用于筛选）
$all_groups = $db->query("SELECT DISTINCT group_id FROM drama_archives WHERE group_id!='' ORDER BY group_id")->fetchAll(PDO::FETCH_COLUMN, 0);
// 统计
$total_count   = $db->query("SELECT COUNT(*) FROM drama_archives WHERE is_deleted=0")->fetchColumn();
$deleted_count = $db->query("SELECT COUNT(*) FROM drama_archives WHERE is_deleted=1")->fetchColumn();

$page_title    = '剧情档案';
$page_icon     = 'fas fa-book-open';
$page_subtitle = '存戏记录管理';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- 统计卡片 -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-primary"><?php echo $total_count; ?></div>
      <div class="text-muted small">有效档案数</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-secondary"><?php echo $deleted_count; ?></div>
      <div class="text-muted small">已归档删除</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card text-center"><div class="card-body py-3">
      <div class="fs-4 fw-bold text-info"><?php echo count($all_groups); ?></div>
      <div class="text-muted small">群组数量</div>
    </div></div>
  </div>
</div>

<!-- 使用说明 -->
<div class="alert alert-info border-0 rounded-3 mb-4 py-2 small">
  <i class="fas fa-book me-2"></i>
  <strong>剧情档案说明：</strong>
  玩家在群内使用「存戏」指令保存的 RP 剧情记录。「参与者」栏为剧情中的角色（以 | 分隔）。
  「删除」为软删除，档案仍可恢复；超级管理员可永久删除。
</div>

<!-- 过滤 -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label fw-semibold small">标题搜索</label>
        <input type="text" class="form-control form-control-sm" name="title" value="<?php echo htmlspecialchars($filter_title); ?>" placeholder="标题关键词">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold small">记录者</label>
        <input type="text" class="form-control form-control-sm" name="recorder" value="<?php echo htmlspecialchars($filter_recorder); ?>" placeholder="QQ号或角色名">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold small">群组</label>
        <select class="form-select form-select-sm" name="group">
          <option value="">全部群</option>
          <?php foreach($all_groups as $g): ?>
          <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $filter_group===$g?'selected':''; ?>><?php echo htmlspecialchars($g); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-auto">
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="show_deleted" id="showDel" value="1" <?php echo $show_deleted?'checked':''; ?>>
          <label class="form-check-label small" for="showDel">显示已删除</label>
        </div>
      </div>
      <div class="col-md d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>搜索</button>
        <a href="drama_archives.php" class="btn btn-sm btn-outline-secondary">重置</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list me-2"></i>共 <?php echo number_format($total); ?> 条档案</span>
    <div class="d-flex gap-2 align-items-center">
      <span class="text-muted small">第 <?php echo $page; ?>/<?php echo $total_pages; ?> 页</span>
      <?php if(can('drama_archives','add')): ?>
      <a href="drama_archives.php?action=add" class="btn btn-sm btn-primary">
        <i class="fas fa-plus me-1"></i>新增档案
      </a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead><tr class="table-active">
          <th class="ps-4" style="width:50px">ID</th>
          <th>标题</th>
          <th>剧情日期</th>
          <th>参与角色</th>
          <th>记录者</th>
          <th>群组</th>
          <th>收录时间</th>
          <th class="pe-4">操作</th>
        </tr></thead>
        <tbody>
        <?php foreach($archives as $arc): ?>
        <tr <?php echo $arc['is_deleted']?'class="table-secondary"':''; ?>>
          <td class="ps-4 text-muted"><?php echo $arc['id']; ?></td>
          <td>
            <div class="fw-semibold">
              <?php if($arc['is_deleted']): ?><s class="text-muted"><?php endif; ?>
              <?php echo htmlspecialchars($arc['title']); ?>
              <?php if($arc['is_deleted']): ?></s><span class="badge bg-secondary ms-1">已删除</span><?php endif; ?>
            </div>
          </td>
          <td class="text-muted"><?php echo htmlspecialchars($arc['date_str']??'—'); ?></td>
          <td>
            <?php
            $parts = array_filter(array_map('trim', explode('|', $arc['participants']??'')));
            foreach(array_slice($parts, 0, 3) as $p):
            ?>
            <span class="badge bg-body-secondary text-body border small me-1"><?php echo htmlspecialchars($p); ?></span>
            <?php endforeach;
            if(count($parts)>3) echo '<span class="text-muted small">+' . (count($parts)-3) . '…</span>';
            if(empty($parts)) echo '<span class="text-muted">—</span>';
            ?>
          </td>
          <td class="text-muted"><?php echo htmlspecialchars($arc['recorder']??'—'); ?></td>
          <td class="text-muted"><?php echo htmlspecialchars($arc['group_id']??'—'); ?></td>
          <td class="text-muted"><?php echo substr($arc['created_at']??'',0,10); ?></td>
          <td class="pe-4">
            <div class="d-flex gap-1">
              <a href="drama_archives.php?action=view&id=<?php echo $arc['id']; ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">查看</a>
              <?php if(!$arc['is_deleted']): ?>
                <?php if(can('drama_archives','edit')): ?>
                <a href="drama_archives.php?action=edit&id=<?php echo $arc['id']; ?>" class="btn btn-sm btn-outline-primary py-0 px-2">编辑</a>
                <?php endif; ?>
                <?php if(can('drama_archives','delete')): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('删除档案「<?php echo htmlspecialchars($arc['title']); ?>」？（可恢复）')">
                  <input type="hidden" name="action" value="soft_delete">
                  <input type="hidden" name="del_id" value="<?php echo $arc['id']; ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2">删除</button>
                </form>
                <?php endif; ?>
              <?php else: ?>
                <?php if(can('drama_archives','edit')): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="restore">
                  <input type="hidden" name="restore_id" value="<?php echo $arc['id']; ?>">
                  <button class="btn btn-sm btn-outline-success py-0 px-2">恢复</button>
                </form>
                <?php endif; ?>
                <?php if(isSuperAdmin()): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('永久删除？此操作不可撤销！')">
                  <input type="hidden" name="action" value="hard_delete">
                  <input type="hidden" name="hard_id" value="<?php echo $arc['id']; ?>">
                  <button class="btn btn-sm btn-danger py-0 px-2">永久删除</button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($archives)): ?>
        <tr><td colspan="8" class="text-center py-5 text-muted">暂无档案记录</td></tr>
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

<?php elseif ($action === 'view' && $edit_row): ?>
<!-- ===== 查看档案 ===== -->
<div class="d-flex justify-content-between mb-4">
  <div></div>
  <div class="d-flex gap-2">
    <?php if(can('drama_archives','edit') && !$edit_row['is_deleted']): ?>
    <a href="drama_archives.php?action=edit&id=<?php echo $edit_row['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-pen me-1"></i>编辑</a>
    <?php endif; ?>
    <a href="drama_archives.php" class="btn btn-outline-secondary btn-sm">返回列表</a>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-info-circle me-2"></i>档案信息</div>
      <div class="card-body small">
        <dl class="row mb-0">
          <dt class="col-4 text-muted">档案 ID</dt><dd class="col-8"><?php echo $edit_row['id']; ?></dd>
          <dt class="col-4 text-muted">标题</dt><dd class="col-8 fw-semibold"><?php echo htmlspecialchars($edit_row['title']); ?></dd>
          <dt class="col-4 text-muted">剧情日期</dt><dd class="col-8"><?php echo htmlspecialchars($edit_row['date_str']??'—'); ?></dd>
          <dt class="col-4 text-muted">记录者</dt><dd class="col-8"><?php echo htmlspecialchars($edit_row['recorder']??'—'); ?></dd>
          <dt class="col-4 text-muted">群组</dt><dd class="col-8"><?php echo htmlspecialchars($edit_row['group_id']??'—'); ?></dd>
          <dt class="col-4 text-muted">收录时间</dt><dd class="col-8"><?php echo htmlspecialchars($edit_row['created_at']??'—'); ?></dd>
        </dl>
      </div>
    </div>
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-users me-2"></i>参与角色</div>
      <div class="card-body">
        <?php
        $parts = array_filter(array_map('trim', explode('|', $edit_row['participants']??'')));
        foreach($parts as $p):
        ?>
        <span class="badge bg-body-secondary text-body border rounded-pill px-3 py-2 me-1 mb-1"><?php echo htmlspecialchars($p); ?></span>
        <?php endforeach;
        if(empty($parts)) echo '<p class="text-muted small mb-0">无参与者信息</p>';
        ?>
      </div>
    </div>
    <?php if(!empty($edit_row['note'])): ?>
    <div class="card">
      <div class="card-header"><i class="fas fa-sticky-note me-2"></i>备注</div>
      <div class="card-body small"><?php echo nl2br(htmlspecialchars($edit_row['note'])); ?></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><i class="fas fa-scroll me-2"></i>剧情正文</div>
      <div class="card-body">
        <div style="white-space:pre-wrap;line-height:1.8;font-size:.9rem;max-height:70vh;overflow-y:auto"><?php echo htmlspecialchars($edit_row['content']??''); ?></div>
      </div>
    </div>
  </div>
</div>

<?php elseif (in_array($action, ['edit','add'])): ?>
<!-- ===== 新增/编辑 ===== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-pen me-2"></i><?php echo $action==='add'?'新增剧情档案':'编辑档案：'.htmlspecialchars($edit_row['title']??''); ?></span>
    <a href="drama_archives.php" class="btn btn-sm btn-outline-secondary">返回列表</a>
  </div>
  <div class="card-body">
    <div class="alert alert-light border rounded-3 small mb-4">
      <i class="fas fa-lightbulb me-2 text-primary"></i>
      <strong>填写说明：</strong>
      「参与角色」格式为 <code>角色A|角色B</code>，以竖线分隔；「剧情日期」建议使用游戏内时间（如 2025年1月3日）；「记录者」填写 QQ 号或角色名。
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="orig_id" value="<?php echo $edit_row['id']??''; ?>">
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label fw-semibold small">标题 <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="title" required
                 value="<?php echo htmlspecialchars($edit_row['title']??''); ?>" placeholder="如：三日训诫">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold small">剧情日期</label>
          <input type="text" class="form-control" name="date_str"
                 value="<?php echo htmlspecialchars($edit_row['date_str']??''); ?>" placeholder="如：2025年1月3日">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold small">群组编号</label>
          <select class="form-select" name="group_id">
            <option value="">不指定</option>
            <?php foreach($all_groups as $g): ?>
            <option value="<?php echo htmlspecialchars($g); ?>" <?php echo ($edit_row['group_id']??'')===$g?'selected':''; ?>><?php echo htmlspecialchars($g); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small">参与角色 <span class="text-muted">(竖线 | 分隔)</span></label>
          <input type="text" class="form-control" name="participants"
                 value="<?php echo htmlspecialchars($edit_row['participants']??''); ?>"
                 placeholder="如：虞景vs奚行简|角色B">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold small">记录者</label>
          <input type="text" class="form-control" name="recorder"
                 value="<?php echo htmlspecialchars($edit_row['recorder']??''); ?>" placeholder="QQ号或角色名">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold small">备注</label>
          <input type="text" class="form-control" name="note"
                 value="<?php echo htmlspecialchars($edit_row['note']??''); ?>" placeholder="选填">
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">剧情正文</label>
          <textarea class="form-control" name="content" rows="16"
                    placeholder="在此粘贴或输入剧情内容..."
                    style="font-size:.875rem;line-height:1.7"><?php echo htmlspecialchars($edit_row['content']??''); ?></textarea>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-2"></i>保存档案</button>
        <a href="drama_archives.php" class="btn btn-outline-secondary">取消</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
