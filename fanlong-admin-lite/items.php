<?php
require_once 'config.php';
checkLogin();
requirePermission('items','view');

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$name   = $_GET['name']   ?? '';
$msg = ''; $msg_type = '';

// 槽位选项（中文显示）— 与机器人一致：accessory/interior 为分组槽
$slot_options = [
    ''          => '无（不可装备）',
    'hair'      => tSlot('hair',  '发型'),
    'top'       => tSlot('top',   '上衣'),
    'bottom'    => tSlot('bottom','下装'),
    'head'      => tSlot('head',  '头饰'),
    'neck'      => tSlot('neck',  '颈饰'),
    'accessory' => '配饰（任意配饰槽 acc1-4）',
    'interior'  => '内衣（任意内饰槽 inner1-2）',
];

// 物品类型选项
$type_map = getItemTypeMap();
// 合并数据库中已有的自定义类型
$db_types = $db->query("SELECT DISTINCT type FROM items WHERE type!='' AND type IS NOT NULL ORDER BY type")->fetchAll(PDO::FETCH_COLUMN,0);
foreach ($db_types as $dt) {
    if (!isset($type_map[$dt])) $type_map[$dt] = $dt;
}

// 货币选项（从 game_terms 金钱配置读取，key 去掉 term_ 前缀以匹配 items.currency 列）
$currency_options = [];
$term_currencies = $db->query("SELECT key,text FROM game_terms WHERE category='金钱配置' ORDER BY key")->fetchAll();
foreach($term_currencies as $tc) {
    $raw_key = preg_replace('/^term_/', '', $tc['key']);
    $currency_options[$raw_key] = $tc['text'];
}
if (empty($currency_options)) {
    $currency_options = ['yuCoin' => t('term_yuCoin', '货币'), 'reputation' => t('term_reputation', '名誉')];
}

// ===== POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';
    if ($pa === 'save') {
        $is_add  = empty($_POST['orig_name']);
        requirePermission('items', $is_add?'add':'edit');
        $n        = trim($_POST['name']             ?? '');
        $price    = floatval($_POST['price']        ?? 0);
        $cur      = trim($_POST['currency']         ?? 'yuCoin');
        $type     = trim($_POST['type']             ?? '');
        $sub_type = trim($_POST['sub_type']         ?? '');
        $slot     = trim($_POST['slot']             ?? '');
        $desc     = trim($_POST['desc']             ?? '');
        $stats    = trim($_POST['stats']            ?? '{}');
        $effect   = trim($_POST['effect']           ?? '{}');
        $cond     = trim($_POST['condition_text']   ?? '');
        $recipe   = trim($_POST['compound_recipe']  ?? '{}');
        $param    = trim($_POST['param']            ?? '{}');
        $selling  = isset($_POST['is_selling']) ? 1 : 0;
        $stock    = intval($_POST['stock_qty']       ?? -1);
        $max_h    = intval($_POST['max_hold']        ?? 0);

        // 验证 JSON 字段
        if (safeJsonDecode($stats) === [] && trim($stats) !== '{}') $stats = '{}';
        if (safeJsonDecode($effect) === [] && trim($effect) !== '{}') $effect = '{}';
        if (safeJsonDecode($recipe) === [] && trim($recipe) !== '{}') $recipe = '{}';
        if (safeJsonDecode($param)  === [] && trim($param)  !== '{}') $param  = '{}';

        if (empty($n)) { $msg='物品名称不能为空'; $msg_type='danger'; }
        else {
            try {
                $old = null;
                if (!$is_add) {
                    $r = $db->prepare("SELECT * FROM items WHERE name=?"); $r->execute([$_POST['orig_name']]); $old = $r->fetch();
                }
                if ($is_add) {
                    $db->prepare("INSERT INTO items (name,price,currency,type,sub_type,slot,`desc`,stats,effect,is_selling,stock_qty,max_hold,condition,compound_recipe,param) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$n,$price,$cur,$type,$sub_type,$slot,$desc,$stats,$effect,$selling,$stock,$max_h,$cond,$recipe,$param]);
                    logAction('items','create',$n,null,['price'=>$price,'type'=>$type,'slot'=>$slot]);
                } else {
                    $db->prepare("UPDATE items SET name=?,price=?,currency=?,type=?,sub_type=?,slot=?,`desc`=?,stats=?,effect=?,is_selling=?,stock_qty=?,max_hold=?,condition=?,compound_recipe=?,param=? WHERE name=?")
                       ->execute([$n,$price,$cur,$type,$sub_type,$slot,$desc,$stats,$effect,$selling,$stock,$max_h,$cond,$recipe,$param,$_POST['orig_name']]);
                    logAction('items','update',$_POST['orig_name'],$old,['price'=>$price,'slot'=>$slot,'is_selling'=>$selling]);
                }
                setFlash('success','物品「'.$n.'」已保存'); header('Location: items.php'); exit();
            } catch(Exception $e){ $msg='保存失败：'.$e->getMessage(); $msg_type='danger'; }
        }
    }
    if ($pa === 'delete') {
        requirePermission('items','delete');
        $dn  = $_POST['del_name'] ?? '';
        $old = $db->prepare("SELECT * FROM items WHERE name=?"); $old->execute([$dn]); $old=$old->fetch();
        $db->prepare("DELETE FROM items WHERE name=?")->execute([$dn]);
        logAction('items','delete',$dn,$old);
        setFlash('success','物品已删除'); header('Location: items.php'); exit();
    }
    if ($pa === 'toggle') {
        requirePermission('items','edit');
        $tn    = $_POST['toggle_name'] ?? '';
        $cur_s = $db->prepare("SELECT is_selling FROM items WHERE name=?"); $cur_s->execute([$tn]); $cur_s=$cur_s->fetchColumn();
        $new_s = $cur_s ? 0 : 1;
        $db->prepare("UPDATE items SET is_selling=? WHERE name=?")->execute([$new_s,$tn]);
        logAction('items','toggle',$tn,['is_selling'=>$cur_s],['is_selling'=>$new_s]);
        setFlash('success','物品「'.$tn.'」'.($new_s?'已上架':'已下架')); header('Location: items.php'); exit();
    }
}

// 编辑/新增
$edit_item = null;
if (in_array($action,['edit','add'])) {
    if ($action==='edit' && !empty($name)) {
        $r = $db->prepare("SELECT * FROM items WHERE name=?"); $r->execute([$name]); $edit_item=$r->fetch();
        if (!$edit_item){ $msg='找不到物品'; $msg_type='danger'; $action='list'; }
    }
}

// 列表
$items = [];
if ($action==='list') {
    $type_filter = $_GET['type'] ?? '';
    $sql = "SELECT * FROM items";
    $params = [];
    if ($type_filter){ $sql.=" WHERE type=?"; $params[]=$type_filter; }
    $sql .= " ORDER BY is_selling DESC, type, name";
    $stmt = $db->prepare($sql); $stmt->execute($params); $items=$stmt->fetchAll();
}

$page_title = '商店管理'; $page_icon = 'fas fa-shop'; $page_subtitle = '商品库存管理';
require_once 'header.php';
?>

<?php if($msg): ?>
<div class="alert alert-<?php echo $msg_type; ?> border-0 rounded-3 alert-dismissible"><?php echo htmlspecialchars($msg); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($action==='list'): ?>
<!-- 教程提示 -->
<div class="alert alert-info border-0 rounded-3 mb-4 py-2">
  <i class="fas fa-lightbulb me-2"></i>
  <strong>使用说明：</strong>物品类型「装备」需填写槽位（决定穿在哪个部位）；「消耗品」无需填槽位。属性加成填写后，穿戴该物品会自动修改用户属性。库存 <code>-1</code> 表示无限量。
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form class="d-flex gap-2 align-items-center flex-wrap">
    <select class="form-select form-select-sm" name="type" style="width:auto">
      <option value="">全部类型</option>
      <?php foreach($type_map as $tv=>$tl): if($tv==='') continue; ?>
      <option value="<?php echo htmlspecialchars($tv); ?>" <?php echo ($_GET['type']??'')===$tv?'selected':''; ?>>
        <?php echo htmlspecialchars($tl); ?>
      </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-secondary">筛选</button>
    <a href="items.php" class="btn btn-sm btn-outline-secondary">重置</a>
  </form>
  <?php if(can('items','add')): ?>
  <a href="items.php?action=add" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i>新增物品</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover datatable align-middle mb-0 small">
        <thead><tr class="table-active">
          <th class="ps-4">物品名</th><th>类型</th><th>槽位</th><th>价格</th>
          <th>属性加成</th><th>库存</th><th>限购</th><th>状态</th><th class="pe-4">操作</th>
        </tr></thead>
        <tbody>
        <?php foreach($items as $it):
          $stats_arr = safeJsonDecode($it['stats']??'{}');
          $stats_str = '';
          foreach($stats_arr as $sk=>$sv) {
              if(intval($sv)!=0) $stats_str .= t($sk,$sk).'<span class="text-'.($sv>0?'success':'danger').'">'.($sv>0?'+':'').intval($sv).'</span> ';
          }
        ?>
        <tr>
          <td class="ps-4">
            <div class="fw-semibold"><?php echo htmlspecialchars($it['name']); ?></div>
            <?php if(!empty($it['desc'])): ?><div class="text-muted" style="font-size:.75rem;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($it['desc']); ?></div><?php endif; ?>
          </td>
          <td>
            <span class="badge bg-body-secondary text-body border">
              <?php echo htmlspecialchars(tItemType($it['type']??'')); ?>
            </span>
            <?php if(!empty($it['sub_type']) && $it['sub_type']!=='normal'):
              $sub_labels=['rename_card'=>'改名卡','optional_pack'=>'自选礼包','stat_boost'=>'属性提升'];
              $sl=$sub_labels[$it['sub_type']]??$it['sub_type'];
            ?><br><span class="text-muted" style="font-size:.72rem"><?php echo htmlspecialchars($sl); ?></span><?php endif; ?>
          </td>
          <td>
            <?php echo empty($it['slot']) ? '<span class="text-muted">—</span>' : '<span class="badge bg-info text-dark">'.htmlspecialchars($slot_options[$it['slot']] ?? tSlot($it['slot'],$it['slot'])).'</span>'; ?>
          </td>
          <td class="text-nowrap">
            <?php echo number_format(floatval($it['price'])); ?>
            <small class="text-muted"><?php echo htmlspecialchars(tAuto($it['currency']??'yuCoin', t('term_yuCoin','货币'))); ?></small>
          </td>
          <td>
            <?php if($stats_str): ?>
            <div class="small"><?php echo $stats_str; ?></div>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if($it['stock_qty']==-1): ?><span class="badge bg-success">无限</span>
            <?php elseif($it['stock_qty']==0): ?><span class="badge bg-danger">缺货</span>
            <?php elseif($it['stock_qty']<=5): ?><span class="badge bg-warning text-dark"><?php echo $it['stock_qty']; ?></span>
            <?php else: ?><span class="badge bg-info text-dark"><?php echo $it['stock_qty']; ?></span>
            <?php endif; ?>
          </td>
          <td><?php echo $it['max_hold']>0 ? $it['max_hold'].'件' : '<span class="text-muted">无限</span>'; ?></td>
          <td>
            <?php if($it['is_selling']): ?><span class="badge bg-success">在售</span>
            <?php else: ?><span class="badge bg-secondary">下架</span><?php endif; ?>
          </td>
          <td class="pe-4">
            <div class="d-flex gap-1 flex-wrap">
              <?php if(can('items','edit')): ?>
              <a href="items.php?action=edit&name=<?php echo urlencode($it['name']); ?>" class="btn btn-sm btn-outline-primary py-0 px-2">编辑</a>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="toggle_name" value="<?php echo htmlspecialchars($it['name']); ?>">
                <button class="btn btn-sm py-0 px-2 <?php echo $it['is_selling']?'btn-outline-warning':'btn-outline-success'; ?>">
                  <?php echo $it['is_selling']?'下架':'上架'; ?>
                </button>
              </form>
              <?php endif; ?>
              <?php if(can('items','delete')): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('确认删除物品「<?php echo htmlspecialchars($it['name']); ?>」？')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="del_name" value="<?php echo htmlspecialchars($it['name']); ?>">
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

<?php else: /* 新增/编辑 */ ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-pen me-2"></i><?php echo $action==='add'?'新增物品':'编辑物品：'.htmlspecialchars($edit_item['name']??''); ?></span>
    <a href="items.php" class="btn btn-sm btn-outline-secondary">返回列表</a>
  </div>
  <div class="card-body">

    <!-- 操作说明 -->
    <div class="alert alert-light border rounded-3 small mb-4">
      <i class="fas fa-circle-info me-2 text-primary"></i>
      <strong>填写指引：</strong>
      物品名称唯一标识该物品；类型选「装备」后请选择槽位；属性加成格式为
      <code>{"stat_face":5,"stat_charm":3}</code>，颜值+5 魅力+3；
      库存填 <strong>-1</strong> 表示无限量；单人限购填 <strong>0</strong> 表示不限。
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="orig_name" value="<?php echo htmlspecialchars($edit_item['name']??''); ?>">

      <h6 class="fw-bold mb-3 text-primary border-bottom pb-2"><i class="fas fa-tag me-2"></i>基本信息</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label fw-semibold small">物品名称 <span class="text-danger">*</span></label>
          <input type="text" class="form-control" name="name" required
                 value="<?php echo htmlspecialchars($edit_item['name']??''); ?>"
                 placeholder="如：休闲T恤">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold small">类型</label>
          <select class="form-select" name="type" id="itemType" onchange="onTypeChange()">
            <?php foreach($type_map as $tv=>$tl): ?>
            <option value="<?php echo htmlspecialchars($tv); ?>" <?php echo ($edit_item['type']??'')===$tv?'selected':''; ?>>
              <?php echo htmlspecialchars($tl); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold small">子类型</label>
          <select class="form-select" name="sub_type">
            <option value="normal" <?php echo ($edit_item['sub_type']??'normal')==='normal'?'selected':''; ?>>普通</option>
            <option value="rename_card" <?php echo ($edit_item['sub_type']??'')==='rename_card'?'selected':''; ?>>改名卡</option>
            <option value="optional_pack" <?php echo ($edit_item['sub_type']??'')==='optional_pack'?'selected':''; ?>>自选礼包</option>
            <option value="stat_boost" <?php echo ($edit_item['sub_type']??'')==='stat_boost'?'selected':''; ?>>属性提升</option>
          </select>
        </div>
        <div class="col-md-2" id="slotWrap">
          <label class="form-label fw-semibold small">装备槽位</label>
          <select class="form-select" name="slot">
            <?php foreach($slot_options as $sv=>$sl): ?>
            <option value="<?php echo $sv; ?>" <?php echo ($edit_item['slot']??'')===$sv?'selected':''; ?>>
              <?php echo htmlspecialchars($sl); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold small">是否在售</label>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="is_selling" id="isSelling" value="1"
                   <?php echo ($edit_item['is_selling']??1)?'checked':''; ?>>
            <label class="form-check-label fw-semibold" for="isSelling">立即上架</label>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold small">物品描述</label>
          <textarea class="form-control" name="desc" rows="2"
                    placeholder="对用户展示的描述文字，可留空"><?php echo htmlspecialchars($edit_item['desc']??''); ?></textarea>
        </div>
      </div>

      <h6 class="fw-bold mb-3 text-primary border-bottom pb-2"><i class="fas fa-coins me-2"></i>价格与库存</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-3">
          <label class="form-label fw-semibold small">价格</label>
          <input type="number" class="form-control" name="price" step="0.01" min="0"
                 value="<?php echo $edit_item['price']??0; ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold small">货币单位</label>
          <select class="form-select" name="currency">
            <?php foreach($currency_options as $ck=>$cl): ?>
            <option value="<?php echo htmlspecialchars($ck); ?>" <?php echo ($edit_item['currency']??'yuCoin')===$ck?'selected':''; ?>>
              <?php echo htmlspecialchars($cl); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold small">库存数量 <span class="text-muted">(-1=无限)</span></label>
          <input type="number" class="form-control" name="stock_qty"
                 value="<?php echo $edit_item['stock_qty']??-1; ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold small">单人限购 <span class="text-muted">(0=不限)</span></label>
          <input type="number" class="form-control" name="max_hold" min="0"
                 value="<?php echo $edit_item['max_hold']??0; ?>">
        </div>
      </div>

      <h6 class="fw-bold mb-3 text-primary border-bottom pb-2"><i class="fas fa-star me-2"></i>属性与效果</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label fw-semibold small">属性加成 <span class="text-muted">(JSON)</span></label>
          <textarea class="form-control font-monospace" name="stats" rows="3"
                    placeholder='{"stat_face":5,"stat_charm":3}'><?php echo htmlspecialchars($edit_item['stats']??'{}'); ?></textarea>
          <div class="form-text">
            可用键名：
            <?php foreach(['stat_face','stat_charm','stat_intel','stat_biz','stat_talk','stat_body','stat_art','stat_obed'] as $sf): ?>
            <code><?php echo $sf; ?></code>(<?php echo t($sf,$sf); ?>)
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold small">特殊效果 <span class="text-muted">(JSON，机器人读取)</span></label>
          <textarea class="form-control font-monospace" name="effect" rows="3"
                    placeholder="{}"><?php echo htmlspecialchars($edit_item['effect']??'{}'); ?></textarea>
          <div class="form-text">由机器人代码解析，具体格式参考游戏文档</div>
        </div>
      </div>

      <h6 class="fw-bold mb-3 text-primary border-bottom pb-2"><i class="fas fa-cogs me-2"></i>高级配置</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label fw-semibold small">购买条件 <span class="text-muted">(选填)</span></label>
          <input type="text" class="form-control" name="condition_text"
                 value="<?php echo htmlspecialchars($edit_item['condition']??''); ?>"
                 placeholder="如：需要VIP等级≥3">
          <div class="form-text">限制玩家购买此物品的条件</div>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">合成配方 <span class="text-muted">(JSON)</span></label>
          <textarea class="form-control font-monospace" name="compound_recipe" rows="2"
                    placeholder="{}"><?php echo htmlspecialchars($edit_item['compound_recipe']??'{}'); ?></textarea>
          <div class="form-text">合成该物品所需材料，格式：{"材料名":数量}</div>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold small">扩展参数 <span class="text-muted">(JSON)</span></label>
          <textarea class="form-control font-monospace" name="param" rows="2"
                    placeholder="{}"><?php echo htmlspecialchars($edit_item['param']??'{}'); ?></textarea>
          <div class="form-text">物品其他自定义参数</div>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save me-2"></i>保存物品</button>
        <a href="items.php" class="btn btn-outline-secondary">取消</a>
      </div>
    </form>
  </div>
</div>

<script>
function onTypeChange() {
  var type = document.getElementById('itemType').value;
  var slotWrap = document.getElementById('slotWrap');
  // 装备类型才需要填槽位
  slotWrap.style.display = (type === 'equip' || type === '') ? '' : 'none';
  if (type !== 'equip' && type !== '') {
    slotWrap.querySelector('select').value = '';
  }
}
document.addEventListener('DOMContentLoaded', onTypeChange);
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
