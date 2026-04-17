import sys
import os
import json
import time
import random
import threading
import re
import math
import requests  # 🟢 引入 requests 库用于下载图片
from datetime import datetime
import OlivOS

# ================= 🔗 连接核心库 =================
current_dir = os.path.dirname(os.path.abspath(__file__))
core_path = os.path.join(current_dir, '..', 'fanlong_core')
if core_path not in sys.path:
    sys.path.append(core_path)

try:
    from lib.db import DB
    from lib.config import GlobalConfig
    from lib.terms import Terms        # 🔥 引入文字配置
    from lib.settings import Settings  # 🔥 引入数值配置
except ImportError as e:
    print(f"[繁笼RPG] 核心库导入失败: {e}")

# ================= ⚙️ 静态配置 =================

BASE_DIR = os.path.dirname(__file__)
RECYCLE_PATH = os.path.join(BASE_DIR, 'recycle_bin.json')

# 内部Key列表
KEY_MAP = {
    "slots": ["hair", "top", "bottom", "head", "neck", "inner1", "inner2", "acc1", "acc2", "acc3", "acc4"],
    "stats": ["stat_face", "stat_charm", "stat_intel", "stat_biz", "stat_talk", "stat_body", "stat_art", "stat_obed"]
}

SLOT_GROUPS = {
    "accessory": ["acc1", "acc2", "acc3", "acc4"],
    "interior": ["inner1", "inner2"]
}

ITEM_DB = {}

# ================= 🛠️ 数据库自动升级 (关键) =================

def check_and_update_db():
    """
    🟢 自动检测数据库结构，缺什么字段就补什么
    解决手动运行 SQL 的麻烦
    """
    try:
        # 获取 items 表当前所有列名
        cursor = DB.conn.cursor() if hasattr(DB, 'conn') else None # 兼容不同DB封装

        columns_to_add = [
            ("condition", "TEXT"),       # 购买门槛
            ("max_hold", "INTEGER"),     # 最大持有
            ("compound_recipe", "TEXT"), # 合成配方
            ("sub_type", "TEXT"),        # 子类型
            ("param", "TEXT"),           # 扩展参数 (注意下面加了逗号)
            ("stock_qty", "INTEGER DEFAULT -1") # 全服库存
        ]

        for col_name, col_type in columns_to_add:
            try:
                # 尝试添加列，如果列已存在 sqlite 会报错，catch 住就行
                DB.execute(f"ALTER TABLE items ADD COLUMN {col_name} {col_type}")
                print(f"[繁笼RPG] 数据库自动升级：添加了 {col_name} 字段")
            except:
                pass

        # 🟢 新增：创建道具实例追踪表（支持购买多个相同道具）
        DB.execute('''
            CREATE TABLE IF NOT EXISTS item_instances (
                instance_id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_name TEXT NOT NULL,
                user_id TEXT NOT NULL,
                currency_given INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT (strftime('%s', 'now')),
                UNIQUE(instance_id)
            )
        ''')

        if hasattr(DB, 'commit'): DB.commit()
    except Exception as e:
        print(f"[繁笼RPG] 数据库检查警告: {e}")

# ================= 🛠️ 辅助函数 =================

def load_items():
    global ITEM_DB
    ITEM_DB = {}
    try:
        # 🟢 SQL 增加读取 stock_qty (第14列，索引14)
        sql = "SELECT name, price, currency, type, slot, desc, stats, effect, is_selling, condition, max_hold, compound_recipe, sub_type, param, stock_qty FROM items"
        rows = DB.query(sql)
        
        standard_names = {k: Terms.get(k) for k in KEY_MAP["stats"]}
        standard_names["reputation"] = Terms.get("term_reputation")
        standard_names["yuCoin"] = Terms.get("term_yuCoin")

        for r in rows:
            name = r[0]
            raw_stats = json.loads(r[6]) if r[6] else {}
            
            fixed_stats = {}
            for old_k, v in raw_stats.items():
                matched = False
                for internal_key, current_cn in standard_names.items():
                    if old_k == current_cn or old_k.replace("/", "_") == current_cn.replace("/", "_"):
                        fixed_stats[current_cn] = v
                        matched = True
                        break
                if not matched: fixed_stats[old_k] = v

            ITEM_DB[name] = {
                "price": r[1], "currency": r[2], "type": r[3], "slot": r[4],
                "desc": r[5], "stats": fixed_stats, "effect": json.loads(r[7]) if r[7] else {},
                "is_selling": r[8],
                "condition": json.loads(r[9]) if r[9] else {},
                "max_hold": r[10] if r[10] is not None else 0,
                "compound_recipe": json.loads(r[11]) if r[11] else {},
                "sub_type": r[12] if r[12] else "normal",
                "param": json.loads(r[13]) if r[13] else {},
                "stock_qty": r[14] if r[14] is not None else -1 # 🟢 全服库存
            }
        return True, len(ITEM_DB)
    except Exception as e:
        print(f"物品库加载失败: {e}")
        return False, 0

def get_stat_key(chinese_name):
    for k in KEY_MAP["stats"]:
        term = Terms.get(k)
        if term == chinese_name: return k
        if "/" in term and chinese_name in term: return k
    return None

def get_slot_key(chinese_name):
    for k in KEY_MAP["slots"]:
        term_key = f"slot_{k}"
        if Terms.get(term_key) == chinese_name: return k
    return None

# ================= 💾 数据库核心操作 =================

def _construct_user_from_row(row):
    if not row: return None
    user_id = row[0]
    u = {
        "id": row[0], "uid": row[1], "name": row[2],
        "currency": json.loads(row[3]),
        "profile": json.loads(row[4]),
        "limits": json.loads(row[5]),
        "stats": {}, "bag": {}, "equip": {}
    }
    
    s_row = DB.query("SELECT * FROM user_stats WHERE user_id = ?", (user_id,))
    if s_row:
        vals = s_row[0]
        db_cols = ["stat_face", "stat_charm", "stat_intel", "stat_biz", "stat_talk", "stat_body", "stat_art", "stat_obed"]
        for i, col in enumerate(db_cols):
            u["stats"][Terms.get(col)] = vals[i+1]
    else:
        for k in KEY_MAP["stats"]: u["stats"][Terms.get(k)] = 0

    b_rows = DB.query("SELECT item_name, count FROM user_bag WHERE user_id = ?", (user_id,))
    for br in b_rows: u["bag"][br[0]] = br[1]

    e_row = DB.query("SELECT * FROM user_equip WHERE user_id = ?", (user_id,))
    if e_row:
        vals = e_row[0]
        keys = ["hair", "top", "bottom", "head", "neck", "inner1", "inner2", "acc1", "acc2", "acc3", "acc4"]
        for i, k in enumerate(keys): u["equip"][k] = vals[i+1]
    else:
        u["equip"] = {k: None for k in KEY_MAP["slots"]}
    return u

def db_get_user(user_id):
    user_id = str(user_id)
    rows = DB.query("SELECT * FROM users WHERE id = ?", (user_id,))
    return _construct_user_from_row(rows[0]) if rows else None

def db_get_all_users():
    rows = DB.query("SELECT * FROM users")
    users = []
    for row in rows: users.append(_construct_user_from_row(row))
    return users

def db_save_user(user):
    uid = str(user["id"])
    sql_main = "UPDATE users SET name=?, currency=?, profile=?, limits=? WHERE id=?"
    DB.execute(sql_main, (
        user["name"], json.dumps(user["currency"]), json.dumps(user["profile"]),
        json.dumps(user["limits"]), uid
    ))
    
    s = user["stats"]
    def get_val(internal_key): return s.get(Terms.get(internal_key), 0)
    
    sql_stats = '''
        UPDATE user_stats SET
        stat_face=?, stat_charm=?, stat_intel=?, stat_biz=?,
        stat_talk=?, stat_body=?, stat_art=?, stat_obed=?
        WHERE user_id=?
    '''
    DB.execute(sql_stats, (
        get_val("stat_face"), get_val("stat_charm"), get_val("stat_intel"), get_val("stat_biz"),
        get_val("stat_talk"), get_val("stat_body"), get_val("stat_art"), get_val("stat_obed"),
        uid
    ))

    e = user["equip"]
    sql_equip = '''
        INSERT OR REPLACE INTO user_equip (
            user_id, hair, top, bottom, head, neck, inner1, inner2, acc1, acc2, acc3, acc4
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    '''
    DB.execute(sql_equip, (
        uid, e.get("hair"), e.get("top"), e.get("bottom"), e.get("head"), e.get("neck"),
        e.get("inner1"), e.get("inner2"), e.get("acc1"), e.get("acc2"), e.get("acc3"), e.get("acc4")
    ))
    
    DB.execute("DELETE FROM user_bag WHERE user_id = ?", (uid,))
    for item, count in user["bag"].items():
        if count > 0:
            DB.execute("INSERT INTO user_bag (user_id, item_name, count) VALUES (?, ?, ?)", (uid, item, count))
    
    if hasattr(DB, 'commit'):
        DB.commit()

def db_create_user(user_id, name):
    """创建新用户 - 通用版（无需公民籍、无初始装备）"""
    # 1. 获取新 UID
    row = DB.query("SELECT value FROM system_vars WHERE key='uidCounter'")
    new_uid = 10000
    if row: new_uid = int(row[0][0])

    # 2. 生成基础随机属性
    min_stat = Settings.get("init_stat_min", 1)
    max_stat = Settings.get("init_stat_max", 20)
    stats_db = {k: random.randint(min_stat, max_stat) for k in KEY_MAP["stats"]}

    # 3. 获取初始货币范围
    min_money = Settings.get("init_money_min", 1)
    max_money = Settings.get("init_money_max", 10)
    init_yu = random.randint(min_money, max_money)

    min_rep = Settings.get("init_rep_min", 0)
    max_rep = Settings.get("init_rep_max", 0)
    # 确保 min <= max，否则调整
    if min_rep > max_rep:
        max_rep = min_rep
    init_rep = random.randint(min_rep, max_rep)
    # 调试信息：打印初始配置
    print(f"[创建用户] 初始名誉配置: {min_rep}~{max_rep}, 实际: {init_rep}")

    # 4. 组装数据 (通用版 - 无户籍/公民籍要求)
    currency = {
        "yuCoin": init_yu,
        "reputation": init_rep
    }

    profile = {
        Terms.get("profile_age", "年龄"): "",
        "属性": "",
        Terms.get("profile_char", "性格"): "",
        Terms.get("profile_look", "外貌"): "",
        Terms.get("profile_height", "身高"): "",
        Terms.get("profile_family", "家世"): "",
        Terms.get("profile_job", "职位"): "",
        Terms.get("profile_bg", "背景"): "",
        Terms.get("profile_like", "喜恶"): "",
        Terms.get("profile_taboo", "禁忌"): "",
        Terms.get("profile_salary", "薪资"): "",
        Terms.get("profile_group", "隶属"): "",
        Terms.get("profile_note", "备注"): ""
    }

    # 5. 写入 limit
    limits = {
        "lastSign": "",
        "lastTrain": "",
        "trainCount": 0,
        "lastLuckyBag": "",
        "luckyBagCount": 0
    }

    # 6. 写入数据库
    DB.execute("INSERT INTO users (id, uid, name, currency, profile, limits) VALUES (?,?,?,?,?,?)",
               (str(user_id), new_uid, name, json.dumps(currency), json.dumps(profile), json.dumps(limits)))

    DB.execute('''
        INSERT INTO user_stats (user_id, stat_face, stat_charm, stat_intel, stat_biz, stat_talk, stat_body, stat_art, stat_obed)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ''', (
        str(user_id),
        stats_db["stat_face"], stats_db["stat_charm"], stats_db["stat_intel"], stats_db["stat_biz"],
        stats_db["stat_talk"], stats_db["stat_body"], stats_db["stat_art"], stats_db["stat_obed"]
    ))

    # 通用版不设置初始装备

    DB.execute("INSERT OR REPLACE INTO system_vars (key, value) VALUES ('uidCounter', ?)", (str(new_uid + 1),))

    if hasattr(DB, 'commit'): DB.commit()
    return db_get_user(user_id)

def db_delete_user(user_id):
    """删除用户 - 通用版"""
    user = db_get_user(user_id)
    if not user: return False
    recycle_bin = {}
    if os.path.exists(RECYCLE_PATH):
        try:
            with open(RECYCLE_PATH, 'r', encoding='utf-8') as f: recycle_bin = json.load(f)
        except: pass
    recycle_bin[str(user_id)] = user
    with open(RECYCLE_PATH, 'w', encoding='utf-8') as f:
        json.dump(recycle_bin, f, ensure_ascii=False, indent=2)
    uid = str(user_id)
    DB.execute("DELETE FROM users WHERE id = ?", (uid,))
    DB.execute("DELETE FROM user_stats WHERE user_id = ?", (uid,))
    DB.execute("DELETE FROM user_bag WHERE user_id = ?", (uid,))
    DB.execute("DELETE FROM user_equip WHERE user_id = ?", (uid,))
    if hasattr(DB, 'commit'): DB.commit()
    return True

def db_find_user_by_name(input_str):
    if not input_str: return None
    match = re.search(r'(?:qq|id)=(\d+)', input_str)
    target_uid = match.group(1) if match else (input_str if input_str.isdigit() else None)
    if target_uid: return db_get_user(target_uid)
    clean = input_str.replace("@", "").strip()
    rows = DB.query("SELECT id FROM users WHERE name = ?", (clean,))
    if rows: return db_get_user(rows[0][0])
    return None

def create_item_instances(user_id, item_name, count):
    """
    🟢 为购买的道具创建实例记录
    返回: 实例ID列表
    """
    instance_ids = []
    uid_str = str(user_id)

    for _ in range(count):
        DB.execute(
            "INSERT INTO item_instances (item_name, user_id, currency_given) VALUES (?, ?, 0)",
            (item_name, uid_str)
        )
        instance_ids.append(DB.cursor.lastrowid)

    if hasattr(DB, 'commit'): DB.commit()
    return instance_ids

def get_unused_item_instance(user_id, item_name):
    """
    🟢 获取一个未使用过的道具实例（未发放过货币奖励）
    返回: 实例ID 或 None
    """
    uid_str = str(user_id)
    rows = DB.query(
        "SELECT instance_id FROM item_instances WHERE item_name = ? AND user_id = ? AND currency_given = 0 LIMIT 1",
        (item_name, uid_str)
    )
    return rows[0][0] if rows else None

def mark_instance_currency_given(instance_id):
    """
    🟢 标记道具实例已发放过货币奖励
    """
    DB.execute(
        "UPDATE item_instances SET currency_given = 1 WHERE instance_id = ?",
        (instance_id,)
    )
    if hasattr(DB, 'commit'): DB.commit()

def db_get_reply(key):
    rows = DB.query("SELECT value FROM custom_replies WHERE key = ?", (key,))
    return rows[0][0] if rows else None

def db_set_reply(key, val):
    DB.execute("INSERT OR REPLACE INTO custom_replies (key, value) VALUES (?, ?)", (key, val))
    if hasattr(DB, 'commit'): DB.commit()

def db_del_reply(key):
    rows = DB.query("SELECT value FROM custom_replies WHERE key = ?", (key,))
    if rows:
        content = rows[0][0]
        if content.startswith("image:"):
            file_name = content.replace("image:", "").strip()
            img_path = os.path.join(BASE_DIR, 'data', 'images', file_name)
            try:
                if os.path.exists(img_path):
                    os.remove(img_path)
                    print(f"[繁笼RPG] 已同步删除本地图片: {file_name}")
            except Exception as e:
                print(f"[繁笼RPG] 删除本地图片失败: {e}")
    DB.execute("DELETE FROM custom_replies WHERE key = ?", (key,))
    if hasattr(DB, 'commit'): DB.commit()

def db_get_all_replies():
    rows = DB.query("SELECT key FROM custom_replies")
    return [r[0] for r in rows]

# ================= 业务逻辑 =================

def safe_add_stat(user, attr_cn, amount):
    if attr_cn not in user["stats"]: user["stats"][attr_cn] = 0
    new = user["stats"][attr_cn] + amount
    CAP = Settings.get("stat_cap", 500)
    if amount > 0 and new > CAP: new = CAP
    user["stats"][attr_cn] = new
    return new

def get_stats(user, attr_cn=None):
    if attr_cn:
        val = user["stats"].get(attr_cn, 0)
        if "equip" in user:
            for slot_code, item_name in user["equip"].items():
                if item_name and item_name in ITEM_DB and "stats" in ITEM_DB[item_name]:
                    val += ITEM_DB[item_name]["stats"].get(attr_cn, 0)
        return val
    total = 0
    for k in KEY_MAP["stats"]: total += get_stats(user, Terms.get(k))
    return total

def get_total_reputation(user):
    # 副货币现在是永久属性，不再需要动态计算装备加成
    # 直接返回数据库里存的数值即可
    return user["currency"].get("reputation", 0)
    
    # ❌ 下面这段旧代码要删掉或注释掉，防止双重计算
    # if "equip" in user:
    #     for slot_code, item_name in user["equip"].items():
    #         if item_name and item_name in ITEM_DB and "stats" in ITEM_DB[item_name]:
    #             val += ITEM_DB[item_name]["stats"].get(Terms.get("term_reputation"), 0)
    # return val

def generate_profile(user):
    """生成角色档案 - 通用版"""
    p = user["profile"]
    stats_str = p.get("属性", "")

    # 定义所有档案字段顺序（key, 显示标签）
    field_keys = [
        "profile_name", "profile_age", "profile_sex", "profile_char", "profile_look",
        "profile_height", "profile_family", "profile_job", "profile_bg",
        "profile_like", "profile_taboo", "profile_salary", "profile_group", "profile_note"
    ]

    # 收集可见字段
    lines = []
    lines.append(f"〖{user['name']} 的档案〗")

    for key in field_keys:
        # 跳过不可见字段 (is_hidden=0)
        if not Terms.is_visible(key):
            continue

        # 获取显示标签名（从 Terms 获取最新的 text）
        label = Terms.get(key)

        # 获取值（尝试多个可能的键名，兼容旧数据）
        value = ""
        # 先用当前 Terms 的 text 查找
        value = p.get(label, "")
        # 如果找不到，尝试数据库中可能存在的旧值（如"职位"、"官职"等）
        if not value:
            # 常见的字段名变体
            if key == "profile_job":
                value = p.get("职位") or p.get("官职") or ""
            elif key == "profile_citizen":
                value = p.get("户籍") or p.get("籍贯") or ""
            # 可以继续添加其他字段的兼容逻辑...

        # 特殊处理：姓名直接用 user['name']
        if key == "profile_name":
            value = user['name']

        # 显示格式：字段名：值（如果值为空，只显示"字段名："）
        display_value = value if value else ""
        lines.append(f"{label}：{display_value}")

    # 添加属性字段（如果不为空且可见）
    if stats_str and stats_str.strip() and Terms.is_visible("stat_attr"):
        lines.append(f"属性：{stats_str}")

    return "\n".join(lines)

def format_changes(item, count=1):
    lines = []
    if "stats" in item:
        for k,v in item["stats"].items():
            total = v * count
            lines.append(f"{k} -> {'+' if total>=0 else ''}{total}")
    if "effect" in item:
        for k,v in item["effect"].items():
            total = v * count
            name = Terms.get("term_reputation") if k == "reputation" else Terms.get("term_yuCoin") if k == "yuCoin" else k
            lines.append(f"{name} -> {'+' if total>=0 else ''}{total}")
    return "\n".join(lines)

# ================= 🚀 标准结构 =================

class Event(object):
    def init(plugin_event, Proc):
        # 🟢 新增：启动时检查数据库结构
        check_and_update_db()

        Terms.reload()
        Settings.reload()
        load_items()
        print("[通用RPG] 插件已加载 (V3.0 通用版)")

    def private_message(plugin_event, Proc):
        process_message(plugin_event, Proc, is_private=True)

    def group_message(plugin_event, Proc):
        process_message(plugin_event, Proc, is_private=False)

    def save(plugin_event, Proc): pass
    def menu(plugin_event, Proc): pass
    def poke(plugin_event, Proc): pass

# ================= 核心业务逻辑 =================

def process_message(plugin_event, Proc, is_private=False):
    try:
        # 🟢 第一步：必须先获取消息内容并赋值给 raw_msg
        raw_msg = plugin_event.data.message.strip()
    except Exception as e:
        # 如果获取失败（比如空消息），直接返回
        return

    # 🟢 第二步：现在才可以安全地使用 raw_msg
    if raw_msg.startswith("/"): 
        raw_msg = raw_msg[1:].strip()
    
    # 🟢 第三步：进行精准切分
    parts = raw_msg.split(None, 1)
    if not parts: 
        return
    
    msg_type = parts[0]
    args = raw_msg.split()

    try:
        if hasattr(plugin_event.data, 'user_id'): sender_id = str(plugin_event.data.user_id)
        elif hasattr(plugin_event.data, 'sender_id'): sender_id = str(plugin_event.data.sender_id)
        else: sender_id = str(plugin_event.data.sender.get('user_id', 'Unknown'))
        group_id = int(plugin_event.data.group_id) if (not is_private and hasattr(plugin_event.data, 'group_id')) else None
    except: return

    def send_reply(text):
        content = str(text).strip()
        if content.startswith("image:") or content.startswith("图片:"):
             url = content.replace("image:", "").replace("图片:", "").strip()
             content = f"[CQ:image,file={url}]"
        if is_private: plugin_event.reply(content)
        else: plugin_event.reply(f"[CQ:at,qq={sender_id}]\n{content}")

    def auto_update_card(target_uid, target_user_data):
        if not is_private and group_id and target_user_data:
            time.sleep(1.0) 
            try:
                rows = DB.query("SELECT profile, name FROM users WHERE id = ?", (str(target_uid),))
                if not rows: return
                p = json.loads(rows[0][0]); name = rows[0][1]
                KEY_JOB = Terms.get('profile_job', '职位'); KEY_FAMILY = Terms.get('profile_family', '家世'); KEY_AGE = Terms.get('profile_age', '年龄')
                raw_job = p.get(KEY_JOB, '')
                job = re.sub(r'^[正从次]?[一二三四五六七八九]品', '', raw_job).strip()
                family = p.get(KEY_FAMILY, ''); age = p.get(KEY_AGE, ''); attr = p.get('属性', '')
                card_str = f"【{job}】{name}-{family}-{age}-{attr}"
                plugin_event.set_group_card(group_id, int(target_uid), card_str)
            except Exception as e:
                print(f"[RPG改名错误] {e}")

    user = db_get_user(sender_id)
    msg_type = args[0]
    NAME_YU = Terms.get("term_yuCoin"); NAME_REP = Terms.get("term_reputation")

    # 构建动态商店口令
    SHOP_YU = f"{NAME_YU}商店"
    SHOP_REP = f"{NAME_REP}商店"
    FAMILY_RANK = f"家族{NAME_REP}"

    # --- 1. 余额查询 (修正版) ---
    if msg_type in ["余额", "我的余额"]:
        if not user: send_reply("📋 未查询到档案，请先发送：创建 [姓名]"); return
        # 🟢 调用动态计算函数，确保包含装备加成
        yu_val = user["currency"].get("yuCoin", 0)
        rep_val = get_total_reputation(user) # 使用动态函数
        send_reply(f"💰 {NAME_YU}：{yu_val}\n🏆 {NAME_REP}：{rep_val}")
        return

    if msg_type.lower() in ["openid", "uid"]:
        uid_str = f"\nUID: {user['uid']}" if user else ""
        send_reply(f"🆔 你的OpenID：\n{sender_id}{uid_str}"); return

    if msg_type == "回复列表":
        keys = db_get_all_replies()
        send_reply(f"📜 自定义回复：\n{', '.join(keys)}" if keys else "暂无自定义回复。"); return

    # --- 2. 管理员指令 ---
    if sender_id in GlobalConfig:
        if msg_type in ["重载商品", "重载配置"]:
            Terms.reload(); Settings.reload(); success, count = load_items()
            send_reply(f"✅ 系统配置已热重载！\n📦 商品库：{count} 个\n⚙️ 术语与数值配置已更新。" if success else "❌ 重载失败。"); return

        if msg_type == "设置回复" and len(args) >= 2:
            key = args[1]; raw_msg = plugin_event.data.message
            url_match = re.search(r"url=(https?://[^\s,\]]+)", raw_msg)
            
            if url_match:
                img_url = url_match.group(1)
                try:
                    img_dir = os.path.join(BASE_DIR, 'data', 'images')
                    if not os.path.exists(img_dir): os.makedirs(img_dir)
                    file_name = f"reply_{int(time.time())}.jpg"
                    save_path = os.path.join(img_dir, file_name)
                    
                    # 🟢 补全：执行实际下载逻辑
                    response = requests.get(img_url, timeout=10)
                    if response.status_code == 200:
                        db_del_reply(key) # 覆盖前先物理删除旧图片
                        with open(save_path, "wb") as f: f.write(response.content)
                        db_set_reply(key, f"image:{file_name}")
                        send_reply(f"✅ 图片录入成功！\n关键词：{key}\n(已转存本地，永久有效)"); return
                    else:
                        send_reply("❌ 图片下载失败：网络响应异常。"); return
                except Exception as e:
                    send_reply(f"❌ 录入异常：{e}"); return
            else:
                val = raw_msg[raw_msg.find(key)+len(key):].strip()
                if val: db_set_reply(key, val); send_reply(f"✅ 已添加回复：\n输入【{key}】\n回复【{val}】"); return

        if msg_type == "删除回复" and len(args) >= 2:
            db_del_reply(args[1]); send_reply(f"🗑️ 已删除关键词【{args[1]}】及其资源。"); return

        # --- 管理员增加/扣除指令 (精准匹配版) ---
        if msg_type in ["增加", "扣除", "加全员", "减全员"]:
            target = None
            item_part = ""
            
            # 1. 解析参数，寻找目标用户
            for a in args[1:]:
                found = db_find_user_by_name(a)
                if found: target = found
                else: item_part = a
            
            # 2. 解析物品与数量
            parts = item_part.split("*")
            what = parts[0]
            amt = int(parts[1]) if len(parts) > 1 else 1  # 默认为1
            if amt == 0: amt = 1 # 防止误输入0
            
            # 3. 处理扣除符号
            if msg_type in ["扣除", "减全员"]: 
                amt = -amt

            # 🟢 分支 A：处理全员操作
            if "全员" in msg_type:
                count = 0
                for u in db_get_all_users():
                    # 货币
                    if what == NAME_YU: u["currency"]["yuCoin"] += amt
                    elif what == NAME_REP: u["currency"]["reputation"] += amt
                    # 属性
                    elif get_stat_key(what): 
                        safe_add_stat(u, what, amt)
                    # 道具
                    elif what in ITEM_DB:
                        u["bag"][what] = u["bag"].get(what, 0) + amt
                        if u["bag"][what] <= 0: u["bag"].pop(what, None)
                    
                    db_save_user(u)
                    count += 1
                send_reply(f"📢【管理员指令】全员 {what} {'+' if amt>0 else ''}{amt}\n(已执行 {count} 人)"); 
                return

            # 🟢 分支 B：处理单人操作 (这部分不能漏！)
            if target:
                # 货币
                if what == NAME_YU: target["currency"]["yuCoin"] += amt
                elif what == NAME_REP: target["currency"]["reputation"] += amt
                # 属性
                elif get_stat_key(what): 
                    safe_add_stat(target, what, amt)
                # 道具
                elif what in ITEM_DB:
                    target["bag"][what] = target["bag"].get(what, 0) + amt
                    if target["bag"][what] <= 0: target["bag"].pop(what, None)
                
                db_save_user(target)
                send_reply(f"⚖️【管理员指令】 {target['name']} {what} {'+' if amt>0 else ''}{amt}")
                return

        # --- 1. 录入档案指令 (回退原版逻辑) ---
        if msg_type == "录入档案":
            at_match = re.search(r"\[CQ:at,qq=(\d+)\]", plugin_event.data.message)
            if at_match:
                target_uid = at_match.group(1)
                rows = DB.query("SELECT id, uid, name, profile FROM users WHERE id = ?", (target_uid,))
                target = {"id": rows[0][0], "uid": rows[0][1], "name": rows[0][2], "profile": json.loads(rows[0][3])} if rows else None
            else:
                target_name = args[1] if len(args) > 1 else ""
                target = db_find_user_by_name(target_name)
            
            if not target: 
                send_reply(f"❌ 找不到该用户。")
                return
            
            lines = raw_msg.split('\n')
            log = []
            new_name = target["name"]
            for line in lines:
                if "录入档案" in line: continue
                parts = line.replace("：", ":").split(":")
                if len(parts) >= 2:
                    k = parts[0].strip()
                    v = ":".join(parts[1:]).strip() # 增强兼容性，防止内容带冒号截断
                    if k and v:
                        if k == Terms.get("profile_name", "姓名"):
                            new_name = v
                            log.append(f"姓名 -> {v}") # 🟢 强制赋值展示
                        else:
                            target["profile"][k] = v
                            log.append(f"{k} -> {v}")
            try:
                # 🟢 确保数据库更新
                DB.execute("UPDATE users SET name = ?, profile = ? WHERE id = ?", 
                           (new_name, json.dumps(target["profile"], ensure_ascii=False), target["id"]))
                
                # 数据库 DB.execute 内部已自带 commit
                
                send_reply(f"✅ 档案录入成功！\n📝 目标：{new_name}\n✏️ 更新项：\n" + "\n".join(log))
                
                # 更新内存并触发当前群改名
                target["name"] = new_name
                auto_update_card(target['id'], target)
            except Exception as e:
                send_reply(f"❌ 数据库更新失败: {e}")
            return
        
        # --- 2. 录入指令 (回退原版批量逻辑) ---
        if msg_type == "录入" and len(args) >= 3:
            targets = []; key_idx = -1
            for i, a in enumerate(args[1:], 1):
                found_u = db_find_user_by_name(a)
                if found_u:
                    if found_u['id'] not in [t['id'] for t in targets]: targets.append(found_u)
                else: 
                    key_idx = i; break
            
            if targets and key_idx != -1:
                field = args[key_idx]
                content = " ".join(args[key_idx+1:])
                names = []
                for t in targets:
                    if field == Terms.get("profile_name"): 
                        t["name"] = content
                    t["profile"][field] = content
                    names.append(t["name"])
                    # 执行保存与触发
                    db_save_user(t)
                    auto_update_card(t['id'], t)
                
                send_reply(f"✅ 批量更新成功！\n👤 涉及人员：{'、'.join(names)}\n📝 更新项：\n{field} -> {content}")
                return
                
        if msg_type == "删除" and len(args) >= 2:
            target = db_find_user_by_name(args[1])
            if target: db_delete_user(target['id']); send_reply(f"已删除 {target['name']}")
            else: send_reply("❌ 删除失败：找不到该用户。"); return

        if msg_type == "发薪资":
            count = 0; total = 0
            all_users = db_get_all_users()
            for u in all_users:
                try:
                    salary_key = Terms.get("profile_salary", "薪资")
                    salary_str = u["profile"].get(salary_key, "0")
                    nums = re.findall(r'\d+', str(salary_str))
                    salary = int(nums[0]) if nums else 0
                    if salary > 0:
                        u["currency"]["yuCoin"] += salary
                        db_save_user(u)
                        count += 1; total += salary
                except: pass
            send_reply(f"💸 发薪完成\n👥 发放人数：{count} 人\n💰 总发放额：{total} {NAME_YU}")
            return

    # --- 核心：自定义回复触发逻辑（优先判定） ---
    reply_content = db_get_reply(msg_type)
    if reply_content:
        if reply_content.startswith("image:"):
            file_name = reply_content.replace("image:", "").strip()
            img_path = os.path.join(BASE_DIR, 'data', 'images', file_name)
            if os.path.exists(img_path):
                # 🟢 绝对路径转换，解决第二天失效问题
                abs_path = os.path.abspath(img_path).replace('\\', '/')
                send_reply(f"[CQ:image,file=file:///{abs_path}]")
            else:
                send_reply(f"[CQ:image,file={file_name}]")
        else:
            send_reply(reply_content)
        return

    # --- 3. 基础逻辑（创建、签到、训练等） -----
    if msg_type in ["创建", "注册"]:
        if user: send_reply("❌ 失败：你已经有档案了。请发送【档案】查看。"); return
        if len(args) < 2: send_reply("❌ 格式错误。示例：创建 李四"); return
        name = args[1]
        if db_find_user_by_name(name): send_reply("❌ 该姓名已被登记。"); return
        user = db_create_user(sender_id, name)
        # 只显示可见的属性字段（过滤掉 is_hidden=0 的字段）
        stats_txt = "\n".join([
            f"{Terms.get(k)}：{user['stats'][Terms.get(k)]}"
            for k in KEY_MAP["stats"]
            if Terms.is_visible(k)
        ])
        send_reply(f"✅ 档案创建成功！\n〖角色档案〗\n姓名：{name}\n{stats_txt}\n----------------\n{NAME_YU}：{user['currency']['yuCoin']}")
        auto_update_card(sender_id, user); return

    if not user:
        if msg_type in ["档案", "属性", "背包", "签到", "余额"]: send_reply("📋 未查询到档案，请先发送：创建 [姓名]"); return
    
    if msg_type == "档案": send_reply(generate_profile(user)); return
    if msg_type == "查档案" and len(args) > 1:
        t = db_find_user_by_name(args[1]); send_reply(generate_profile(t) if t else "❌ 查无此人。"); return

    # ... (前面的代码)

    # 🟢 纯Python 动态名片生成指令
    if msg_type in ["我的名片", "查看名片"]:
        target = user
        if len(args) > 1:
            found = db_find_user_by_name(args[1])
            if found: target = found
            else: send_reply(f"❌ 查无此人：{args[1]}"); return
        
        if not target: send_reply("❌ 未查询到档案。"); return

        send_reply(f"🎨 正在生成【{target['name']}】的身份协议面板...")

        try:
            # 获取当前 main.py 所在的文件夹路径
            curr_dir = os.path.dirname(os.path.abspath(__file__))
            # 如果这个路径不在系统搜索列表里，强行加进去
            if curr_dir not in sys.path:
                sys.path.append(curr_dir)
            # === 🔍 强制路径修复结束 ===

            # 1. 动态导入绘图模块
            import card_renderer
            
            # (防止修改代码后不生效，强制重载一下)
            import importlib
            importlib.reload(card_renderer)
            
            # 2. 准备 8 维属性数据 (确保顺序)
            # 注意：你的数据库里保存的是英文key，这里转成中文方便显示
            stat_order = ["stat_face", "stat_charm", "stat_intel", "stat_biz",
                          "stat_talk", "stat_body", "stat_art", "stat_obed"]
            
            stats_data = {}
            for k in stat_order:
                cn_key = Terms.get(k) # 获取中文名
                # 调用你现有的 get_stats 函数计算总值 (含装备)
                val = get_stats(target, cn_key) 
                stats_data[cn_key] = val
            
            # 3. 调用绘图
            img_path = card_renderer.render(target, stats_data)
            
            # 4. 发送
            abs_path = os.path.abspath(img_path).replace("\\", "/")
            send_reply(f"[CQ:image,file=file:///{abs_path}]")
            
        except Exception as e:
            import traceback
            traceback.print_exc()
            send_reply(f"❌ 生成失败：{e}")
        return

    if msg_type == "签到":
        today = datetime.now().strftime("%a %b %d %Y")
        if user["limits"].get("lastSign") == today: send_reply("📅 今日已签到。"); return
        min_rw, max_rw = Settings.get("signin_reward_min", 1), Settings.get("signin_reward_max", 10)
        curr_type = Terms.get("config_signin_currency", "yuCoin"); r = random.randint(min_rw, max_rw)
        if curr_type == "reputation": user["currency"]["reputation"] += r; unit = NAME_REP
        else: user["currency"]["yuCoin"] += r; unit = NAME_YU
        user["limits"]["lastSign"] = today; db_save_user(user); send_reply(f"✅ 签到成功，{unit}+{r}。"); return

    if msg_type == "训练":
        today = datetime.now().strftime("%a %b %d %Y")
        if user["limits"].get("lastTrain") != today:
            user["limits"]["lastTrain"] = today; user["limits"]["trainCount"] = 0
        LIMIT = Settings.get("daily_train_limit", 2)
        if user["limits"]["trainCount"] >= LIMIT: send_reply(f"💪 今日训练次数已达上限 ({LIMIT}次)。"); return
        random_key = random.choice(KEY_MAP["stats"]); attr_cn = Terms.get(random_key)
        CAP = Settings.get("stat_cap", 500)
        if user["stats"][attr_cn] >= CAP:
            user["limits"]["trainCount"] += 1; db_save_user(user)
            send_reply(f"💦 训练完成，但【{attr_cn}】已达上限({CAP})，无法继续提升。\n今日次数：{user['limits']['trainCount']}/{LIMIT}")
        else:
            safe_add_stat(user, attr_cn, 1)
            user["limits"]["trainCount"] += 1; db_save_user(user)
            send_reply(f"💦 训练完成，{attr_cn} +1。\n今日次数：{user['limits']['trainCount']}/{LIMIT}")
        return

    # --- 4. 抽盲盒逻辑 (V8 双重直连版：名字+概率全都不走缓存) ---
    if msg_type in ["抽盲盒", "盲盒"]:
        today = datetime.now().strftime("%a %b %d %Y")
        
        # 每日重置逻辑
        if user["limits"].get("lastLuckyBag") != today:
            user["limits"]["lastLuckyBag"] = today
            user["limits"]["luckyBagCount"] = 0
            
        # ==========================================
        # 🟢 内部函数：通用直连查库 (支持 int, float, str)
        # ==========================================
        def get_config_direct(key, default_val, val_type="int"):
            try:
                # 每次都发起新的 SQL 查询，确保拿到最新的
                rows = DB.query("SELECT value FROM game_config WHERE key=?", (key,))
                if rows:
                    val = rows[0][0]
                    if val_type == "int": return int(val)
                    if val_type == "float": return float(val)
                    if val_type == "str": return str(val)
                return default_val
            except:
                return default_val
                
        # 1. 获取基础配置 (全部直连)
        LIMIT = get_config_direct("daily_box_limit", 10, "int")
        COST = get_config_direct("box_cost", 4, "int")
        min_prize = get_config_direct("box_reward_min", -2, "int")
        max_prize = get_config_direct("box_reward_max", 8, "int")
        
        # 2. 限制判断
        if user["limits"]["luckyBagCount"] >= LIMIT:
            send_reply(f"📦 今日盲盒次数已尽 ({LIMIT}次)。")
            return
            
        if user["currency"]["yuCoin"] < COST:
            send_reply(f"❌ 余额不足，抽盲盒需要 {COST} {NAME_YU}。\n当前余额：{user['currency']['yuCoin']}")
            return
            
        # 3. 扣费
        user["currency"]["yuCoin"] -= COST
        
        # 4. 生成奖励
        prize = random.randint(min_prize, max_prize)
        reward_type = Terms.get("config_box_currency", "yuCoin")
        
        if reward_type == "reputation":
            unit = NAME_REP; user["currency"]["reputation"] += prize
            comment = "🎁 盲盒开启！"
        else:
            unit = NAME_YU; user["currency"]["yuCoin"] += prize
            # 文案逻辑
            net = prize - COST
            if net >= 3: comment = "✨ 欧皇附体！"
            elif net > 0: comment = "小赚一笔。"
            elif net == 0: comment = "保本不亏。"
            elif net >= -3: comment = "小亏一点。"
            else: comment = "😭 非酋 "
            
        # 5. 碎片掉落 (🔴核心修复：名字也直连数据库)
        # 之前这里用的 Settings.get 导致读到旧名字，现在改成直连！
        frag_name = get_config_direct("box_fragment_name", "礼盒碎片", "str")
        frag_rate = get_config_direct("box_fragment_rate", 20, "float")

        extra_msg = ""
        
        # 🔴 [调试信息] 如果还不行，请截图这个控制台红字给我！
        # 它可以证明机器人到底在找"礼盒碎片"还是"幸运碎片"
        is_exist = frag_name in ITEM_DB
        print(f"🔴 [盲盒调试] 目标:{frag_name} | 存在:{is_exist} | 概率:{frag_rate}%")

        if is_exist and random.uniform(0, 100) <= frag_rate:
            user["bag"][frag_name] = user["bag"].get(frag_name, 0) + 1
            extra_msg = f"\n🧩 意外获得：{frag_name} * 1"

        # 6. 保存并回复
        user["limits"]["luckyBagCount"] += 1
        db_save_user(user)
        
        send_reply(f"🎲 盲盒开启 (消耗{COST}{NAME_YU})...\n💰 结果：{prize} {unit}\n{comment}{extra_msg}")
        return

    # --- 5. 商店与购买 (优化版：显示库存+限购) ---
    if msg_type == "商店" or msg_type == SHOP_YU or msg_type == SHOP_REP:
        is_rep = (msg_type == SHOP_REP); currency = "reputation" if is_rep else "yuCoin"
        page = int(args[1]) if len(args) > 1 and args[1].isdigit() else 1
        PAGE_SIZE = 15

        valid_items = []
        for k, v in ITEM_DB.items():
            # if v.get("stock_qty") == 0: continue # 如果不想显示已售罄的商品，取消这行注释

            if v.get("currency") == currency and v.get("is_selling", 1) == 1 and v.get("price", 0) >= 0:
                price_unit = NAME_REP if is_rep else NAME_YU
                
                # 🟢 1. 处理库存显示
                stock = v.get("stock_qty", -1)
                if stock == -1:
                    stock_str = "" # 无限库存时不显示，保持界面清爽
                elif stock == 0:
                    stock_str = " [❌已售罄]"
                else:
                    stock_str = f" [剩{stock}]"
                
                # 🟢 2. 处理限购显示 (新增)
                limit = v.get("max_hold", 0)
                limit_str = f" [限购:{limit}]" if limit > 0 else ""
                
                # 🟢 3. 组合显示
                # 修改后的排版逻辑：名称与价格一行，描述另起一行并缩进
                valid_items.append(f"┏━━ 🏷️ {k}\n┃ 💰 价格：{v['price']}{price_unit}{stock_str}{limit_str}\n┗ 📝 {v['desc']}")
        
        total_pages = math.ceil(len(valid_items) / PAGE_SIZE)
        if page > total_pages: page = total_pages
        if page < 1: page = 1
        
        start = (page - 1) * PAGE_SIZE
        end = start + PAGE_SIZE
        current_items = valid_items[start:end]
        
        footer = f"\n第 {page}/{total_pages} 页 | 发送【商店 页码】翻页" if total_pages > 1 else ""
        shop_title = f"==== {NAME_REP if is_rep else NAME_YU}商店 ====\n"
        send_reply(shop_title + "\n".join(current_items) + footer if current_items else "🏪 商店货架空空如也。")
        return
        
    if msg_type == "购买" and len(args) >= 2:
        item_part = args[1]; count_part = args[2] if len(args)>2 else "1"
        if "*" in item_part: item_part, count_part = item_part.split("*")
        item_name = item_part; count = int(count_part) if count_part.isdigit() else 1
        item = ITEM_DB.get(item_name)
        if not item or item.get("is_selling", 1) == 0: send_reply("❌ 购买失败：商品不存在或已下架。"); return
        
        # 🟢 检查全服库存 (Stock)
        stock = item.get("stock_qty", -1)
        if stock != -1:
            if stock < count:
                send_reply(f"❌ 库存不足：全服仅剩 {stock} 件，无法购买 {count} 件。"); return

        # 检查购买门槛
        conditions = item.get("condition", {})
        if conditions:
            for cond_k, cond_v in conditions.items():
                if cond_k == "reputation":
                    if get_total_reputation(user) < cond_v:
                        send_reply(f"❌ 购买门槛未达标：需要 {NAME_REP} ≥ {cond_v}"); return
                elif get_stat_key(cond_k):
                    my_stat = get_stats(user, Terms.get(cond_k))
                    if my_stat < cond_v:
                        send_reply(f"❌ 购买门槛未达标：需要 {Terms.get(cond_k)} ≥ {cond_v}"); return

        # 检查个人最大持有
        max_hold = item.get("max_hold", 0)
        if max_hold > 0:
            current_hold = user["bag"].get(item_name, 0)
            if current_hold + count > max_hold:
                send_reply(f"❌ 购买受限：个人最多持有 {max_hold} 个。"); return

        cost = item["price"] * count; curr = item["currency"]
        if user["currency"][curr] < cost: send_reply(f"❌ 余额不足。需要 {cost} {NAME_YU if curr == 'yuCoin' else NAME_REP}。"); return
        
        user["currency"][curr] -= cost
        if item_name == "正面新闻":
            user["currency"]["reputation"] += (5 * count); db_save_user(user); send_reply(f"✅ 购买并使用正面新闻，{NAME_REP}+{5*count}"); return

        # 🟢 扣除全服库存 (DB + 内存)
        if stock != -1:
            DB.execute("UPDATE items SET stock_qty = stock_qty - ? WHERE name = ?", (count, item_name))
            item['stock_qty'] -= count # 更新内存，无需重载即可生效

        # 🟢 为购买的道具创建实例记录（支持多个相同道具）
        create_item_instances(sender_id, item_name, count)

        user["bag"][item_name] = user["bag"].get(item_name, 0) + count; db_save_user(user)
        send_reply(f"✅ 购买成功：{item_name} * {count}\n介绍：{item['desc']}"); return

    if msg_type in ["背包", "我的背包"]:
        bag = [f"▪ {k} x{v} | {ITEM_DB.get(k,{}).get('desc','无描述')}" for k,v in user["bag"].items() if v > 0]
        send_reply(f"🎒 背包清单：\n" + "\n".join(bag) if bag else "📭 背包空空如也。"); return

    # --- 6. 使用物品 ---
    if msg_type == "使用" and len(args) >= 2:
        item_part = args[1]
        count = 1
        extra_arg = args[2] if len(args) > 2 else None # 获取额外参数(新名字/选定的属性)
        
        if "*" in item_part: item_part, count_str = item_part.split("*"); count = int(count_str)
        item_name = item_part
        
        if user.get("bag", {}).get(item_name, 0) < count: send_reply(f"❌ 使用失败：背包内没有足够的【{item_name}】。"); return
        item = ITEM_DB.get(item_name)
        if not item: send_reply("❌ 物品不存在。"); return

        # 🟢 新增分支：改名卡
        if item.get("sub_type") == "rename_card":
            if not extra_arg: send_reply(f"❌ 使用失败：请指定新名字。\n格式：使用 {item_name} 新名字"); return
            new_name = extra_arg
            if db_find_user_by_name(new_name): send_reply("❌ 改名失败：该名字已被使用。"); return
            
            user["bag"][item_name] -= 1
            if user["bag"][item_name] <= 0: del user["bag"][item_name]
            user["name"] = new_name
            user["profile"][Terms.get("profile_name", "姓名")] = new_name
            db_save_user(user)
            auto_update_card(user['id'], user) # 触发群名片刷新
            send_reply(f"✅ 改名成功！你现在是【{new_name}】了。"); return

        # 🟢 新增分支：自选属性礼包
        elif item.get("sub_type") == "optional_pack":
            if not extra_arg: send_reply(f"❌ 使用失败：请指定要增加的属性。\n可选：{','.join(item.get('param', []))}"); return
            target_attr = extra_arg
            allowed = item.get("param", []) 
            if target_attr not in allowed: send_reply(f"❌ 该礼包不支持属性【{target_attr}】。\n可选：{','.join(allowed)}"); return
            
            amount = item.get("effect", {}).get("amount", 1) * count
            user["bag"][item_name] -= count
            if user["bag"][item_name] <= 0: del user["bag"][item_name]
            
            key = get_stat_key(target_attr)
            if key: safe_add_stat(user, Terms.get(key), amount)
            elif target_attr == NAME_REP: user["currency"]["reputation"] += amount
            elif target_attr == NAME_YU: user["currency"]["yuCoin"] += amount
            
            db_save_user(user)
            send_reply(f"✅ 已使用 {item_name}*{count}，{target_attr} +{amount}"); return

        # 原有逻辑：普通消耗品
        elif item["type"] == "consumable":
            user["bag"][item_name] -= count
            if user["bag"][item_name] <= 0: del user["bag"][item_name]
            if "effect" in item:
                for k, v in item["effect"].items():
                    if k=="reputation": user["currency"]["reputation"] += v*count
                    elif get_stat_key(k): safe_add_stat(user, k, v*count)
            db_save_user(user); send_reply(f"✅ 已使用：{item_name}*{count}\n{format_changes(item, count)}"); return
        
        elif item["type"] == "equip": send_reply("❌ 这是装备，请使用【换上 装备名】指令。"); return
    
    # --- 7. 兑换 (合成/配方/货币) [修复版] ---
    if msg_type == "兑换" and len(args) >= 2:
        parts = args[1].split("*")
        target_thing = parts[0]
        count = int(parts[1]) if len(parts)>1 else 1
        
        # A: 合成系统 (优先判断是否为物品配方)
        target_item = ITEM_DB.get(target_thing)
        if target_item and target_item.get("compound_recipe"):
            recipe = target_item["compound_recipe"]
            
            # 🟢 检查库存
            stock = target_item.get("stock_qty", -1)
            if stock != -1 and stock < count:
                send_reply(f"❌ 兑换失败：目标物品全服库存不足 (剩余 {stock})。")
                return

            # 1. 检查材料
            missing = []
            cost_info = []
            cost_yu = recipe.get("yuCoin", 0) * count
            cost_rep = recipe.get("reputation", 0) * count
            if cost_yu > 0 and user["currency"]["yuCoin"] < cost_yu: missing.append(f"{NAME_YU}不足")
            if cost_rep > 0 and user["currency"]["reputation"] < cost_rep: missing.append(f"{NAME_REP}不足")
            
            for mat_name, mat_count in recipe.items():
                if mat_name in ["yuCoin", "reputation"]: continue
                req_total = mat_count * count
                if user.get("bag", {}).get(mat_name, 0) < req_total:
                    missing.append(f"{mat_name}不足")
                cost_info.append(f"{mat_name} x {req_total}")

            if missing:
                send_reply(f"❌ 兑换失败：\n" + "\n".join(missing))
                return

            # 检查个人持有上限
            max_hold = target_item.get("max_hold", 0)
            if max_hold > 0 and (user["bag"].get(target_thing, 0) + count > max_hold):
                 send_reply(f"❌ 兑换失败：个人持有上限 {max_hold}。")
                 return

            # 2. 扣除材料
            if cost_yu > 0: user["currency"]["yuCoin"] -= cost_yu
            if cost_rep > 0: user["currency"]["reputation"] -= cost_rep
            
            for mat_name, mat_count in recipe.items():
                if mat_name in ["yuCoin", "reputation"]: continue
                req_total = mat_count * count
                user["bag"][mat_name] -= req_total
                if user["bag"][mat_name] <= 0: del user["bag"][mat_name]

            # 🟢 3. 扣除全服库存 & 发放
            if stock != -1:
                DB.execute("UPDATE items SET stock_qty = stock_qty - ? WHERE name = ?", (count, target_thing))
                target_item['stock_qty'] -= count

            # 🟢 为兑换的道具创建实例记录
            create_item_instances(sender_id, target_thing, count)

            user["bag"][target_thing] = user["bag"].get(target_thing, 0) + count
            db_save_user(user)

            cost_str = "\n".join(cost_info)
            if cost_yu > 0: cost_str += f"\n{NAME_YU} x {cost_yu}"

            send_reply(f"✅ 兑换成功！\n获得：{target_thing} x {count}\n消耗：\n{cost_str}")
            return
            
        # 🟢 B: 货币互转逻辑 (修复 UnboundLocalError)
        # 修复点：将 curr_type 改为 target_thing
        RATE = Settings.get("exchange_rate", 1000)

        # 情况1: 兑换 副货币 (消耗 主货币)
        if target_thing == NAME_REP:
             cost = count * RATE
             if user["currency"]["yuCoin"] < cost:
                 send_reply(f"❌ 兑换失败：余额不足。\n需要 {cost} {NAME_YU}。")
                 return
             user["currency"]["yuCoin"] -= cost
             user["currency"]["reputation"] += count
             db_save_user(user)
             send_reply(f"✅ 兑换成功\n{NAME_YU} -> -{cost}\n{NAME_REP} -> +{count}\n(汇率 {RATE}:1)")
             return

        # 情况2: 兑换 主货币 (消耗 副货币)
        elif target_thing == NAME_YU:
             cost = math.ceil(count / RATE)
             if user["currency"]["reputation"] < cost:
                 send_reply(f"❌ {NAME_REP}不足。\n需要 {cost} {NAME_REP}。")
                 return
             user["currency"]["reputation"] -= cost
             user["currency"]["yuCoin"] += count
             db_save_user(user)
             send_reply(f"✅ 兑换成功\n{NAME_REP} -> -{cost}\n{NAME_YU} -> +{count}\n(汇率 1:{RATE})")
             return
             
        # 情况3: 输入了不存在的东西
        else:
            send_reply(f"❌ 无法兑换：【{target_thing}】不是合成配方，也不是货币。")
            return

    if msg_type in ["换上", "装备"] and len(args) >= 2:
        item_name = args[1]; target_slot_input = args[2] if len(args) > 2 else None
        if user.get("bag", {}).get(item_name, 0) <= 0: send_reply(f"❌ 失败：你的背包里没有【{item_name}】。"); return
        item_info = ITEM_DB.get(item_name)
        if not item_info or item_info["type"] != "equip": send_reply("❌ 失败：该物品不存在或不是装备。"); return
        slot_type = item_info["slot"]; target_slot_key = None
        if slot_type in ["top", "bottom", "hair", "head", "neck"]:
            if target_slot_input: send_reply(f"⚠️ 提示：不可指定位置，请使用【换上 装备名】指令。"); return
            target_slot_key = slot_type
        elif slot_type in SLOT_GROUPS:
            candidates = SLOT_GROUPS[slot_type]
            if target_slot_input:
                mapped = get_slot_key(target_slot_input)
                if not mapped or mapped not in candidates: send_reply(f"❌ 失败：位置【{target_slot_input}】不存在或不可装备。"); return
                target_slot_key = mapped
            else:
                empty = [k for k in candidates if not user["equip"][k]]
                target_slot_key = random.choice(empty) if empty else random.choice(candidates)
        if target_slot_key:
            # 1. 处理旧装备 (脱下)
            old = user["equip"][target_slot_key]
            if old:
                user["bag"][old] = user["bag"].get(old, 0) + 1
            
            # 2. 扣除背包新装备
            user["bag"][item_name] -= 1
            if user["bag"][item_name] <= 0: del user["bag"][item_name]
            
            # 3. 更新身上装备
            user["equip"][target_slot_key] = item_name

            # ==========================================
            # 🟢 新增核心逻辑：首次穿戴获得永久主货币/副货币
            # 修复：改为道具实例级别记录，每个道具实例只发放一次
            # ==========================================
            bonus_msg = ""

            # 检查该用户对该装备是否有未发放过货币奖励的实例
            # 🟢 关键修复：查询该用户背包中是否有未使用货币奖励的实例
            unused_instance = get_unused_item_instance(sender_id, item_name)

            if unused_instance:
                # 如果有未发放过货币奖励的实例，则发放
                if "stats" in item_info:
                    added = []
                    for k, v in item_info["stats"].items():
                        # 如果属性是副货币
                        if k == NAME_REP:
                            user["currency"]["reputation"] += v
                            added.append(f"{NAME_REP}+{v}")
                        # 如果属性是主货币
                        elif k == NAME_YU:
                            user["currency"]["yuCoin"] += v
                            added.append(f"{NAME_YU}+{v}")

                    # 如果确实有增加货币，才标记该实例已使用
                    if added:
                        # 🟢 标记该道具实例已发放过货币奖励
                        mark_instance_currency_given(unused_instance)
                        bonus_msg = f"\n✨ 首次佩戴奖励：{' '.join(added)}"

            db_save_user(user); 
            # ==========================================
            # 🟢 核心优化：显示过滤
            # 为了防止普通属性栏里重复显示货币属性或误导玩家
            # 我们构造一个临时的 display_item，把货币属性剔除掉
            # ==========================================
            display_item = item_info.copy() # 浅拷贝 item
            if "stats" in item_info:
                display_item["stats"] = item_info["stats"].copy() # 深拷贝 stats 字典

                # 剔除副货币
                if NAME_REP in display_item["stats"]:
                    del display_item["stats"][NAME_REP]

                # 剔除主货币
                if NAME_YU in display_item["stats"]:
                    del display_item["stats"][NAME_YU]

            # 发送消息 (使用过滤后的 display_item 生成普通属性文本)
            # format_changes 会自动处理剩下的 "威慑+25"
            send_reply(f"✅ 已换上：{item_name}\n👘 位置：{Terms.get('slot_'+target_slot_key)}{bonus_msg}\n{format_changes(display_item)}")
            return

    if msg_type == "卸下" and len(args) >= 2:
        item_name = args[1]

        # 1. 特殊物品拦截
        if item_name == "家徽烙印•罪":
            send_reply("❌ 耻辱的烙印已深入骨髓，无法自行卸下。")
            return

        # 2. 查找装备
        found = next((k for k, v in user["equip"].items() if v == item_name), None)
        if not found: send_reply(f"❌ 失败：你当前没有穿戴【{item_name}】。"); return
        
        item_info = ITEM_DB.get(item_name)
        
        # 3. 执行卸下 (不涉及任何货币扣除)
        user["equip"][found] = None
        user["bag"][item_name] = user["bag"].get(item_name, 0) + 1
        db_save_user(user)

        # 🟢 4. 优化显示：过滤掉货币属性，只显示普通属性变化
        # 这样机器人就不会报货币属性的假消息了
        display_stats = {}
        if item_info and "stats" in item_info:
            display_stats = item_info["stats"].copy()
            # 移除货币键，确保不显示扣除提示
            if Terms.get("term_reputation") in display_stats: 
                del display_stats[Terms.get("term_reputation")]
            if Terms.get("term_yuCoin") in display_stats: 
                del display_stats[Terms.get("term_yuCoin")]
        
        # 构造临时的 item 对象用于生成文本
        temp_item_for_display = {"stats": display_stats}
        change_str = format_changes(temp_item_for_display, -1)
        
        send_reply(f"✅ 已卸下：{item_name}\n原位置：{Terms.get('slot_'+found)}\n{change_str}")
        return

    if msg_type in ["服饰", "我的装备", "查服饰", "查装备"]:
        t = user
        if len(args) > 1:
            found = db_find_user_by_name(args[1])
            if found: t = found
            else: send_reply(f"❌ 查无此人：{args[1]}信息"); return
        e = t["equip"]
        def show(k):
            # 只显示可见的装备位置（is_hidden=1）
            if not Terms.is_visible('slot_'+k):
                return None
            return f"{Terms.get('slot_'+k)}：{e.get(k) or '无'}"
        # 收集可见的装备行
        slot_groups = [
            ['hair', 'top', 'bottom', 'head', 'neck'],
            ['inner1', 'inner2'],
            ['acc1', 'acc2', 'acc3', 'acc4']
        ]
        lines = []
        for group in slot_groups:
            group_lines = []
            for slot in group:
                slot_line = show(slot)
                if slot_line:
                    group_lines.append(slot_line)
            if group_lines:
                lines.extend(group_lines)
                lines.append("-" * 16)
        if lines and lines[-1] == "-" * 16:
            lines.pop()
        send_reply(f"\n🕴️ {t['name']} 的着装\n" + "\n".join(lines)); return

    if msg_type in ["属性", "我的属性", "查属性"]:
        t = user
        if len(args) > 1: t = db_find_user_by_name(args[1])
        if not t: send_reply("❌ 查无此人。"); return
        # 只显示可见的属性（is_hidden=1）
        visible_stats = [k for k in KEY_MAP["stats"] if Terms.is_visible(k)]
        stats_str = "\n".join([f"{Terms.get(k)}：{get_stats(t, Terms.get(k))}" for k in visible_stats])
        send_reply(f"〖{t['name']} 的属性〗\n{stats_str}\n----------------\n总数值：{get_stats(t)}\n{NAME_REP}：{get_total_reputation(t)}\n{NAME_YU}：{t['currency']['yuCoin']}"); return

    if msg_type in ["赠送", "送道具"] and len(args) >= 2:
        target = None; item_part = ""
        for a in args[1:]:
            found = db_find_user_by_name(a)
            if found: target = found
            else: item_part = a
        if not target: send_reply("❌ 失败：请指定对象 (@姓名)。"); return
        parts = item_part.split("*"); what = parts[0]; cnt = int(parts[1]) if len(parts)>1 else 1
        if cnt <= 0: send_reply("❌ 失败：数量必须大于0。"); return
        if msg_type == "赠送":
            if what == NAME_YU:
                if user["currency"]["yuCoin"] < cnt: send_reply(f"❌ 转账失败：余额不足，你只有 {user['currency']['yuCoin']} {NAME_YU}。"); return
                user["currency"]["yuCoin"] -= cnt; target["currency"]["yuCoin"] += cnt
            elif what == NAME_REP:
                if user["currency"]["reputation"] < cnt: send_reply(f"❌ 转让失败：{NAME_REP}不足，你只有 {user['currency']['reputation']} {NAME_REP}。"); return
                user["currency"]["reputation"] -= cnt; target["currency"]["reputation"] += cnt
            else: send_reply(f"❌ 格式错误：赠送仅支持货币。物品请用【送道具】指令。"); return
        else:
            if user.get("bag", {}).get(what, 0) < cnt: send_reply(f"❌ 赠送失败：你的背包里没有足够的【{what}】。"); return
            # 🟢 转移道具实例所有权
            for _ in range(cnt):
                # 从赠送者获取一个该道具的实例
                rows = DB.query(
                    "SELECT instance_id FROM item_instances WHERE item_name = ? AND user_id = ? AND currency_given = 0 LIMIT 1",
                    (what, str(sender_id))
                )
                if rows:
                    instance_id = rows[0][0]
                    # 转移所有权给接收者
                    DB.execute(
                        "UPDATE item_instances SET user_id = ? WHERE instance_id = ?",
                        (str(target['id']), instance_id)
                    )
                else:
                    # 如果没有未使用的实例，转移一个已使用的实例（这种情况不应该发生，但为了健壮性）
                    rows = DB.query(
                        "SELECT instance_id FROM item_instances WHERE item_name = ? AND user_id = ? LIMIT 1",
                        (what, str(sender_id))
                    )
                    if rows:
                        instance_id = rows[0][0]
                        DB.execute(
                            "UPDATE item_instances SET user_id = ? WHERE instance_id = ?",
                            (str(target['id']), instance_id)
                        )

            user["bag"][what] -= cnt; target["bag"][what] = target["bag"].get(what, 0) + cnt
            if user["bag"][what] <= 0: del user["bag"][what]
        db_save_user(user); db_save_user(target); send_reply(f"🎁【赠送】你将 {what} * {cnt} 送给了 {target['name']}。"); return
    
    # --- 7.2 排行榜逻辑 ---
    if msg_type in ["排行榜", "榜单"]:
        key = args[1] if len(args)>1 else "总数值"
        all_users = db_get_all_users()
        
        if key == NAME_YU: 
            all_users.sort(key=lambda u: u["currency"]["yuCoin"], reverse=True)
            val_fn = lambda u: u["currency"]["yuCoin"]
        elif key == NAME_REP: 
            all_users.sort(key=lambda u: get_total_reputation(u), reverse=True)
            val_fn = lambda u: get_total_reputation(u)
        elif get_stat_key(key): 
            all_users.sort(key=lambda u: get_stats(u, key), reverse=True)
            val_fn = lambda u: get_stats(u, key)
        else: 
            all_users.sort(key=lambda u: get_stats(u), reverse=True)
            val_fn = lambda u: get_stats(u); key = "总数值"
        
        lines = [f"第{i+1}: {u['name']} ({val_fn(u)})" for i,u in enumerate(all_users[:10])]
        send_reply(f"🏆 [{key}] 排行榜\n" + "\n".join(lines))
        return

    # --- 7.3 家族货币榜 ---
    if msg_type == FAMILY_RANK:
        fams = {}
        all_users = db_get_all_users()
        KEY_GROUP = Terms.get("profile_group", "隶属")
        
        for u in all_users:
            fname = u["profile"].get(KEY_GROUP)
            if fname and fname != "无":
                fams[fname] = fams.get(fname, 0) + get_total_reputation(u)
        
        if not fams:
            send_reply(f"🏰 家族{NAME_REP}榜\n暂无数据"); return

        sorted_fams = sorted(fams.items(), key=lambda x: x[1], reverse=True)
        lines = [f"第{i+1}: {name} ({score})" for i, (name, score) in enumerate(sorted_fams)]
        send_reply(f"🏰 家族{NAME_REP}榜\n" + "\n".join(lines))
        return

    # --- 8. 个人信息查询 (OpenID/UID) ---
    if msg_type.lower() in ["openid", "uid"]:
        uid_str = f"\nUID: {user['uid']}" if user else ""
        send_reply(f"🆔 你的OpenID：\n{sender_id}{uid_str}")
        return