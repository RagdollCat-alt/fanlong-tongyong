<?php
require_once 'config.php';
checkLogin();
requirePermission('broadcast','execute');

$db = getDB();

$stat_fields = ['stat_face','stat_charm','stat_intel','stat_biz','stat_talk','stat_body','stat_art','stat_obed'];
$result_msg = '';
$result_type = '';

// ============================================================
// POST 处理
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // ——— 全员发送 ———
    if ($pa === 'broadcast') {
        $btype  = $_POST['btype']  ?? '';   // item / yuCoin / reputation / stat_xxx
        $bvalue = trim($_POST['bvalue'] ?? '');  // 物品名 or ignored for stat
        $bamt   = intval($_POST['bamt'] ?? 0);

        if ($bamt === 0) {
            $result_msg = '数量不能为 0'; $result_type = 'danger';
        } else {
            try {
                $all_users = $db->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN, 0);
                $count = 0;

                if ($btype === 'item') {
                    // ——— 物品 ———
                    if (empty($bvalue)) { $result_msg='请选择物品名称'; $result_type='danger'; goto done; }
                    // 查物品类型及 stats，判断是否需要创建首穿实例记录
                    $itype_r = $db->prepare("SELECT type, stats FROM items WHERE name=? LIMIT 1");
                    $itype_r->execute([$bvalue]);
                    $irow  = $itype_r->fetch();
                    $itype = $irow['type'] ?? '';
                    $now   = time();
                    // 只有装备且含货币加成时才需要实例记录
                    $yu_term  = $db->query("SELECT text FROM game_terms WHERE key='term_yuCoin' LIMIT 1")->fetchColumn() ?: 'yuCoin';
                    $rep_term = $db->query("SELECT text FROM game_terms WHERE key='term_reputation' LIMIT 1")->fetchColumn() ?: 'reputation';
                    $istats   = safeJsonDecode($irow['stats'] ?? '{}');
                    $needs_instance = ($itype === 'equip') && (
                        isset($istats[$yu_term]) || isset($istats[$rep_term]) ||
                        isset($istats['yuCoin']) || isset($istats['reputation'])
                    );

                    foreach ($all_users as $uid) {
                        $row = $db->prepare("SELECT count FROM user_bag WHERE user_id=? AND item_name=?");
                        $row->execute([$uid, $bvalue]);
                        $existing = $row->fetch();

                        if ($bamt > 0) {
                            if ($existing !== false) {
                                $new_count = max(0, $existing['count'] + $bamt);
                                $db->prepare("UPDATE user_bag SET count=? WHERE user_id=? AND item_name=?")
                                   ->execute([$new_count, $uid, $bvalue]);
                                if ($needs_instance) {
                                    for ($i = 0; $i < $bamt; $i++) {
                                        $db->prepare("INSERT INTO item_instances (item_name,user_id,currency_given,created_at) VALUES (?,?,0,?)")
                                           ->execute([$bvalue, $uid, $now]);
                                    }
                                }
                            } else {
                                $db->prepare("INSERT INTO user_bag (user_id,item_name,count) VALUES (?,?,?)")
                                   ->execute([$uid, $bvalue, $bamt]);
                                if ($needs_instance) {
                                    for ($i = 0; $i < $bamt; $i++) {
                                        $db->prepare("INSERT INTO item_instances (item_name,user_id,currency_given,created_at) VALUES (?,?,0,?)")
                                           ->execute([$bvalue, $uid, $now]);
                                    }
                                }
                            }
                        } else {
                            // 扣除
                            if ($existing !== false) {
                                $new_count = $existing['count'] + $bamt; // bamt is negative
                                if ($new_count <= 0) {
                                    $db->prepare("DELETE FROM user_bag WHERE user_id=? AND item_name=?")->execute([$uid, $bvalue]);
                                    if ($itype === 'equip') {
                                        $db->prepare("DELETE FROM item_instances WHERE item_name=? AND user_id=?")->execute([$bvalue, $uid]);
                                    }
                                } else {
                                    $db->prepare("UPDATE user_bag SET count=? WHERE user_id=? AND item_name=?")
                                       ->execute([$new_count, $uid, $bvalue]);
                                }
                            }
                        }
                        $count++;
                    }
                    logAction('broadcast','execute','all_items',null,['item'=>$bvalue,'amt'=>$bamt,'users'=>$count]);
                    $result_msg = "已完成：{$count} 名用户背包 · 物品「{$bvalue}」" . ($bamt > 0 ? "+{$bamt}" : "{$bamt}");
                    $result_type = 'success';

                } elseif ($btype === 'yuCoin' || $btype === 'reputation') {
                    // ——— 货币 ———
                    $updated = 0;
                    $users_rows = $db->query("SELECT id, currency FROM users")->fetchAll();
                    foreach ($users_rows as $urow) {
                        $cur = safeJsonDecode($urow['currency'] ?? '{}');
                        $cur[$btype] = intval($cur[$btype] ?? 0) + $bamt;
                        if ($cur[$btype] < 0) $cur[$btype] = 0;
                        $db->prepare("UPDATE users SET currency=? WHERE id=?")
                           ->execute([json_encode($cur, JSON_UNESCAPED_UNICODE), $urow['id']]);
                        $updated++;
                    }
                    $curr_label = $btype === 'yuCoin' ? t('term_yuCoin','虞元') : t('term_reputation','名誉');
                    logAction('broadcast','execute',"all_{$btype}",null,['amt'=>$bamt,'users'=>$updated]);
                    $result_msg = "已完成：{$updated} 名用户 · {$curr_label} " . ($bamt > 0 ? "+{$bamt}" : "{$bamt}");
                    $result_type = 'success';

                } elseif (in_array($btype, $stat_fields)) {
                    // ——— 属性 ———
                    $col = $btype; // safe: value is from whitelist ($stat_fields)
                    $db->prepare("UPDATE user_stats SET $col = MAX(0, $col + ?)")->execute([$bamt]);
                    $affected = (int)$db->query("SELECT changes()")->fetchColumn();
                    $stat_label = t($btype, $btype);
                    logAction('broadcast','execute',"all_stat_{$btype}",null,['amt'=>$bamt,'rows'=>$affected]);
                    $result_msg = "已完成：{$affected} 条属性记录 · {$stat_label} " . ($bamt > 0 ? "+{$bamt}" : "{$bamt}");
                    $result_type = 'success';

                } else {
                    $result_msg = '未知操作类型'; $result_type = 'danger';
                }
            } catch (Exception $e) {
                $result_msg = '执行失败：' . $e->getMessage(); $result_type = 'danger';
            }
        }
    }

    // ——— 发薪资 ———
    if ($pa === 'salary') {
        try {
            $salary_cn_key = t('profile_salary', '薪资');
            $users_rows = $db->query("SELECT id, currency, profile FROM users")->fetchAll();
            $sent_count = 0; $total_amt = 0;
            foreach ($users_rows as $urow) {
                $profile  = safeJsonDecode($urow['profile'] ?? '{}');
                $currency = safeJsonDecode($urow['currency'] ?? '{}');
                // profile 可能用 Unicode 转义存储，尝试两种格式
                $salary_str = $profile[$salary_cn_key] ?? $profile['薪资'] ?? '0';
                preg_match('/\d+/', (string)$salary_str, $m);
                $salary = isset($m[0]) ? intval($m[0]) : 0;
                if ($salary > 0) {
                    $currency['yuCoin'] = intval($currency['yuCoin'] ?? 0) + $salary;
                    $db->prepare("UPDATE users SET currency=? WHERE id=?")
                       ->execute([json_encode($currency, JSON_UNESCAPED_UNICODE), $urow['id']]);
                    $sent_count++; $total_amt += $salary;
                }
            }
            $yu_label = t('term_yuCoin','虞元');
            logAction('broadcast','execute','salary',null,['users'=>$sent_count,'total'=>$total_amt]);
            $result_msg = "发薪完成：共 {$sent_count} 人，总发放 {$total_amt} {$yu_label}";
            $result_type = 'success';
        } catch (Exception $e) {
            $result_msg = '发薪失败：' . $e->getMessage(); $result_type = 'danger';
        }
    }
}

done:
// 物品列表（仅装备/消耗品）
$items_list = $db->query("SELECT name, type FROM items WHERE is_selling=1 OR is_selling IS NULL ORDER BY name")->fetchAll();
$user_count = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// 货币名称
$yu_label  = t('term_yuCoin','虞元');
$rep_label = t('term_reputation','名誉');

$page_title = '批量发送'; $page_icon = 'fas fa-bullhorn'; $page_subtitle = '全员操作与薪资发放';
require_once 'header.php';
?>

<?php if ($result_msg): ?>
<div class="alert alert-<?php echo $result_type; ?> border-0 rounded-3 alert-dismissible">
  <i class="fas fa-<?php echo $result_type==='success'?'circle-check':'circle-exclamation'; ?> me-2"></i>
  <?php echo htmlspecialchars($result_msg); ?>
  <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="alert alert-warning border-0 rounded-3 mb-4 py-2 small">
  <i class="fas fa-triangle-exclamation me-2"></i>
  <strong>注意：</strong>批量操作将影响 <strong>全部 <?php echo $user_count; ?> 名用户</strong>，操作不可撤销。执行前请仔细确认。
</div>

<div class="row g-4">
  <!-- 全员发送 -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-users me-2"></i>全员发送</div>
      <div class="card-body">
        <form method="POST" id="broadcastForm" onsubmit="return confirmBroadcast()">
          <input type="hidden" name="action" value="broadcast">

          <div class="mb-3">
            <label class="form-label fw-semibold small">操作类型</label>
            <select class="form-select" name="btype" id="btypeSelect" onchange="onTypeChange(this.value)" required>
              <option value="">-- 请选择 --</option>
              <optgroup label="货币">
                <option value="yuCoin"><?php echo htmlspecialchars($yu_label); ?></option>
                <option value="reputation"><?php echo htmlspecialchars($rep_label); ?></option>
              </optgroup>
              <optgroup label="物品">
                <option value="item">物品（下方选择具体物品）</option>
              </optgroup>
              <optgroup label="八维属性">
                <?php foreach ($stat_fields as $sf): ?>
                <option value="<?php echo $sf; ?>"><?php echo htmlspecialchars(t($sf, $sf)); ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>

          <!-- 物品选择（仅物品类型时显示）-->
          <div class="mb-3" id="itemSelectGroup" style="display:none">
            <label class="form-label fw-semibold small">选择物品</label>
            <select class="form-select select2" name="bvalue" id="bvalueSelect">
              <option value="">-- 请选择物品 --</option>
              <?php foreach ($items_list as $il): ?>
              <option value="<?php echo htmlspecialchars($il['name']); ?>">
                <?php echo htmlspecialchars($il['name']); ?>
                <?php if ($il['type'] === 'equip'): ?>
                <span class="text-muted">（装备）</span>
                <?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold small">
              数量 <span class="text-muted fw-normal">（正数=发放，负数=扣除）</span>
            </label>
            <input type="number" class="form-control" name="bamt" id="bamtInput"
                   value="1" required placeholder="如：1 或 -1">
          </div>

          <div class="d-flex gap-2 align-items-center">
            <button type="submit" class="btn btn-danger px-4">
              <i class="fas fa-paper-plane me-2"></i>执行全员操作
            </button>
            <span class="text-muted small">当前用户数：<strong><?php echo $user_count; ?></strong> 人</span>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 发薪资 -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-money-bill-wave me-2"></i>发薪资</div>
      <div class="card-body">
        <p class="text-muted small">
          读取每位用户角色档案中的「<strong><?php echo htmlspecialchars(t('profile_salary','薪资')); ?>」</strong>字段，
          将数值直接加到 <?php echo htmlspecialchars($yu_label); ?> 余额。
          没有配置薪资或薪资为 0 的用户不受影响。
        </p>
        <div class="alert alert-info border-0 rounded-3 py-2 small mb-3">
          <i class="fas fa-info-circle me-2"></i>
          对应机器人指令：<code>发薪资</code>
        </div>
        <form method="POST" onsubmit="return confirm('确认为所有用户发放薪资？')">
          <input type="hidden" name="action" value="salary">
          <button type="submit" class="btn btn-success px-4">
            <i class="fas fa-coins me-2"></i>一键发薪
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function onTypeChange(val) {
  document.getElementById('itemSelectGroup').style.display = val === 'item' ? '' : 'none';
  if (val !== 'item') {
    document.getElementById('bvalueSelect').value = '';
  }
}

function confirmBroadcast() {
  var type = document.getElementById('btypeSelect').value;
  var amt  = parseInt(document.getElementById('bamtInput').value) || 0;
  if (!type) { alert('请选择操作类型'); return false; }
  if (amt === 0) { alert('数量不能为 0'); return false; }
  if (type === 'item' && !document.getElementById('bvalueSelect').value) {
    alert('请选择物品'); return false;
  }
  var userCount = <?php echo $user_count; ?>;
  var typeLabel = document.getElementById('btypeSelect').options[document.getElementById('btypeSelect').selectedIndex].text;
  var itemLabel = type === 'item' ? '「' + document.getElementById('bvalueSelect').value + '」' : '';
  var sign = amt > 0 ? '+' : '';
  return confirm('⚠️ 确认对全部 ' + userCount + ' 名用户执行：\n' + typeLabel + itemLabel + ' ' + sign + amt + '\n此操作不可撤销！');
}
</script>

<?php require_once 'footer.php'; ?>
