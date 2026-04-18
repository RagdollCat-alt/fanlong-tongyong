<?php
require_once 'config.php';
checkLogin();
requirePermission('game_terms','view');

$db = getDB();
$msg=''; $msg_type='';

// 批量操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    if ($pa === 'save') {
        $is_add   = empty($_POST['orig_key']);
        requirePermission('game_terms', $is_add?'add':'edit');
        $key      = trim($_POST['key']      ?? '');
        $text     = trim($_POST['text']     ?? '');
        $category = trim($_POST['category'] ?? 'other');
        // 机器人约定：is_hidden=1 = 可见（默认），is_hidden=0 = 隐藏
        // 复选框"对玩家隐藏"勾选 → 存 0；不勾 → 存 1
        $hidden     = isset($_POST['is_hidden']) ? 0 : 1;
        $sort_order = intval($_POST['sort_order'] ?? 0);
        if (empty($key)) { $msg='键名不能为空'; $msg_type='danger'; }
        elseif ($is_add && strpos($key, 'profile_') !== 0) { $msg='新增术语键名必须以 profile_ 开头'; $msg_type='danger'; }
        else {
            if ($is_add) $category = '档案配置';
            try {
                $old = null;
                if (!$is_add) { $r=$db->prepare("SELECT * FROM game_terms WHERE key=?"); $r->execute([$_POST['orig_key']]); $old=$r->fetch(); }
                // 编辑时用 UPDATE 保持 rowid 不变；新增时 INSERT
                $exists = $db->prepare("SELECT COUNT(*) FROM game_terms WHERE key=?");
                $exists->execute([$key]);
                if ($exists->fetchColumn()) {
                    $db->prepare("UPDATE game_terms SET text=?,category=?,is_hidden=?,sort_order=? WHERE key=?")->execute([$text,$category,$hidden,$sort_order,$key]);
                } else {
                    $db->prepare("INSERT INTO game_terms (key,text,category,is_hidden,sort_order) VALUES (?,?,?,?,?)")->execute([$key,$text,$category,$hidden,$sort_order]);
                }

                // ——— 自动同步存量数据（仅编辑且文字有变化时）———
                $sync_msg = '';
                if (!$is_add && $old && $old['text'] !== $text && !empty($old['text'])) {
                    $old_text = $old['text'];

                    // Python json.dumps() 默认把中文转成 \uXXXX，PHP json_encode() 同理
                    // 部分地方用 ensure_ascii=False 直接存中文，两种格式都要替换
                    $old_esc  = json_encode($old_text);          // "\u559c\u6076"（含外层引号）
                    $new_esc  = json_encode($text);              // "\u5174\u8da3\u7231\u597d"
                    $old_lit  = '"' . $old_text . '"';           // "喜恶"（直接中文）
                    $new_lit  = '"' . $text . '"';               // "兴趣爱好"

                    if ($category === '档案配置') {
                        // 同步 users.profile（转义格式）
                        $db->prepare("UPDATE users SET profile = REPLACE(profile, ?, ?) WHERE profile LIKE ?")
                           ->execute([$old_esc . ':', $new_esc . ':', '%' . $old_esc . ':%']);
                        $cnt1 = (int)$db->query("SELECT changes()")->fetchColumn();
                        // 同步 users.profile（直接中文格式）
                        $db->prepare("UPDATE users SET profile = REPLACE(profile, ?, ?) WHERE profile LIKE ?")
                           ->execute([$old_lit . ':', $new_lit . ':', '%' . $old_lit . ':%']);
                        $cnt2 = (int)$db->query("SELECT changes()")->fetchColumn();
                        $total = $cnt1 + $cnt2;
                        if ($total > 0) $sync_msg = "，已同步 {$total} 条用户档案";

                    } elseif ($category === '属性配置') {
                        // 同步 items.stats（转义格式）
                        $db->prepare("UPDATE items SET stats = REPLACE(stats, ?, ?) WHERE stats LIKE ?")
                           ->execute([$old_esc . ':', $new_esc . ':', '%' . $old_esc . ':%']);
                        $cnt1 = (int)$db->query("SELECT changes()")->fetchColumn();
                        // 同步 items.stats（直接中文格式）
                        $db->prepare("UPDATE items SET stats = REPLACE(stats, ?, ?) WHERE stats LIKE ?")
                           ->execute([$old_lit . ':', $new_lit . ':', '%' . $old_lit . ':%']);
                        $cnt2 = (int)$db->query("SELECT changes()")->fetchColumn();
                        $total = $cnt1 + $cnt2;
                        if ($total > 0) $sync_msg = "，已同步 {$total} 件物品属性";
                    }
                }

                logAction('game_terms', $is_add?'create':'update', $key, $old, ['text'=>$text,'category'=>$category,'is_hidden'=>$hidden]);
                setFlash('success', '术语已保存' . $sync_msg . '。修改生效后请向机器人发送「重载配置」刷新缓存');
                header('Location: terms.php'); exit();
            } catch(Exception $e){ $msg='保存失败：'.$e->getMessage(); $msg_type='danger'; }
        }
    }

    if ($pa === 'delete') {
        requirePermission('game_terms','delete');
        $k  = $_POST['del_key'] ?? '';
        $old = $db->prepare("SELECT * FROM game_terms WHERE key=?"); $old->execute([$k]); $old=$old->fetch();
        $db->prepare("DELETE FROM game_terms WHERE key=?")->execute([$k]);
        logAction('game_terms','delete',$k,$old);
        setFlash('success','术语已删除'); header('Location: terms.php'); exit();
    }

    if ($pa === 'unhide_all') {
        requirePermission('game_terms','edit');
        // is_hidden=0 = 隐藏；全部恢复为 1（可见）
        $cnt = $db->query("SELECT COUNT(*) FROM game_terms WHERE is_hidden=0")->fetchColumn();
        $db->exec("UPDATE game_terms SET is_hidden=1");
        logAction('game_terms','update','batch',null,['unhide_count'=>$cnt]);
        setFlash('success',"已将 $cnt 条隐藏术语全部设为可见");
        header('Location: terms.php'); exit();
    }
}

$filter_cat = $_GET['cat'] ?? '';
$sql = "SELECT * FROM game_terms";
$params = [];
if ($filter_cat) { $sql .= " WHERE category=?"; $params[] = $filter_cat; }
$sql .= " ORDER BY category, key";
$stmt = $db->prepare($sql); $stmt->execute($params); $terms = $stmt->fetchAll();

$categories  = $db->query("SELECT DISTINCT category FROM game_terms ORDER BY category")->fetchAll(PDO::FETCH_COLUMN, 0);
// is_hidden=0 = 真正的隐藏（对机器人不可见）
$hidden_count = $db->query("SELECT COUNT(*) FROM game_terms WHERE is_hidden=0")->fetchColumn();

$category_labels = [
    '属性配置' => '八维属性',
    '服饰配置' => '装备槽位',
    '档案配置' => '角色档案',
    '金钱配置' => '货币名称',
    '指令配置' => '游戏指令',
    '系统配置' => '系统参数',
    'other'    => '其他',
];

$page_title='术语翻译'; $page_icon='fas fa-language'; $page_subtitle='游戏术语与中文显示名称';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- 说明 -->
<div class="alert alert-info border-0 rounded-3 mb-4 py-2 small">
  <i class="fas fa-language me-2"></i>
  <strong>术语翻译说明：</strong>
  后台所有页面的字段名都通过此表翻译成中文。「键名」是代码中的英文标识，「显示文本」是对玩家/管理员展示的中文名称。
  修改后刷新页面即可生效。<strong>「对用户隐藏」</strong>勾选后该字段不在游戏角色档案中展示给玩家。
</div>

<!-- 分类过滤 & 操作 -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="d-flex gap-2 flex-wrap">
    <a href="terms.php" class="btn btn-sm <?php echo !$filter_cat?'btn-primary':'btn-outline-secondary'; ?>">全部</a>
    <?php foreach($categories as $cat): ?>
    <a href="terms.php?cat=<?php echo urlencode($cat); ?>"
       class="btn btn-sm <?php echo $filter_cat===$cat?'btn-primary':'btn-outline-secondary'; ?>">
      <?php echo htmlspecialchars($category_labels[$cat] ?? $cat); ?>
    </a>
    <?php endforeach; ?>
  </div>
  <div class="d-flex gap-2">
    <?php if($hidden_count > 0 && can('game_terms','edit')): ?>
    <form method="POST" onsubmit="return confirm('将所有隐藏术语设为对用户可见？')">
      <input type="hidden" name="action" value="unhide_all">
      <button class="btn btn-sm btn-outline-warning">
        <i class="fas fa-eye me-1"></i>全部设为可见（<?php echo $hidden_count; ?> 条隐藏）
      </button>
    </form>
    <?php endif; ?>
    <?php if(can('game_terms','add')): ?>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#termModal" onclick="clearTermForm()">
      <i class="fas fa-plus me-1"></i>新增术语
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-list me-2"></i>术语列表（<?php echo count($terms); ?> 条）</div>
  <div class="card-body p-0">
    <table class="table table-hover datatable align-middle mb-0 small">
      <thead><tr class="table-active">
        <th class="ps-4">键名 <span class="text-muted fw-normal">(代码标识)</span></th>
        <th>中文显示名称</th>
        <th>分类</th>
        <th>对用户隐藏</th>
        <th class="text-center">排序</th>
        <th class="pe-4">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach($terms as $term): ?>
      <tr>
        <td class="ps-4"><code class="small"><?php echo htmlspecialchars($term['key']); ?></code></td>
        <td class="fw-semibold"><?php echo htmlspecialchars($term['text']); ?></td>
        <td>
          <span class="badge bg-body-secondary text-body border small">
            <?php echo htmlspecialchars($category_labels[$term['category']] ?? $term['category']); ?>
          </span>
        </td>
        <td>
          <?php if(!$term['is_hidden']): ?>
          <span class="badge bg-warning text-dark"><i class="fas fa-eye-slash me-1"></i>隐藏</span>
          <?php else: ?>
          <span class="text-success small"><i class="fas fa-eye me-1"></i>可见</span>
          <?php endif; ?>
        </td>
        <td class="text-center text-muted small">
          <?php echo $term['category']==='档案配置' ? intval($term['sort_order'] ?? 0) : '—'; ?>
        </td>
        <td class="pe-4">
          <div class="d-flex gap-1">
            <?php if(can('game_terms','edit')): ?>
            <button class="btn btn-sm btn-outline-primary py-0 px-2"
                    onclick="editTerm('<?php echo htmlspecialchars($term['key'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($term['text'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($term['category'],ENT_QUOTES); ?>',<?php echo intval($term['is_hidden']); ?>,<?php echo intval($term['sort_order'] ?? 0); ?>)">
              编辑
            </button>
            <?php endif; ?>
            <?php if(can('game_terms','delete')): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('确认删除术语「<?php echo htmlspecialchars($term['key']); ?>」？')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="del_key" value="<?php echo htmlspecialchars($term['key']); ?>">
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

<!-- 新增/编辑弹窗 -->
<div class="modal fade" id="termModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold" id="termModalTitle">新增术语</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="orig_key" id="origKey">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold small">键名 <span class="text-danger">*</span> <span class="text-muted fw-normal">(代码中使用的英文标识)</span></label>
            <input type="hidden" name="key" id="termKeyFinal">
            <!-- 新增模式：固定 profile_ 前缀 -->
            <div id="keyAddGroup" class="input-group">
              <span class="input-group-text font-monospace text-muted bg-light">profile_</span>
              <input type="text" class="form-control font-monospace" id="termKeySuffix" placeholder="如：hometown" oninput="syncTermKey()">
            </div>
            <!-- 编辑模式：自由编辑 -->
            <input type="text" class="form-control font-monospace" id="termKeyEdit" style="display:none" placeholder="如：stat_face" oninput="syncTermKey()">
            <div class="form-text text-info" id="keyAddNote"><i class="fas fa-info-circle me-1"></i>目前仅支持新增「档案字段」(<code>profile_</code>)，属性/槽位/货币等字段须在代码中配置。</div>
            <div class="form-text" id="keyEditNote" style="display:none">命名规范：属性用 stat_、槽位用 slot_、档案用 profile_、货币用 term_</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">中文显示名称</label>
            <input type="text" class="form-control" name="text" id="termText" placeholder="如：颜值">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">分类</label>
            <select class="form-select" name="category" id="termCat">
              <?php foreach($category_labels as $cv=>$cl): ?>
              <option value="<?php echo $cv; ?>"><?php echo $cl; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3" id="sortOrderRow">
            <label class="form-label fw-semibold small">展示排序 <span class="text-muted fw-normal">（数字越小越靠前，0 = 按添加顺序）</span></label>
            <input type="number" class="form-control" name="sort_order" id="termSortOrder" value="0" min="0" style="width:120px">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_hidden" value="1" id="termHidden">
            <label class="form-check-label">对玩家隐藏（不在角色档案中展示）</label>
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
function syncTermKey(){
  const isAdd = !document.getElementById('origKey').value;
  if(isAdd){
    const s = document.getElementById('termKeySuffix').value.trim();
    document.getElementById('termKeyFinal').value = s ? 'profile_' + s : '';
  } else {
    document.getElementById('termKeyFinal').value = document.getElementById('termKeyEdit').value.trim();
  }
}
function clearTermForm(){
  document.getElementById('origKey').value = '';
  document.getElementById('termText').value = '';
  document.getElementById('termKeySuffix').value = '';
  document.getElementById('termKeyFinal').value = '';
  document.getElementById('termSortOrder').value = '0';
  // 新增模式：显示 profile_ 前缀输入组
  document.getElementById('keyAddGroup').style.display = '';
  document.getElementById('termKeySuffix').required = true;
  document.getElementById('termKeyEdit').style.display = 'none';
  document.getElementById('termKeyEdit').required = false;
  document.getElementById('keyAddNote').style.display = '';
  document.getElementById('keyEditNote').style.display = 'none';
  document.getElementById('termCat').value = '档案配置';
  document.getElementById('termHidden').checked = false;
  document.getElementById('sortOrderRow').style.display = '';
  document.getElementById('termModalTitle').textContent = '新增档案字段';
}
function editTerm(key,text,cat,hidden,sortOrder){
  document.getElementById('origKey').value = key;
  document.getElementById('termKeyFinal').value = key;
  document.getElementById('termText').value = text;
  document.getElementById('termSortOrder').value = sortOrder || 0;
  // 编辑模式：显示自由编辑输入框
  document.getElementById('keyAddGroup').style.display = 'none';
  document.getElementById('termKeySuffix').required = false;
  document.getElementById('termKeyEdit').style.display = '';
  document.getElementById('termKeyEdit').value = key;
  document.getElementById('termKeyEdit').required = true;
  document.getElementById('keyAddNote').style.display = 'none';
  document.getElementById('keyEditNote').style.display = '';
  document.getElementById('termCat').value = cat || 'other';
  document.getElementById('termHidden').checked = hidden == 0;
  // 档案/属性/槽位字段显示排序输入
  const showSort = ['档案配置','属性配置','服饰配置'].includes(cat);
  document.getElementById('sortOrderRow').style.display = showSort ? '' : 'none';
  document.getElementById('termModalTitle').textContent = '编辑术语';
  new bootstrap.Modal(document.getElementById('termModal')).show();
}
</script>

<?php require_once 'footer.php'; ?>
