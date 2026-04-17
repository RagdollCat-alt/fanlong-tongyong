<?php
require_once 'config.php';
checkLogin();
requirePermission('users', 'view');

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = $_GET['id']     ?? '';
$msg = ''; $msg_type = '';

// ===== POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'save') {
        $is_add = empty($_POST['orig_id']);
        requirePermission('users', $is_add ? 'add' : 'edit');

        $user_id  = trim($_POST['user_id']  ?? '');
        $uid      = trim($_POST['uid']      ?? '');
        $name     = trim($_POST['name']     ?? '');
        $currency = trim($_POST['currency'] ?? '{}');
        $profile  = trim($_POST['profile']  ?? '{}');
        $limits   = trim($_POST['limits']   ?? '{}');

        // 验证JSON
        $cur_arr = safeJsonDecode($currency); if (empty($cur_arr)) $currency = '{}';
        $pro_arr = safeJsonDecode($profile);  if (empty($pro_arr)) $profile  = '{}';
        $lim_arr = safeJsonDecode($limits);   if (empty($lim_arr)) $limits   = '{}';

        try {
            if ($is_add) {
                $old = null;
                $db->prepare("INSERT INTO users (id,uid,name,currency,profile,limits) VALUES (?,?,?,?,?,?)")
                   ->execute([$user_id,$uid,$name,$currency,$profile,$limits]);
                logAction('users','create',$user_id,null,['uid'=>$uid,'name'=>$name]);
                setFlash('success','用户 '.$name.' 已创建');
            } else {
                $old = $db->prepare("SELECT * FROM users WHERE id=?");
                $old->execute([$_POST['orig_id']]); $old=$old->fetch();
                $db->prepare("UPDATE users SET uid=?,name=?,currency=?,profile=?,limits=? WHERE id=?")
                   ->execute([$uid,$name,$currency,$profile,$limits,$_POST['orig_id']]);
                logAction('users','update',$_POST['orig_id'],$old,['uid'=>$uid,'name'=>$name,'currency'=>$currency]);
                setFlash('success','用户 '.$name.' 已更新');
            }
            header('Location: users.php'); exit();
        } catch(Exception $e){ $msg='保存失败：'.$e->getMessage(); $msg_type='danger'; }
    }

    if ($post_action === 'delete') {
        requirePermission('users','delete');
        $del_id = $_POST['del_id'] ?? '';
        $old = $db->prepare("SELECT * FROM users WHERE id=?"); $old->execute([$del_id]); $old=$old->fetch();
        try {
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$del_id]);
            $db->prepare("DELETE FROM user_stats WHERE user_id=?")->execute([$del_id]);
            $db->prepare("DELETE FROM user_bag WHERE user_id=?")->execute([$del_id]);
            $db->prepare("DELETE FROM user_equip WHERE user_id=?")->execute([$del_id]);
            logAction('users','delete',$del_id,$old);
            setFlash('success','用户已删除（相关属性/背包/装备数据一并清除）');
            header('Location: users.php'); exit();
        } catch(Exception $e){ $msg='删除失败：'.$e->getMessage(); $msg_type='danger'; }
    }
}

// 查看/编辑 单个用户
$edit_user = null;
if (in_array($action, ['view','edit','add'])) {
    if ($action !== 'add' && !empty($id)) {
        $r = $db->prepare("SELECT * FROM users WHERE id=?"); $r->execute([$id]); $edit_user = $r->fetch();
        if (!$edit_user) { $msg='找不到该用户'; $msg_type='danger'; $action='list'; }
    }
}

// 用户列表
$users = []; $rank_list = [];
$stat_fields_list = getVisibleStatFields(); // 只取对玩家可见的属性字段
if ($action === 'list') {
    // 动态拼接 total_stats，仅统计可见属性
    $total_sql = implode('+', array_map(fn($sf)=>"COALESCE(s.$sf,0)", $stat_fields_list));
    $users = $db->query("SELECT u.id,u.uid,u.name,u.currency,u.created_at,
        ($total_sql) AS total_stats
        FROM users u LEFT JOIN user_stats s ON u.id=s.user_id ORDER BY u.created_at DESC")->fetchAll();

    // ——— 排行榜：实战属性（基础 + 装备加成）———
    $all_stats_rows = $db->query("SELECT * FROM user_stats")->fetchAll();
    $all_equip_rows = $db->query("SELECT * FROM user_equip")->fetchAll();
    $all_item_rows  = $db->query("SELECT name,stats FROM items WHERE stats IS NOT NULL AND stats!='' AND stats!='{}'")->fetchAll();
    // 物品属性字典（key = 物品名）
    $item_stats_map = [];
    foreach ($all_item_rows as $ai) {
        $item_stats_map[$ai['name']] = safeJsonDecode($ai['stats'] ?: '{}');
    }
    // 属性中文名 → 英文键 反向映射（ALL_STAT_FIELDS 全集，装备可能包含隐藏属性）
    $stat_cn_map_list = [];
    foreach (ALL_STAT_FIELDS as $sf) { $stat_cn_map_list[t($sf, $sf)] = $sf; }
    // 每个用户的基础属性
    $user_base_map = [];
    foreach ($all_stats_rows as $as) {
        $user_base_map[$as['user_id']] = $as;
    }
    // 每个用户的装备加成合计（全字段跟踪，排行只取可见部分求和）
    $user_bonus_map = [];
    foreach ($all_equip_rows as $ae) {
        $bonus = array_fill_keys(ALL_STAT_FIELDS, 0);
        foreach (['hair','top','bottom','head','neck','inner1','inner2','acc1','acc2','acc3','acc4'] as $sl) {
            $iname = $ae[$sl] ?? null;
            if (!$iname || !isset($item_stats_map[$iname])) continue;
            foreach ($item_stats_map[$iname] as $ikey => $ival) {
                $canon = $stat_cn_map_list[$ikey] ?? (in_array($ikey, ALL_STAT_FIELDS) ? $ikey : null);
                if ($canon) $bonus[$canon] += intval($ival);
            }
        }
        $user_bonus_map[$ae['user_id']] = $bonus;
    }
    // 构建排行列表（合计仅取可见属性）
    $user_name_map = array_column($users, 'name', 'id');
    foreach ($users as $u) {
        $uid   = $u['id'];
        $base  = $user_base_map[$uid]  ?? null;
        $bonus = $user_bonus_map[$uid] ?? array_fill_keys(ALL_STAT_FIELDS, 0);
        $base_total  = array_sum(array_map(fn($sf)=>intval($base[$sf]??0), $stat_fields_list));
        $bonus_total = array_sum(array_map(fn($sf)=>$bonus[$sf]??0, $stat_fields_list));
        $rank_list[] = [
            'id'          => $uid,
            'name'        => $u['name'] ?? $uid,
            'base_total'  => $base_total,
            'bonus_total' => $bonus_total,
            'real_total'  => $base_total + $bonus_total,
            'base'        => $base,
            'bonus'       => $bonus,
        ];
    }
    usort($rank_list, fn($a,$b)=>$b['real_total']-$a['real_total']);
}

$page_title = '用户管理';
$page_icon  = 'fas fa-users';
$page_subtitle = $action==='list'?'用户列表':($action==='add'?'新增用户':($action==='edit'?'编辑用户':'查看用户'));
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible">
  <?php echo htmlspecialchars($msg); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- ===== 用户列表 ===== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list me-2"></i>共 <?php echo count($users); ?> 名用户</span>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#rankModal">
        <i class="fas fa-trophy me-1"></i>属性排行榜
      </button>
      <?php if(can('users','add')): ?>
      <a href="users.php?action=add" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i>新增用户</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover datatable align-middle mb-0">
        <thead><tr class="table-active">
          <th class="ps-4">QQ 号</th><th>UID</th><th>角色名</th>
          <th>虞元</th><th>名誉</th><th>总属性</th>
          <th>注册时间</th><th class="pe-4">操作</th>
        </tr></thead>
        <tbody>
        <?php foreach($users as $u):
          $cur   = safeJsonDecode($u['currency']);
          // 优先 yuCoin，再兼容旧键名
          $money = $cur['yuCoin'] ?? $cur['虞元'] ?? $cur['yuyuan'] ?? 0;
          $rep   = $cur['reputation'] ?? $cur['名誉'] ?? 0;
        ?>
        <tr>
          <td class="ps-4"><code><?php echo htmlspecialchars($u['id']); ?></code></td>
          <td class="text-muted small"><?php echo htmlspecialchars($u['uid'] ?? '—'); ?></td>
          <td class="fw-semibold"><?php echo htmlspecialchars($u['name'] ?? '—'); ?></td>
          <td><?php echo number_format(intval($money)); ?></td>
          <td><?php echo intval($rep) > 0 ? '<span class="text-info">'.number_format(intval($rep)).'</span>' : '<span class="text-muted">0</span>'; ?></td>
          <td><span class="badge bg-primary rounded-pill"><?php echo intval($u['total_stats']); ?></span></td>
          <td class="text-muted small"><?php echo htmlspecialchars(substr($u['created_at']??'',0,10)); ?></td>
          <td class="pe-4">
            <div class="d-flex gap-1">
              <a href="users.php?action=view&id=<?php echo urlencode($u['id']); ?>" class="btn btn-sm btn-outline-secondary py-0 px-2">查看</a>
              <?php if(can('users','edit')): ?>
              <a href="users.php?action=edit&id=<?php echo urlencode($u['id']); ?>" class="btn btn-sm btn-outline-primary py-0 px-2">编辑</a>
              <?php endif; ?>
              <?php if(can('users','delete')): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('确认删除用户 <?php echo htmlspecialchars($u['name']??$u['id']); ?>？此操作同时清除其属性/背包/装备！')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="del_id" value="<?php echo htmlspecialchars($u['id']); ?>">
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
</div>

<!-- ===== 属性排行榜 弹窗 ===== -->
<div class="modal fade" id="rankModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="fas fa-trophy text-warning me-2"></i>属性排行榜 <small class="text-muted fw-normal fs-6">实战属性 = 基础 + 装备加成</small></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <?php if(empty($rank_list)): ?>
        <p class="text-muted text-center py-4">暂无数据</p>
        <?php else: ?>
        <table class="table table-sm align-middle mb-0">
          <thead><tr class="table-active small">
            <th class="ps-2" style="width:40px">名次</th>
            <th>角色名</th>
            <?php foreach($stat_fields_list as $sf): ?>
            <th class="text-center" title="<?php echo t($sf,$sf); ?>"><?php echo mb_substr(t($sf,$sf),0,1); ?></th>
            <?php endforeach; ?>
            <th class="text-end pe-2">实战合计</th>
          </tr></thead>
          <tbody>
          <?php foreach($rank_list as $ri=>$rv): ?>
          <tr class="<?php echo $ri===0?'table-warning':($ri===1?'table-light':($ri===2?'':''));?>">
            <td class="ps-2 fw-bold">
              <?php if($ri===0): ?><i class="fas fa-medal text-warning"></i>
              <?php elseif($ri===1): ?><i class="fas fa-medal text-secondary"></i>
              <?php elseif($ri===2): ?><i class="fas fa-medal" style="color:#cd7f32"></i>
              <?php else: ?><span class="text-muted small"><?php echo $ri+1; ?></span>
              <?php endif; ?>
            </td>
            <td>
              <a href="users.php?action=view&id=<?php echo urlencode($rv['id']); ?>" class="text-decoration-none fw-semibold" target="_self">
                <?php echo htmlspecialchars($rv['name']); ?>
              </a>
            </td>
            <?php foreach($stat_fields_list as $sf):
              $bv = intval($rv['base'][$sf]??0);
              $bon = intval($rv['bonus'][$sf]);
            ?>
            <td class="text-center small">
              <span class="text-muted"><?php echo $bv; ?></span>
              <?php if($bon>0): ?><span class="text-success" style="font-size:.7rem">+<?php echo $bon; ?></span><?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td class="text-end pe-2 fw-bold">
              <?php echo number_format($rv['real_total']); ?>
              <?php if($rv['bonus_total']>0): ?>
              <div class="text-success" style="font-size:.7rem">+<?php echo $rv['bonus_total']; ?></div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <div class="modal-footer border-0 pt-0">
        <small class="text-muted me-auto">
          <?php
          $col_headers = array_map(fn($sf)=>t($sf,$sf), $stat_fields_list);
          echo '列缩写：' . implode('、', array_map(fn($sf)=>mb_substr(t($sf,$sf),0,1).'='.t($sf,$sf), $stat_fields_list));
          ?>
        </small>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<?php elseif ($action === 'view' && $edit_user): ?>
<!-- ===== 用户详情 ===== -->
<?php
$cur  = safeJsonDecode($edit_user['currency']);
$prof = safeJsonDecode($edit_user['profile']);
$lim  = safeJsonDecode($edit_user['limits']);
$stats_r = $db->prepare("SELECT * FROM user_stats WHERE user_id=?"); $stats_r->execute([$edit_user['id']]); $stats_row=$stats_r->fetch();
$equip_r = $db->prepare("SELECT * FROM user_equip WHERE user_id=?"); $equip_r->execute([$edit_user['id']]); $equip_row=$equip_r->fetch();
$bag_r   = $db->prepare("SELECT item_name,count FROM user_bag WHERE user_id=? ORDER BY item_name"); $bag_r->execute([$edit_user['id']]); $bag_items=$bag_r->fetchAll();
$stat_fields = getVisibleStatFields(); // 只取可见属性用于展示和合计
$slot_fields=['hair','top','bottom','head','neck','inner1','inner2','acc1','acc2','acc3','acc4'];

// ——— 属性中文名 → 英文键 反向映射（全字段，装备可能含隐藏属性）———
$stat_cn_map = [];
foreach (ALL_STAT_FIELDS as $sf) { $stat_cn_map[t($sf, $sf)] = $sf; }

// ——— 计算装备加成（全字段跟踪，显示时只用可见字段）———
$equip_bonus = array_fill_keys(ALL_STAT_FIELDS, 0);
if ($equip_row) {
    $equipped_counts = [];
    foreach ($slot_fields as $sl) {
        $n = $equip_row[$sl] ?? null;
        if ($n !== null && $n !== '') {
            $equipped_counts[$n] = ($equipped_counts[$n] ?? 0) + 1;
        }
    }
    if (!empty($equipped_counts)) {
        $unique_names = array_keys($equipped_counts);
        $ph = implode(',', array_fill(0, count($unique_names), '?'));
        $ir = $db->prepare("SELECT name,stats FROM items WHERE name IN ($ph)");
        $ir->execute($unique_names);
        foreach ($ir->fetchAll() as $itm) {
            $istats = safeJsonDecode($itm['stats'] ?: '{}');
            $multiplier = $equipped_counts[$itm['name']] ?? 1;
            foreach ($istats as $ikey => $ival) {
                $canon = $stat_cn_map[$ikey] ?? (in_array($ikey, ALL_STAT_FIELDS) ? $ikey : null);
                if ($canon) $equip_bonus[$canon] += intval($ival) * $multiplier;
            }
        }
    }
}
// has_bonus：只看可见属性有无加成
$has_bonus = false;
foreach ($stat_fields as $sf) { if (($equip_bonus[$sf]??0) != 0) { $has_bonus = true; break; } }
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div></div>
  <div class="d-flex gap-2">
    <?php if(can('users','edit')): ?>
    <a href="users.php?action=edit&id=<?php echo urlencode($edit_user['id']); ?>" class="btn btn-primary btn-sm"><i class="fas fa-pen me-1"></i>编辑</a>
    <?php endif; ?>
    <a href="users.php" class="btn btn-outline-secondary btn-sm">返回列表</a>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-id-card me-2"></i>基本信息</div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-5 text-muted">QQ 号</dt><dd class="col-7"><code><?php echo htmlspecialchars($edit_user['id']); ?></code></dd>
          <dt class="col-5 text-muted">UID</dt><dd class="col-7"><?php echo htmlspecialchars($edit_user['uid']??'—'); ?></dd>
          <dt class="col-5 text-muted">角色名</dt><dd class="col-7 fw-semibold"><?php echo htmlspecialchars($edit_user['name']??'—'); ?></dd>
          <dt class="col-5 text-muted">注册时间</dt><dd class="col-7"><?php echo htmlspecialchars($edit_user['created_at']??'—'); ?></dd>
        </dl>
      </div>
    </div>
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-coins me-2"></i>货币资产</div>
      <div class="card-body">
        <?php foreach($cur as $k=>$v): ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-muted small"><?php echo htmlspecialchars(tAuto($k, $k)); ?></span>
          <span class="badge bg-warning text-dark"><?php echo number_format(floatval($v), is_float($v+0)?1:0); ?></span>
        </div>
        <?php endforeach; ?>
        <?php if(empty($cur)): ?><p class="text-muted small mb-0">无货币数据</p><?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="fas fa-shirt me-2"></i>当前装备</div>
      <div class="card-body">
        <?php
        $_slot_vis = getSlotFieldsVisibility();
        foreach($slot_fields as $slot):
            $item_on  = $equip_row[$slot] ?? null;
            if(!$item_on) continue; // 空槽不展示
            // is_hidden: 1=可见, 0=隐藏；若 term 不存在则默认可见
            $_sv = $_slot_vis[$slot] ?? 1;
        ?>
        <div class="d-flex justify-content-between align-items-center mb-1 small <?php echo !$_sv ? 'opacity-50' : ''; ?>">
          <span class="text-muted d-flex align-items-center gap-1">
            <?php echo htmlspecialchars(tSlot($slot, $slot)); ?>
            <?php if(!$_sv): ?>
            <span class="badge text-bg-warning" style="font-size:.65rem;font-weight:500">隐藏槽</span>
            <?php endif; ?>
          </span>
          <span class="fw-semibold"><?php echo htmlspecialchars($item_on); ?></span>
        </div>
        <?php endforeach; ?>
        <?php if(!$equip_row): ?><p class="text-muted small mb-0">未佩戴任何装备</p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-chart-bar me-2"></i>八维属性</span>
        <?php if($has_bonus): ?>
        <span class="badge bg-success small fw-normal"><i class="fas fa-shirt me-1"></i>含装备加成</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if($has_bonus): ?>
        <div class="d-flex gap-3 mb-3 small text-muted">
          <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:linear-gradient(90deg,#667eea,#764ba2);vertical-align:middle" class="me-1"></span>基础值</span>
          <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#198754;vertical-align:middle" class="me-1"></span>装备加成</span>
        </div>
        <?php endif; ?>
        <?php $max_stat=500; foreach($stat_fields as $sf):
          $base  = intval($stats_row[$sf]??0);
          $bonus = $equip_bonus[$sf];
          $total = $base + $bonus;
          $base_pct  = min(100, round($base/$max_stat*100));
          $bonus_pct = ($bonus > 0) ? min(100-$base_pct, round($bonus/$max_stat*100)) : 0;
        ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center small mb-1">
            <span class="fw-semibold"><?php echo t($sf,$sf); ?></span>
            <span class="text-end">
              <span class="text-muted"><?php echo $base; ?></span>
              <?php if($bonus != 0): ?>
              <span class="fw-semibold ms-1 <?php echo $bonus>0?'text-success':'text-danger'; ?>">
                <?php echo ($bonus>0?'+':'').$bonus; ?>
              </span>
              <span class="fw-bold ms-1 text-body">= <?php echo $total; ?></span>
              <?php endif; ?>
            </span>
          </div>
          <div class="progress" style="height:7px;border-radius:4px;">
            <div class="progress-bar" style="width:<?php echo $base_pct; ?>%;background:linear-gradient(90deg,#667eea,#764ba2)" title="基础 <?php echo $base; ?>"></div>
            <?php if($bonus_pct > 0): ?>
            <div class="progress-bar bg-success" style="width:<?php echo $bonus_pct; ?>%" title="装备 +<?php echo $bonus; ?>"></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if(!$stats_row): ?><p class="text-muted">无属性数据</p><?php endif; ?>
        <?php if($stats_row): ?>
        <div class="border-top pt-2 mt-1 small">
          <?php
          $base_sum  = array_sum(array_map(fn($sf)=>intval($stats_row[$sf]??0), $stat_fields));
          $bonus_sum = array_sum(array_map(fn($sf)=>$equip_bonus[$sf]??0, $stat_fields));
          $total_sum = $base_sum + $bonus_sum;
          ?>
          <div class="d-flex justify-content-between text-muted mb-1">
            <span>基础合计</span><span><?php echo number_format($base_sum); ?></span>
          </div>
          <?php if($bonus_sum != 0): ?>
          <div class="d-flex justify-content-between text-muted mb-1">
            <span>装备加成</span>
            <span class="<?php echo $bonus_sum>0?'text-success':'text-danger'; ?>"><?php echo ($bonus_sum>0?'+':'').$bonus_sum; ?></span>
          </div>
          <div class="d-flex justify-content-between fw-bold border-top pt-1 mt-1">
            <span>实战合计</span><span class="text-success"><?php echo number_format($total_sum); ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-bag-shopping me-2"></i>背包 (<?php echo count($bag_items); ?> 件)</div>
      <div class="card-body">
        <?php if(empty($bag_items)): ?>
        <p class="text-muted">背包为空</p>
        <?php else: ?>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach($bag_items as $bi): ?>
          <span class="badge bg-body-secondary text-body border rounded-pill px-3 py-2">
            <?php echo htmlspecialchars($bi['item_name']); ?>
            <span class="ms-1 text-muted">×<?php echo $bi['count']; ?></span>
          </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="fas fa-file-lines me-2"></i>档案信息</div>
      <div class="card-body">
        <?php
        $_prof_vis = getProfileFieldsVisibility();
        foreach($prof as $k=>$v):
            $_label   = tAuto($k, $k);
            // is_hidden: 1=可见, 0=隐藏；若 term 不存在则默认可见
            // 先按原始键 $k 查（中文键/英文后缀都能命中），再按翻译后标签查
            $_visible = $_prof_vis[$k] ?? $_prof_vis[$_label] ?? 1;
        ?>
        <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1 small <?php echo !$_visible ? 'opacity-50' : ''; ?>">
          <span class="text-muted d-flex align-items-center gap-1">
            <?php echo htmlspecialchars($_label); ?>
            <?php if(!$_visible): ?>
            <span class="badge text-bg-warning" style="font-size:.65rem;font-weight:500">对玩家隐藏</span>
            <?php endif; ?>
          </span>
          <span><?php echo htmlspecialchars(is_array($v)?json_encode($v,JSON_UNESCAPED_UNICODE):strval($v)); ?></span>
        </div>
        <?php endforeach; ?>
        <?php if(empty($prof)): ?><p class="text-muted small mb-0">档案为空</p><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php elseif (in_array($action, ['edit','add'])): ?>
<!-- ===== 新增/编辑 ===== -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-pen me-2"></i><?php echo $action==='add'?'新增用户':'编辑用户'; ?></span>
    <a href="users.php" class="btn btn-sm btn-outline-secondary">返回</a>
  </div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="orig_id" value="<?php echo htmlspecialchars($edit_user['id']??''); ?>">
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label fw-semibold small">QQ 号 <?php echo $action==='add'?'<span class="text-danger">*</span>':''; ?></label>
          <input type="text" class="form-control" name="user_id"
                 value="<?php echo htmlspecialchars($edit_user['id']??''); ?>"
                 <?php echo $action!=='add'?'readonly':'required'; ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">UID</label>
          <input type="text" class="form-control" name="uid" value="<?php echo htmlspecialchars($edit_user['uid']??''); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">角色名</label>
          <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($edit_user['name']??''); ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">货币 <span class="text-muted">(JSON)</span></label>
          <textarea class="form-control font-monospace" name="currency" rows="3"><?php echo htmlspecialchars($edit_user['currency']??'{}'); ?></textarea>
          <div class="form-text">格式：{"虞元": 100, "名誉": 50}</div>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">档案 <span class="text-muted">(JSON)</span></label>
          <textarea class="form-control font-monospace" name="profile" rows="3"><?php echo htmlspecialchars($edit_user['profile']??'{}'); ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">每日限制 <span class="text-muted">(JSON)</span></label>
          <textarea class="form-control font-monospace" name="limits" rows="3"><?php echo htmlspecialchars($edit_user['limits']??'{}'); ?></textarea>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-2"></i>保存</button>
        <a href="users.php" class="btn btn-outline-secondary">取消</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
