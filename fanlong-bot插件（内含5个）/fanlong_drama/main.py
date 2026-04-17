import sys
import os
import json
import re
import time
from datetime import datetime
import OlivOS
import requests # 必须添加这个库

# ================= 🔗 1. 连接核心库 =================
current_dir = os.path.dirname(os.path.abspath(__file__))
core_path = os.path.join(current_dir, '..', 'fanlong_core')
if core_path not in sys.path:
    sys.path.append(core_path)

try:
    from lib.db import DB
    from lib.utils import get_rpg_name
    from lib.config import GlobalConfig
    from lib.terms import Terms        # 🔥
    from lib.settings import Settings  # 🔥
except ImportError as e:
    print(f"[繁笼戏录] 核心库导入失败: {e}")

# ================= ⚙️ 动态配置 =================

# 皮下后缀判定
OOC_SUFFIXES = ["/b", "b", "bot", "/bot", "/"]

# ================= 📂 路径与数据 =================

BASE_DIR = os.path.dirname(__file__)
DATA_DIR = os.path.join(BASE_DIR, 'data')
if not os.path.exists(DATA_DIR): os.makedirs(DATA_DIR)

STATS_PATH = os.path.join(DATA_DIR, 'drama_stats.json')

STATS_DATA = {
    "last_date": "",
    "groups": {}
}

MSG_CACHE = {}
OOC_STREAK = {}
WAITING_FORWARD = {}

def load_stats():
    global STATS_DATA
    try:
        if os.path.exists(STATS_PATH):
            with open(STATS_PATH, 'r', encoding='utf-8') as f:
                loaded_data = json.load(f)
                # 确保 groups 键始终存在
                if "groups" not in loaded_data:
                    loaded_data["groups"] = {}
                STATS_DATA = loaded_data
    except: save_stats()

def save_stats():
    with open(STATS_PATH, 'w', encoding='utf-8') as f:
        json.dump(STATS_DATA, f, ensure_ascii=False, indent=2)

# ================= 🛠️ 辅助逻辑 =================
def db_get_active_groups():
    """从数据库读取开启了戏录的群列表"""
    try:
        rows = DB.query("SELECT value FROM system_vars WHERE key='drama_active_groups'")
        if rows:
            return json.loads(rows[0][0]) # 返回 list
    except: pass
    return []

def db_save_active_groups(group_list):
    """保存开启列表到数据库"""
    try:
        val = json.dumps(list(set(group_list))) # 去重并转JSON
        DB.execute("INSERT OR REPLACE INTO system_vars (key, value) VALUES (?, ?)", ('drama_active_groups', val))
    except Exception as e:
        print(f"[戏录配置] 保存失败: {e}")

def get_display_name(user_id, default_name=None):
    """获取显示名 (优先RPG名)"""
    rpg_name = get_rpg_name(user_id)
    if rpg_name: return rpg_name
    if default_name: return default_name
    return str(user_id)

def get_real_name_from_db(user_id, fallback_name):
    """🔥 强力修正：直接查库获取最新名字 (修复改名不同步问题)"""
    try:
        # 直接查 users 表
        rows = DB.query("SELECT name FROM users WHERE id = ?", (str(user_id),))
        if rows:
            return rows[0][0] # 返回最新名字
    except: pass
    return fallback_name # 查不到就用缓存的旧名字

def check_daily_reset():
    today = datetime.now().strftime("%Y-%m-%d")
    last_date = STATS_DATA.get("last_date", "")

    # 如果日期变了 (跨天)
    if last_date != today:
        print(f"[繁笼戏录] 检测到跨天: {last_date} -> {today}，正在归档历史战绩...")
        
        # --- 1. 执行归档 (将昨天的战绩写入 SQL) ---
        if last_date: # 只有当存在旧日期时才归档
            try:
                for gid, group_data in STATS_DATA.get("groups", {}).items():
                    if "users" in group_data:
                        for uid, u_data in group_data["users"].items():
                            # 只记录有数据的用户
                            w = u_data.get("today_words", 0)
                            l = u_data.get("today_lines", 0)
                            if w > 0 or l > 0:
                                # 写入数据库
                                sql = "INSERT INTO history_stats (date, user_id, group_id, word_count, line_count) VALUES (?, ?, ?, ?, ?)"
                                DB.execute(sql, (last_date, str(uid), str(gid), w, l))
                print("[繁笼戏录] 历史战绩归档完成。")
            except Exception as e:
                print(f"[繁笼戏录] 归档失败: {e}")

        # --- 2. 重置数据 ---
        STATS_DATA["last_date"] = today
        for gid in STATS_DATA["groups"]:
            group_data = STATS_DATA["groups"][gid]
            if "users" in group_data:
                for uid in group_data["users"]:
                    group_data["users"][uid]["today_words"] = 0
                    group_data["users"][uid]["today_lines"] = 0
        
        # --- 3. 保存重置后的 JSON ---
        save_stats()
        
        # 清理缓存
        MSG_CACHE.clear() # 🟢 跨天时清空旧的消息缓存，释放内存
        OOC_STREAK.clear()

def clean_text(content):
    text = re.sub(r'\[CQ:.*?\]', '', content)
    return text.strip()

def is_ooc(text):
    t = text.lower()
    for s in OOC_SUFFIXES:
        if t.endswith(s): return True
    return False

def extract_participants(content):
    names = set()
    matches = re.findall(r'^([^：:\n\r]+)[：:]', content, re.MULTILINE)
    for m in matches: names.add(m.strip())
    return list(names)

# ================= 💾 数据库操作 =================

def db_save_archive(title, date_str, content, participants, recorder, note, group_id):
    p_str = ",".join(participants) if isinstance(participants, list) else str(participants)
    sql = '''
        INSERT INTO drama_archives (title, date_str, content, participants, recorder, note, group_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    '''
    new_id = DB.execute(sql, (title, date_str, content, p_str, recorder, note, str(group_id)))
    return new_id

def db_get_archive(aid):
    sql = "SELECT * FROM drama_archives WHERE id = ?"
    res = DB.query(sql, (aid,))
    return res[0] if res else None

def db_soft_delete(aid, operator):
    sql = "UPDATE drama_archives SET is_deleted = 1 WHERE id = ?"
    DB.execute(sql, (aid,))

# ================= 🚀 标准结构 =================

class Event(object):
    def init(plugin_event, Proc):
        Terms.reload()
        Settings.reload()
        load_stats()
        
        # 🟢 新增：从数据库恢复开启的群
        active_list = db_get_active_groups()
        restored_count = 0
        for gid in active_list:
            gid_str = str(gid)
            # 如果 json 里没这个群（比如文件被删了），就重新初始化它
            if gid_str not in STATS_DATA["groups"]:
                STATS_DATA["groups"][gid_str] = {"users": {}}
                restored_count += 1
        
        if restored_count > 0:
            save_stats() # 同步回 json 文件
            print(f"[繁笼戏录] 已从数据库恢复 {restored_count} 个群的开启状态")
            
        print("[繁笼戏录] 插件已加载 (数据库配置版)")

    def private_message(plugin_event, Proc):
        process_cmd(plugin_event, Proc, is_private=True)

    def group_message(plugin_event, Proc):
        process_cmd(plugin_event, Proc, is_private=False)

    def group_message_recall(plugin_event, Proc):
        try:
            msg_id = plugin_event.data.message_id
            if msg_id in MSG_CACHE:
                info = MSG_CACHE[msg_id]
                uid = str(info['uid'])
                gid = str(info.get('group_id', '0'))
                count = info['count']
                
                if not info['is_ooc'] and gid in STATS_DATA["groups"]:
                    users_map = STATS_DATA["groups"][gid]["users"]
                    if uid in users_map:
                        u_data = users_map[uid]
                        u_data["today_words"] = max(0, u_data["today_words"] - count)
                        u_data["today_lines"] = max(0, u_data["today_lines"] - 1)
                        u_data["total_words"] = max(0, u_data["total_words"] - count)
                        u_data["total_lines"] = max(0, u_data["total_lines"] - 1)
                        save_stats()
                del MSG_CACHE[msg_id]
        except: pass

    def save(plugin_event, Proc): save_stats()
    def menu(plugin_event, Proc): pass
    def poke(plugin_event, Proc): pass

# ================= 核心业务 =================

def process_cmd(plugin_event, Proc, is_private=False):
    try:
        msg = plugin_event.data.message.strip()
        sender_id = str(plugin_event.data.user_id)
        group_id = 0
        if not is_private and hasattr(plugin_event.data, 'group_id'):
            group_id = int(plugin_event.data.group_id)
        
        base_nick = plugin_event.data.sender.get('nickname', '未知')
        if hasattr(plugin_event.data.sender, 'card') and plugin_event.data.sender['card']:
            base_nick = plugin_event.data.sender['card']
        
        sender_name = get_display_name(sender_id, base_nick)
    except: return

    def send_reply(text):
        if is_private: plugin_event.reply(text)
        else: plugin_event.reply(f"[CQ:at,qq={sender_id}]\n{text}")

    # 🔥 多指令支持 helper
    def get_cmds(key, default):
        val = Terms.get(key, default)
        return tuple(val.split("|")) if "|" in val else (val,)

    CMD_SAVE = get_cmds("cmd_drama_save", "存戏")
    CMD_LIST = get_cmds("cmd_drama_list", "戏录列表")
    CMD_QUERY = get_cmds("cmd_drama_query", "查询戏录")
    CMD_RANK = get_cmds("cmd_drama_rank", "戏录榜")
    CMD_MY = get_cmds("cmd_drama_my", "我的戏录")

    def match_prefix(text, cmd_tuple):
        for prefix in cmd_tuple:
            if text.startswith(prefix):
                return prefix
        return None

    # 管理员重载
    if sender_id in GlobalConfig and msg in ["重载商品", "重载配置"]:
        Terms.reload()
        Settings.reload()
        return

    # --- 优先处理：等待转发消息 ---
    if sender_id in WAITING_FORWARD:
        id_match = re.search(r'id=([A-Za-z0-9_\-]+)', msg)
        if not id_match:
            id_match = re.search(r'res[Ii]d=([A-Za-z0-9_\-]+)', msg)

        if id_match or "[OP:forward" in msg:
            res_id = id_match.group(1) if id_match else None
            info = WAITING_FORWARD.pop(sender_id)
            
            if res_id:
                send_reply("⏳ 正在尝试解析合并转发...")
                try:
                    # ================= 🟢 新增：参考 AmourGame 的 API 调用逻辑 =================
                    bot_port = plugin_event.bot_info.post_info.port
                    bot_token = plugin_event.bot_info.post_info.access_token
                    
                    url = f"http://127.0.0.1:{bot_port}/get_forward_msg"
                    headers = {
                        "Authorization": f"Bearer {bot_token}",
                        "Content-Type": "application/json"
                    }
                    payload = json.dumps({"message_id": res_id})
                    
                    # 发送请求
                    response = requests.post(url, headers=headers, data=payload, timeout=10)
                    response_data = response.json()
                    
                    # 检查返回结果
                    if response.status_code != 200 or response_data.get("retcode") != 0:
                        raise Exception(f"API请求失败: {response_data.get('message', '未知错误')}")
                        
                    # 提取消息列表
                    msg_list = response_data.get("data", {}).get("messages", [])
                    if not msg_list:
                        raise Exception("转发内容为空")

                    final_text = []
                    participants = set()

                    # 遍历解析每一条消息
                    for msg in msg_list:
                        # 1. 提取发送者名字
                        sender_node = msg.get('sender', {})
                        node_name = sender_node.get('card') or sender_node.get('nickname') or "未知"
                        participants.add(node_name)

                        # 2. 提取文本内容 (参考参考代码的解析方式)
                        content_list = msg.get("content", [])
                        node_msg = ""
                        
                        # 如果 content 是列表（OneBot v11 标准）
                        if isinstance(content_list, list):
                            for item in content_list:
                                if item.get("type") == "text":
                                    node_msg += item.get("data", {}).get("text", "")
                        # 如果 content 是字符串（部分旧版本）
                        elif isinstance(content_list, str):
                            node_msg = content_list
                            
                        if node_msg:
                            final_text.append(f"{node_name}：\n{node_msg}\n")

                    # ================= 🟢 解析结束，恢复原有存库逻辑 =================

                    full_content = "\n".join(final_text)
                    
                    # 构建参与者列表
                    p_list = []
                    if info.get("note"): 
                        p_list.append(info["note"])
                    else: 
                        p_list = list(participants)
                    
                    note_str = info.get('note', '')
                    
                    # 存入数据库
                    new_id = db_save_archive(info['title'], info['date'], full_content, p_list, sender_name, note_str, group_id)
                    send_reply(f"✅ 自动解析成功！\n编号：{new_id}\n标题：{info['title']}")
                    return

                except Exception as e:
                    print(f"[自动解析失败] {e}")
                    # 失败回落
                    WAITING_FORWARD[sender_id] = info
                    send_reply(f"⚠️ 自动提取失败: {e}\n请尝试双击打开记录，全选复制并直接发送。")
                    return

        elif len(msg) > 10:
             info = WAITING_FORWARD.pop(sender_id)
             p_list = extract_participants(msg)
             note_str = info.get('note', '')
             new_id = db_save_archive(info['title'], info['date'], msg, p_list, sender_name, note_str, group_id)
             send_reply(f"✅ 手动录入成功！\n编号：{new_id}\n标题：{info['title']}\n备注：{note_str}")
             return

        elif msg == "取消":
            del WAITING_FORWARD[sender_id]
            send_reply("已取消录入。")
            return
        
        # 兼容旧逻辑
        if match_prefix(msg, CMD_SAVE): 
            if sender_id in WAITING_FORWARD: del WAITING_FORWARD[sender_id]

    # --- 1. 管理员指令 ---
    if sender_id in GlobalConfig:
        
        if msg == "设置戏录群":
            if is_private: send_reply("❌ 请在目标群内发送。")
            else:
                gid_str = str(group_id)
                if gid_str not in STATS_DATA["groups"]:
                    STATS_DATA["groups"][gid_str] = {"users": {}}
                    save_stats() # 保存 JSON (今日数据)
                    
                    # 🟢 同步写入数据库 (配置数据)
                    current_list = db_get_active_groups()
                    if group_id not in current_list:
                        current_list.append(group_id)
                        db_save_active_groups(current_list)
                        
                    send_reply(f"✅ 已开启本群 ({group_id}) 的戏录统计 (配置已入库)。")
                else:
                    send_reply("⚠️ 本群已在统计列表中。")
            return

        if msg == "移除戏录群":
            gid_str = str(group_id)
            if gid_str in STATS_DATA["groups"]:
                del STATS_DATA["groups"][gid_str]
                save_stats() # 保存 JSON
                
                # 🟢 同步从数据库移除
                current_list = db_get_active_groups()
                # 兼容 int 和 str 类型的移除
                if group_id in current_list: current_list.remove(group_id)
                if gid_str in current_list: current_list.remove(gid_str)
                db_save_active_groups(current_list)
                
                send_reply(f"🗑️ 已移除本群统计。")
            return

        if msg.startswith("删除戏录"):
            args = msg.split()
            if len(args) < 2:
                send_reply("❌ 请输入编号。")
                return
            
            aid = args[1]
            archive = db_get_archive(aid)
            if archive:
                if archive[8] == 1:
                    send_reply("⚠️ 该戏录已经被删除了。")
                    return
                db_soft_delete(aid, sender_name)
                send_reply(f"🗑️ 已删除戏录 [{aid}]：{archive[1]}\n📋 数据已移入回收站。")
            else:
                send_reply(f"⚠️ 未找到编号为 {aid} 的戏录。")
            return
        
        if msg == "废弃戏录列表" or msg == "回收站列表":
            sql = "SELECT id, title, recorder FROM drama_archives WHERE is_deleted = 1 ORDER BY id DESC LIMIT 5"
            rows = DB.query(sql)
            if not rows:
                send_reply("🚮 回收站是空的。")
                return
            lines = ["🗑️ 最近删除的戏录", "=================="]
            for row in rows:
                lines.append(f"[{row[0]}] {row[1]} (录入: {row[2]})")
            send_reply("\n".join(lines))
            return
        
        matched_save = match_prefix(msg, CMD_SAVE)
        if matched_save:
            raw = msg.replace(matched_save, "", 1).strip()
            parts = raw.split("+", 3)
            if len(parts) < 2:
                send_reply(f"❌ 格式错误。\n{CMD_SAVE[0]} 日期+标题+备注[+内容]")
                return

            date_str = parts[0]
            title = parts[1]
            note_str = parts[2] if len(parts) > 2 else ""
            content = parts[3] if len(parts) > 3 else ""  

            if content:
                p_list = extract_participants(content)
                new_id = db_save_archive(title, date_str, content, p_list, sender_name, note_str, group_id)
                send_reply(f"✅ 文本归档成功！\n编号：{new_id}\n标题：{title}")
            else:
                WAITING_FORWARD[sender_id] = { "date": date_str, "title": title, "note": note_str }
                send_reply(f"📂 准备录入【{title}】\n请发送【合并转发消息】。")
            return

    # --- 2. 查戏录/查列表 (全员可用) ---
    
    if msg in CMD_LIST:
        sql = "SELECT id, date_str, title, note FROM drama_archives WHERE is_deleted = 0 ORDER BY id DESC LIMIT 20"
        rows = DB.query(sql)
        if not rows:
            send_reply("📭 当前暂无戏录存档。")
            return
        
        lines = ["📜 繁笼戏录档案", "=================="]
        for row in rows:
            note_part = f"-{row[3]}" if row[3] else ""
            line = f"[{row[0]}] {row[1]} - {row[2]}{note_part}"
            lines.append(line)
        send_reply("\n".join(lines) + f"\n==================\n发送【{CMD_QUERY[0]} 编号】查看详情")
        return

    matched_query = match_prefix(msg, CMD_QUERY)
    if matched_query:
        args = msg.split()
        if len(args) < 2: return
        aid = args[1]
        
        archive = db_get_archive(aid)
        if not archive:
            send_reply("❌ 未找到该编号。")
            return
        
        if archive[8] == 1:
            send_reply("⚠️ 该戏录已被放入回收站。")
            return

        note_display = archive[5] if archive[5] else archive[4]
        res = (f"📼 【戏录-{archive[0]}】{archive[1]}\n"
               f"📅 时间：{archive[2]}\n"
               f"📝 备注：{note_display}\n"
               f"================\n"
               f"{archive[3]}")
        send_reply(res)
        return

    # --- 3. 查榜单 (🔥 关键修改处) ---
    if msg == "今日戏录" or msg in CMD_MY:
        load_stats(); check_daily_reset()
        if is_private:
            results = []
            has_data = False
            for gid, g_data in STATS_DATA["groups"].items():
                if sender_id in g_data["users"]:
                    u = g_data["users"][sender_id]
                    if u['total_words'] > 0 or u['today_words'] > 0:
                        has_data = True
                        # 🔥 强制获取最新名字
                        real_name = get_real_name_from_db(sender_id, u['name'])
                        results.append(f"📁 群({gid}):\n   今日: {u['today_lines']}段/{u['today_words']}字 | 累计: {u['total_lines']}段/{u['total_words']}字")
            
            if not has_data:
                send_reply("📭 你在所有已登记群组中暂无戏录数据。")
            else:
                # 🔥 强制获取最新名字
                my_name = get_real_name_from_db(sender_id, sender_name)
                send_reply(f"📊 {my_name} 的全域数据\n" + "\n----------------\n".join(results))
            return
        else:
            gid_str = str(group_id)
            if gid_str not in STATS_DATA["groups"]: return
            u = STATS_DATA["groups"][gid_str]["users"].get(sender_id)
            if not u: send_reply("你在这个群还没有戏录数据。")
            else:
                # 🔥 强制获取最新名字
                real_name = get_real_name_from_db(sender_id, u['name'])
                send_reply(f"📊 {real_name} (本群)\n今日：{u['today_lines']}段 / {u['today_words']}字\n累计：{u['total_lines']}段 / {u['total_words']}字")
        return

    if msg in CMD_RANK:
        if is_private: return
        load_stats(); check_daily_reset()
        gid_str = str(group_id)
        if gid_str not in STATS_DATA["groups"]: return
        users_map = STATS_DATA["groups"][gid_str]["users"]
        sorted_list = sorted(users_map.items(), key=lambda x: x[1]['today_words'], reverse=True)
        lines = ["🏆 本群今日活跃榜 🏆", "=================="]
        c = 0
        for uid, u in sorted_list: # 注意：这里改成了遍历 items (uid, u)
            if u['today_words'] > 0:
                # 🔥 强制获取最新名字
                real_name = get_real_name_from_db(uid, u['name'])
                lines.append(f"{real_name}: {u['today_words']}字 ({u['today_lines']}段)")
                c += 1
            if c >= 10: break
        send_reply("\n".join(lines) if c > 0 else "今日暂无数据。")
        return

    # ================= 4. 实时统计 =================
    if is_private: return
    gid_str = str(group_id)
    if gid_str not in STATS_DATA["groups"]: return

    if msg.startswith(".") or msg.startswith("/"): return
    check_daily_reset()
    pure_text = clean_text(msg)
    
    if sender_id not in OOC_STREAK: OOC_STREAK[sender_id] = 0

    if is_ooc(msg):
        OOC_STREAK[sender_id] += 1
        LIMIT = Settings.get("drama_ooc_limit", 3)
        if OOC_STREAK[sender_id] == LIMIT:
            send_reply(f"⚠️ 你已连续下皮发言 {LIMIT} 条，请注意注水量。")
            OOC_STREAK[sender_id] = 0
        if getattr(plugin_event.data, 'message_id', None):
            MSG_CACHE[plugin_event.data.message_id] = {'uid': sender_id, 'group_id': gid_str, 'count': 0, 'is_ooc': True}
        return
    else:
        OOC_STREAK[sender_id] = 0

    MIN_LEN = Settings.get("drama_min_len", 2)
    if len(pure_text) < MIN_LEN:
        return

    users_map = STATS_DATA["groups"][gid_str]["users"]
    
    # 存入时依然存当前名字（作为缓存），但查询时我们会用 DB 覆盖它
    real_name = get_display_name(sender_id, sender_name)
    
    if sender_id not in users_map:
        users_map[sender_id] = {
            "name": real_name, "today_words": 0, "today_lines": 0, "total_words": 0, "total_lines": 0
        }
    
    # 更新一下缓存里的名字
    users_map[sender_id]["name"] = real_name
    
    wc = len(pure_text)
    users_map[sender_id]["today_words"] += wc
    users_map[sender_id]["today_lines"] += 1
    users_map[sender_id]["total_words"] += wc
    users_map[sender_id]["total_lines"] += 1
    save_stats()
    
    msg_id = getattr(plugin_event.data, 'message_id', None)
    if msg_id: MSG_CACHE[msg_id] = {'uid': sender_id, 'group_id': gid_str, 'count': wc, 'is_ooc': False}
    plugin_event.set_block(False)