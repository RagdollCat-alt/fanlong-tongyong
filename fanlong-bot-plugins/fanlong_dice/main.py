import sys
import os
import json
import random
import re
import OlivOS

# ================= 🔗 连接核心库 =================
current_dir = os.path.dirname(os.path.abspath(__file__))
core_path = os.path.join(current_dir, '..', 'fanlong_core')
if core_path not in sys.path:
    sys.path.append(core_path)

try:
    from lib.db import DB
    from lib.config import GlobalConfig
    from lib.terms import Terms        
    from lib.settings import Settings  
except ImportError as e:
    print(f"[繁笼骰子] 核心库导入失败: {e}")

# ================= ⚙️ 动态配置获取 =================

INTERNAL_STATS = ["stat_face", "stat_charm", "stat_intel", "stat_biz", "stat_talk", "stat_body", "stat_art", "stat_obed"]
ITEM_DB = {}

def load_items():
    global ITEM_DB
    ITEM_DB = {}
    try:
        # 🟢 获取装备加成数值库
        rows = DB.query("SELECT name, stats FROM items")
        
        # 构建标准属性名映射
        standard_names = {k: Terms.get(k) for k in INTERNAL_STATS}
        
        count = 0
        for r in rows:
            name = r[0]
            raw_stats = json.loads(r[1]) if r[1] else {}
            
            # 将中文属性名映射到标准中文属性名
            fixed_stats = {}
            for old_k, v in raw_stats.items():
                matched = False
                for internal_key, current_cn in standard_names.items():
                    if old_k == current_cn or old_k.replace("/", "_") == current_cn.replace("/", "_"):
                        fixed_stats[current_cn] = v
                        matched = True
                        break
                if not matched: fixed_stats[old_k] = v
            
            ITEM_DB[name] = { "stats": fixed_stats }
            count += 1
        return True, count
    except Exception as e:
        print(f"[繁笼骰子] 物品加载失败: {e}")
        return False, 0

# ================= 🔍 辅助逻辑 =================

def get_rpg_name_from_db(user_id, default_nick):
    try:
        rows = DB.query("SELECT name FROM users WHERE id = ?", (str(user_id),))
        if rows: return rows[0][0]
    except: pass
    return default_nick

def resolve_target_key(user_input):
    if not user_input: return None
    for key in INTERNAL_STATS:
        term_name = Terms.get(key)
        if user_input == term_name: return key
        if "/" in term_name and user_input in term_name: return key
    if user_input == Terms.get("term_yuCoin"): return "yuCoin"
    if user_input == Terms.get("term_reputation"): return "reputation"
    return None

def db_get_total_stat(user_id, target_key):
    """
    【同步核心】计算 基础值 + 所有当前装备的加成总和
    """
    user_id = str(user_id)
    base_val = 0
    extra_val = 0
    
    # 1. 获取基础值
    try:
        if target_key.startswith("stat_"):
            # 8大属性从 user_stats 表读取
            sql = f"SELECT {target_key} FROM user_stats WHERE user_id = ?"
            row = DB.query(sql, (user_id,))
            if row and row[0][0] is not None:
                base_val = row[0][0]
        elif target_key in ["yuCoin", "reputation"]:
            # 🟢 修正点：副货币和主货币从 users 表读取
            row = DB.query("SELECT currency FROM users WHERE id = ?", (user_id,))
            if row and row[0][0]:
                currency_data = json.loads(row[0][0])
                # 无论数据库里存的是基础值还是什么，确保拿到英文 key 对应的值
                base_val = currency_data.get(target_key, 0)
                print(f"[骰子调试] 目标:{target_key} 基础值:{base_val}") # 调试输出
    except Exception as e:
        print(f"[骰子基础值读取错误] {e}")

    # 2. 获取装备加成
    try:
        eq_sql = "SELECT hair, top, bottom, head, neck, inner1, inner2, acc1, acc2, acc3, acc4 FROM user_equip WHERE user_id = ?"
        eq_row = DB.query(eq_sql, (user_id,))
        if eq_row and eq_row[0]:
            # 动态获取当前数据库定义的中文名
            if target_key.startswith("stat_"):
                target_cn = Terms.get(target_key)
            else:
                target_cn = Terms.get(f"term_{target_key}")

            for item_name in eq_row[0]:
                if item_name and item_name in ITEM_DB:
                    item_stats = ITEM_DB[item_name].get("stats", {})
                    # 🟢 同时支持中文名匹配和带斜杠的兼容匹配
                    val = item_stats.get(target_cn, 0)
                    if val == 0 and "/" in target_cn:
                        val = item_stats.get(target_cn.replace("/", "_"), 0)
                    
                    extra_val += val
    except Exception as e:
        print(f"[骰子装备加成错误] {e}")

    return base_val + extra_val

def roll_dice(num, sides):
    rolls = [random.randint(1, sides) for _ in range(num)]
    return rolls, sum(rolls)

def is_admin(user_id):
    try:
        sql = "SELECT level FROM admins WHERE user_id = ?"
        row = DB.query(sql, (str(user_id),))
        return row and int(row[0][0]) > 0
    except: return False

# ================= 🚀 标准结构 =================

class Event(object):
    def init(plugin_event, Proc):
        Terms.reload(); Settings.reload(); load_items()
        print("[繁笼骰子] 插件已加载 (全数据装备同步版)")

    def group_message(plugin_event, Proc):
        process_dice(plugin_event, Proc)

    def private_message(plugin_event, Proc):
        process_dice(plugin_event, Proc, is_private=True)

# ================= 🚀 一键同步检测工具 =================

def run_sync_check(plugin_event, target_id):
    """一键对比 RPG 属性与骰子检定数据"""
    target_id = str(target_id)
    user_name = get_rpg_name_from_db(target_id, "未知")
    
    report = [f"📊 【数据同步检测】 目标：{user_name}({target_id})"]
    report.append("-" * 20)
    
    # 定义需要检测的所有属性 Key
    check_list = [
        ("stat_face", None), ("stat_charm", None), ("stat_intel", None),
        ("stat_biz", None), ("stat_talk", None), ("stat_body", None),
        ("stat_art", None), ("stat_obed", None),
        ("reputation", None), ("yuCoin", None)
    ]
    
    for internal_key, default_name in check_list:
        # 1. 获取当前数据库定义的术语名
        display_name = Terms.get(internal_key) if internal_key.startswith("stat_") else Terms.get(f"term_{internal_key}")
        if not display_name: display_name = internal_key
        
        # 2. 调用骰子插件的同步计算函数
        # 该函数应返回：基础值 + 装备加成
        total_val = db_get_total_stat(target_id, internal_key)
        
        # 3. 这里的逻辑用于调试显示细节
        report.append(f"🔹 {display_name} ({internal_key}):")
        report.append(f"   ∟ 最终检定值: {total_val}")
    
    report.append("-" * 20)
    report.append("💡 若数值为 0 或 100(固定值)，请检查 db_get_total_stat 中的 SQL 路径。")
    
    plugin_event.reply("\n".join(report))

# ================= 修改 process_dice 加入指令 =================

def process_dice(plugin_event, Proc, is_private=False):
    try:
        msg = plugin_event.data.message.strip()
        sender_id = str(plugin_event.data.user_id)
    except: return

    # 🟢 新增指令：数据检测
    if msg == "数据检测":
        run_sync_check(plugin_event, sender_id)
        return

    # 管理员重载指令同步刷新
    if msg in ["重载商品", "重载配置"]:
        if is_admin(sender_id):
            Terms.reload(); Settings.reload(); load_items()
        return

    # 匹配 .r 指令
    if not re.match(r'^[.]?r[dh]?', msg, re.IGNORECASE): return

    is_hidden = 'h' in msg.lower()
    cmd_content = re.sub(r'^[.]?r[dh]?\s*', '', msg, flags=re.IGNORECASE).strip()

    # 名字获取
    base_nick = plugin_event.data.sender.get('nickname', '玩家')
    if hasattr(plugin_event.data.sender, 'card') and plugin_event.data.sender['card']:
        base_nick = plugin_event.data.sender['card']
    sender_name = get_rpg_name_from_db(sender_id, base_nick)

    # 骰子解析
    num = 1; sides = 100; target_input = ""
    dice_match = re.match(r'^(\d*)[dD](\d+)(.*)', cmd_content)
    if dice_match:
        n_str, s_str, rest = dice_match.groups()
        num = int(n_str) if n_str else 1
        sides = int(s_str)
        target_input = rest.strip()
    else:
        if cmd_content: target_input = cmd_content
    
    if num > 50: num = 50
    if sides > 10000: sides = 10000

    rolls, total = roll_dice(num, sides)
    roll_detail = f"[{'+'.join(map(str, rolls))}]" if num > 1 else ""

    # 检定逻辑
    judge_text = ""
    if target_input:
        target_val = None
        display_name = target_input

        if target_input.isdigit():
            target_val = int(target_input)
            display_name = "指定值"
        else:
            real_key = resolve_target_key(target_input)
            if real_key:
                # 🟢 此处调用已加固的同步计算函数
                val = db_get_total_stat(sender_id, real_key)
                if val is not None:
                    target_val = val
                    display_name = Terms.get(real_key) if real_key.startswith("stat_") else target_input
        
        if target_val is not None:
            result_str = ""
            CRIT_S = Settings.get("dice_crit_success", 5)
            CRIT_F = Settings.get("dice_crit_fail", 96)
            
            if total <= target_val:
                if sides == 100 and total <= CRIT_S: result_str = "✨大成功✨"
                elif total <= target_val / 5: result_str = "极难成功"
                elif total <= target_val / 2: result_str = "困难成功"
                else: result_str = "成功"
            else:
                if sides == 100 and total >= CRIT_F: result_str = "🔥大失败🔥"
                else: result_str = "失败"
            judge_text = f"\n📊 检定：{display_name}({target_val}) {result_str}"

    final_msg = f"🎲 {sender_name} 掷骰：\n{num}D{sides}={total} {roll_detail}{judge_text}"

    if is_hidden:
        if not is_private:
            plugin_event.reply(f"🎲 {sender_name} 进行了一次暗骰。")
            plugin_event.send('private', int(sender_id), f"🎲【暗骰结果】\n{final_msg}")
        else:
            plugin_event.reply(f"🎲【暗骰】{final_msg}")
    else:
        plugin_event.reply(final_msg)
    plugin_event.set_block(True)