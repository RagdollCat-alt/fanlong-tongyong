import sys, os, json, time, threading, re, requests, sqlite3, OlivOS

# ================= 🔗 路径加载 =================
current_dir = os.path.dirname(os.path.abspath(__file__))
core_path = os.path.join(current_dir, '..', 'fanlong_core')
if core_path not in sys.path: sys.path.append(core_path)

try:
    from lib.db import DB
    from lib.config import GlobalConfig
    from lib.terms import Terms
except: print("[繁笼名片] 核心库加载失败")

# ================= ⚙️ 配置与接口 =================
# 基于您的 JSON 配置文件
API_PORT = "11111"
API_TOKEN = "345312"
CONFIG = {"sync_groups": [], "card_template": ""}

# 默认名片模板
DEFAULT_CARD_TEMPLATE = "【{职位}】{姓名}-{家世}-{年龄}-{属性}"

def load_config():
    global CONFIG
    # 从数据库 system_vars 表加载已保存的群列表
    rows = DB.query("SELECT value FROM system_vars WHERE key='card_sync_groups'")
    if rows: CONFIG["sync_groups"] = json.loads(rows[0][0])

    # 加载名片模板
    rows = DB.query("SELECT value FROM system_vars WHERE key='card_template'")
    if rows:
        CONFIG["card_template"] = rows[0][0]
    else:
        # 如果数据库中没有，使用默认模板并保存
        CONFIG["card_template"] = DEFAULT_CARD_TEMPLATE
        save_card_template()

def save_config():
    # 持久化保存群列表到数据库
    val = json.dumps(CONFIG["sync_groups"])
    DB.execute("INSERT OR REPLACE INTO system_vars (key, value) VALUES (?, ?)", ('card_sync_groups', val))

def save_card_template():
    # 保存名片模板到数据库
    DB.execute("INSERT OR REPLACE INTO system_vars (key, value) VALUES (?, ?)",
              ('card_template', CONFIG["card_template"]))

# ================= 🔍 核心逻辑 =================

def get_card_from_db(user_id):
    """查询数据库并生成格式化名片"""
    rows = DB.query("SELECT name, profile FROM users WHERE id = ?", (str(user_id),))
    if not rows: return None
    name, p_raw = rows[0][0], rows[0][1]
    p = json.loads(p_raw) if p_raw else {}

    # 动态从 game_terms 获取档案字段
    field_mapping = {"姓名": name}

    # 获取所有 profile_ 开头的字段
    term_rows = DB.query("SELECT key, text FROM game_terms WHERE key LIKE 'profile_%'")
    if term_rows:
        for key, text in term_rows:
            if text:  # 确保有中文名
                # 获取值，优先使用当前 text 作为键名，也尝试旧键名做兼容
                value = p.get(text, '')
                # 如果用当前 text 找不到，尝试用 key 直接找
                if not value:
                    # 常见兼容映射
                    if key == "profile_job":
                        value = p.get("职位") or p.get("官职") or ''
                        # 去除品级前缀
                        value = re.sub(r'^[正从次]?[一二三四五六七八九]品', '', value).strip()
                    elif key == "profile_name":
                        value = name
                    else:
                        value = p.get(key.replace('profile_', ''), '')
                field_mapping[text] = value

    # 添加一些常用字段的兼容映射（非 profile_ 开头的）
    for field_name in ["官职", "修为", "简介", "品阶", "属性"]:
        field_mapping[field_name] = p.get(field_name, '')

    # 使用自定义模板生成名片
    template = CONFIG.get("card_template", DEFAULT_CARD_TEMPLATE)

    try:
        res = template.format(**field_mapping)
        # 过滤掉空值，连续的横杠和空的括号
        res = re.sub(r'-+', '-', res)  # 多个横杠合并为一个
        res = re.sub(r'【】-', '【', res)  # 清理空的括号前缀
        res = re.sub(r'-【', '【', res)  # 清理横杠后跟括号
        res = res.strip('-')
        res = res.strip()
    except Exception as e:
        print(f"[繁笼名片] 模板格式化失败: {e}")
        res = DEFAULT_CARD_TEMPLATE.format(**field_mapping)

    return res if len(res.replace("【】", "").replace("--", "").strip()) > 3 else None

def http_force_sync(target_qq_id):
    """HTTP 协议层强制同步"""
    time.sleep(8.0) # 避开冲突波峰
    card_str = get_card_from_db(target_qq_id)
    if not card_str: return

    url = f"http://127.0.0.1:{API_PORT}/set_group_card?access_token={API_TOKEN}"
    # 遍历所有已配置的同步群执行 HTTP 请求
    for gid in CONFIG.get("sync_groups", []):
        try:
            requests.post(url, json={"group_id": int(gid), "user_id": int(target_qq_id), "card": card_str}, timeout=5)
        except: pass

# ================= 🚀 事件分发 =================

def process(plugin_event, Proc):
    msg = plugin_event.data.message.strip()
    sender_id = str(plugin_event.data.user_id)
    current_gid = int(plugin_event.data.group_id) if hasattr(plugin_event.data, 'group_id') else 0

    # 权限校验：骰主及管理员均可操作
    if sender_id in GlobalConfig:
        # 1. 同步群管理口令
        if msg.startswith("添加同步群"):
            args = msg.split()
            gid = int(args[1]) if len(args) >= 2 and args[1].isdigit() else current_gid
            if gid > 0 and gid not in CONFIG["sync_groups"]:
                CONFIG["sync_groups"].append(gid)
                save_config(); plugin_event.reply(f"✅ 已添加同步群：{gid}")
            return
        
        if msg.startswith("删除同步群"):
            args = msg.split()
            gid = int(args[1]) if len(args) >= 2 and args[1].isdigit() else current_gid
            if gid in CONFIG["sync_groups"]:
                CONFIG["sync_groups"].remove(gid)
                save_config(); plugin_event.reply(f"🗑️ 已移除同步群：{gid}")
            return

        if msg == "同步群列表":
            plugin_event.reply(f"📜 当前同步列表：{CONFIG['sync_groups']}")
            return

        # 名片模板管理
        if msg == "查看名片模板":
            plugin_event.reply(f"📋 当前名片模板：\n{CONFIG.get('card_template', DEFAULT_CARD_TEMPLATE)}\n\n💡 使用方法：{姓名}、{职位}、{家世}、{年龄}、{属性} 等")
            return

        if msg.startswith("设置名片模板"):
            template = msg[len("设置名片模板"):].strip()
            if not template:
                plugin_event.reply("❌ 请输入模板内容\n示例：设置名片模板 {姓名}|{家世}|{年龄}")
                return

            # 验证模板是否包含至少一个有效字段
            valid_fields = ["姓名", "职位", "家世", "年龄", "属性", "性别", "性格", "外貌", "身高", "背景", "喜恶", "禁忌", "户籍", "薪资", "隶属", "备注"]
            has_field = any(f"{{{field}}}" in template for field in valid_fields)

            if not has_field:
                plugin_event.reply("❌ 模板必须包含至少一个档案字段\n可用字段：姓名、职位、家世、年龄、属性、性别、性格等\n示例：{姓名}|{家世}|{年龄}")
                return

            CONFIG["card_template"] = template
            save_card_template()
            plugin_event.reply(f"✅ 名片模板已更新：\n{template}")
            return

        if msg == "重置名片模板":
            CONFIG["card_template"] = DEFAULT_CARD_TEMPLATE
            save_card_template()
            plugin_event.reply(f"✅ 名片模板已重置为默认：\n{DEFAULT_CARD_TEMPLATE}")
            return

        # 2. 触发改名逻辑
        trigger_words = ["录入档案", "创建", "注册", "录入", "升官", "贬职", "刷新名片"]
        if any(msg.startswith(w) for w in trigger_words):
            final_target = None
            target_match = re.search(r"(?:id|qq|id=|=|qq=)(\d+)", msg)
            if target_match:
                final_target = target_match.group(1)
            elif "@" in msg:
                name_part = re.search(r"@([^\s]+)", msg).group(1)
                rows = DB.query("SELECT id FROM users WHERE name = ?", (name_part,))
                if rows: final_target = str(rows[0][0])
            
            final_target = final_target or sender_id
            threading.Thread(target=http_force_sync, args=(final_target,)).start()

class Event(object):
    def init(plugin_event, Proc):
        Terms.reload(); load_config()
    def group_message(plugin_event, Proc): process(plugin_event, Proc)
    def private_message(plugin_event, Proc): process(plugin_event, Proc)
    def save(plugin_event, Proc): save_config()