<?php
require_once 'config.php';
checkLogin();
$db    = getDB();
$today = date('Y-m-d');

// 统计数据
$stat = [];
$stat['users']       = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$stat['items']       = $db->query("SELECT COUNT(*) FROM items")->fetchColumn();
$s = $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at)=?");
$s->execute([$today]); $stat['today_users'] = $s->fetchColumn();
$stat['admins']      = $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();

// 最近10个用户
$recent_users = $db->query("SELECT id, name, created_at FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll();

// 第一个可见属性 TOP5（自动跟随术语配置）
$_top_stat  = getVisibleStatFields()[0] ?? 'stat_face';
$top_face   = $db->query("SELECT u.name, u.id, s.$_top_stat AS top_val FROM users u JOIN user_stats s ON u.id=s.user_id ORDER BY s.$_top_stat DESC LIMIT 5")->fetchAll();
$_top_label = t($_top_stat, $_top_stat);

// 低库存物品
$low_stock = $db->query("SELECT name, stock_qty, price, currency FROM items WHERE is_selling=1 AND stock_qty != -1 ORDER BY stock_qty ASC LIMIT 8")->fetchAll();

// 最近操作日志
try {
    $recent_logs = $db->query("SELECT admin_id, module, action, target_id, created_at FROM admin_logs ORDER BY id DESC LIMIT 8")->fetchAll();
} catch(Exception $e) { $recent_logs = []; }

$page_title    = '仪表盘';
$page_icon     = 'fas fa-gauge-high';
$page_subtitle = '系统概览';
require_once 'header.php';
?>

<!-- ── 统计卡片 ── -->
<div class="stat-grid">
<?php
$cards = [
  ['id'=>'stat-users',    'icon'=>'fas fa-users',       'color'=>'oklch(0.59 0.22 22)',   'bg'=>'oklch(0.59 0.22 22 / 0.12)',  'val'=>$stat['users'],       'label'=>'总用户数',  'link'=>'users.php'],
  ['id'=>'stat-items',    'icon'=>'fas fa-shop',         'color'=>'oklch(0.65 0.18 152)',  'bg'=>'oklch(0.65 0.18 152 / 0.12)', 'val'=>$stat['items'],       'label'=>'物品数量',  'link'=>'items.php'],
  ['id'=>'stat-today',    'icon'=>'fas fa-user-plus',    'color'=>'oklch(0.63 0.12 228)',  'bg'=>'oklch(0.63 0.12 228 / 0.12)', 'val'=>$stat['today_users'], 'label'=>'今日新增',  'link'=>'users.php'],
  ['id'=>'stat-admins',   'icon'=>'fas fa-user-shield',  'color'=>'oklch(0.62 0.15 290)',  'bg'=>'oklch(0.62 0.15 290 / 0.12)', 'val'=>$stat['admins'],      'label'=>'管理员',    'link'=>'admins.php'],
];
foreach($cards as $c): ?>
<a href="<?php echo $c['link']; ?>" class="stat-card-link">
  <div class="stat-card-inner">
    <div class="stat-icon-wrap" style="background:<?php echo $c['bg']; ?>">
      <i class="<?php echo $c['icon']; ?>" style="color:<?php echo $c['color']; ?>"></i>
    </div>
    <div>
      <div class="stat-num" id="<?php echo $c['id']; ?>"><?php echo $c['val']; ?></div>
      <div class="stat-label"><?php echo $c['label']; ?></div>
    </div>
  </div>
</a>
<?php endforeach; ?>
</div>

<!-- ── 快速操作 ── -->
<div class="card mb-4">
  <div class="card-header"><i class="fas fa-bolt me-2" style="color:oklch(0.80 0.15 83)"></i>快速操作</div>
  <div class="card-body p-3">
    <div class="row g-2">
      <?php
      $quick=[
        ['url'=>'users.php?action=add',  'icon'=>'fas fa-user-plus',   'label'=>'新增用户', 'color'=>'primary'],
        ['url'=>'items.php?action=add',  'icon'=>'fas fa-plus-circle', 'label'=>'新增物品', 'color'=>'success'],
        ['url'=>'game_config.php',       'icon'=>'fas fa-sliders',     'label'=>'游戏配置', 'color'=>'info'],
        ['url'=>'terms.php',             'icon'=>'fas fa-language',    'label'=>'术语翻译', 'color'=>'secondary'],
        ['url'=>'backup.php',            'icon'=>'fas fa-floppy-disk', 'label'=>'备份数据', 'color'=>'warning'],
        ['url'=>'admin_logs.php',        'icon'=>'fas fa-list-check',  'label'=>'操作日志', 'color'=>'danger'],
      ];
      foreach($quick as $q): ?>
      <div class="col-6 col-md-4 col-lg-2">
        <a href="<?php echo $q['url']; ?>" class="btn btn-outline-<?php echo $q['color']; ?> w-100 py-3 d-flex flex-column align-items-center gap-2">
          <i class="<?php echo $q['icon']; ?> fs-5"></i>
          <span style="font-size:.76rem"><?php echo $q['label']; ?></span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ── 用户 & 排行 ── -->
<div class="row g-3 mb-4">
  <!-- 最近注册用户 -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-user-clock me-2" style="color:var(--brand)"></i>最近注册用户</span>
        <a href="users.php" class="btn btn-sm btn-outline-primary">全部</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th class="ps-4">用户 ID</th><th>昵称</th><th>注册时间</th><th class="pe-4">操作</th>
            </tr></thead>
            <tbody>
            <?php foreach($recent_users as $u): ?>
            <tr>
              <td class="ps-4"><code><?php echo htmlspecialchars($u['id']); ?></code></td>
              <td><?php echo htmlspecialchars($u['name'] ?? '—'); ?></td>
              <td style="color:var(--t-2);font-size:.78rem"><?php echo htmlspecialchars($u['created_at'] ?? ''); ?></td>
              <td class="pe-4">
                <a href="users.php?action=view&id=<?php echo urlencode($u['id']); ?>" class="btn btn-xs btn-outline-primary">查看</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($recent_users)): ?>
            <tr><td colspan="4" class="text-center py-4" style="color:var(--t-3)">暂无用户数据</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- 第一可见属性排行 -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-crown me-2" style="color:oklch(0.80 0.15 83)"></i><?php echo htmlspecialchars($_top_label); ?> 排行 TOP5</span>
        <a href="stats.php" class="btn btn-sm btn-outline-warning">全部</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th class="ps-4" style="width:25%">排名</th><th>角色名</th><th class="pe-4"><?php echo htmlspecialchars($_top_label); ?></th>
            </tr></thead>
            <tbody>
            <?php $rank=1; foreach($top_face as $p): ?>
            <tr>
              <td class="ps-4">
                <?php
                  $medals=['1'=>'🥇','2'=>'🥈','3'=>'🥉'];
                  echo isset($medals[$rank])
                    ? "<span style='font-size:1.1rem'>{$medals[$rank]}</span>"
                    : "<span class='badge bg-secondary'>#{$rank}</span>";
                ?>
              </td>
              <td><?php echo htmlspecialchars($p['name'] ?? '—'); ?></td>
              <td class="pe-4">
                <span class="badge bg-primary rounded-pill"><?php echo intval($p['top_val']); ?></span>
              </td>
            </tr>
            <?php $rank++; endforeach; ?>
            <?php if(empty($top_face)): ?>
            <tr><td colspan="3" class="text-center py-4" style="color:var(--t-3)">暂无数据</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── 库存提醒 & 操作日志 ── -->
<div class="row g-3">
  <!-- 低库存物品 -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-triangle-exclamation me-2" style="color:oklch(0.80 0.15 83)"></i>库存提醒</span>
        <a href="items.php" class="btn btn-sm btn-outline-warning">管理物品</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr>
              <th class="ps-4">物品名称</th><th>价格</th><th>库存</th><th class="pe-4">状态</th>
            </tr></thead>
            <tbody>
            <?php foreach($low_stock as $item): ?>
            <tr>
              <td class="ps-4 fw-semibold"><?php echo htmlspecialchars($item['name']); ?></td>
              <td style="color:var(--t-2)"><?php echo $item['price']; ?> <?php echo htmlspecialchars(tAuto($item['currency'] ?? '', t('term_yuCoin', '货币'))); ?></td>
              <td>
                <?php if($item['stock_qty']==0): ?>
                <span class="badge bg-danger">0</span>
                <?php elseif($item['stock_qty']<=5): ?>
                <span class="badge bg-warning"><?php echo $item['stock_qty']; ?></span>
                <?php else: ?>
                <span class="badge bg-info"><?php echo $item['stock_qty']; ?></span>
                <?php endif; ?>
              </td>
              <td class="pe-4">
                <?php if($item['stock_qty']==0): ?>
                <span class="badge bg-danger">断货</span>
                <?php elseif($item['stock_qty']<=5): ?>
                <span class="badge bg-warning">紧张</span>
                <?php else: ?>
                <span class="badge bg-secondary">偏低</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($low_stock)): ?>
            <tr><td colspan="4" class="text-center py-4" style="color:var(--t-3)">库存充足，无需补货</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- 最近操作日志 -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fas fa-clipboard-list me-2" style="color:oklch(0.63 0.12 228)"></i>最近操作</span>
        <?php if(can('logs')): ?>
        <a href="admin_logs.php" class="btn btn-sm btn-outline-info">全部</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if(empty($recent_logs)): ?>
        <div class="text-center py-5" style="color:var(--t-3)">
          <i class="fas fa-clock-rotate-left fa-2x mb-2 d-block" style="opacity:.2"></i>暂无操作记录
        </div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
        <?php
        $aColors=['login'=>'success','logout'=>'secondary','create'=>'primary','update'=>'warning','delete'=>'danger','execute'=>'info'];
        $aIcons=['login'=>'right-to-bracket','logout'=>'right-from-bracket','create'=>'plus','update'=>'pen','delete'=>'trash','execute'=>'play'];
        foreach($recent_logs as $log):
          $a=strtolower($log['action']);
          $color=$aColors[$a]??'secondary';
          $icon=$aIcons[$a]??'circle';
        ?>
        <li class="list-group-item border-0 py-2 px-3">
          <div class="d-flex gap-2 align-items-start">
            <span class="badge bg-<?php echo $color; ?> mt-1 flex-shrink-0"><i class="fas fa-<?php echo $icon; ?>"></i></span>
            <div class="flex-grow-1 overflow-hidden">
              <div style="font-size:.81rem;font-weight:600" class="text-truncate">
                <?php echo htmlspecialchars($log['admin_id']); ?>
                <span style="font-weight:400;color:var(--t-2)">· <?php echo htmlspecialchars($log['module']); ?></span>
              </div>
              <div style="font-size:.70rem;color:var(--t-3)"><?php echo htmlspecialchars($log['created_at']); ?></div>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>
