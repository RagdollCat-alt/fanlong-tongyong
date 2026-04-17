import sys
import os
import OlivOS

# ================= 🔗 连接核心库 =================
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.append(current_dir)

try:
    from lib.db import DB
    from lib.config import GlobalConfig, load_admins
    from lib.terms import Terms
    from lib.settings import Settings
except ImportError as e:
    print(f"[繁笼核心] 库导入失败: {e}")

# ================= 📖 帮助菜单配置 (最终完整版) =================

# 玩家菜单 (1-4) - 通用版
USER_HELP = {
    "1": {
        "keys": ["角色", "档案", "rpg", "1"],
        "title": "🎭 角色与养成 (RPG)",
        "desc": "核心档案、物品与经济系统",
        "cmds": [
            "📋 [档案与查询]",
            "创建 [姓名]          - 注册新档案",
            "档案 / 查档案 [姓名] - 查看完整人设",
            "属性 / 查属性 [姓名] - 查看属性/数值/余额",
            "服饰 / 查服饰 [姓名] - 查看当前穿戴装备",
            "背包                 - 查看持有物品",
            "余额                 - 查看资产",
            "我的名片/查看名片 [姓名] - 生成图片信息",
            "----------------",
            "📅 [日常玩法]",
            "签到                 - 每日领取货币",
            "训练                 - 每日随机增加属性",
            "盲盒 / 抽盲盒        - 消耗货币抽取奖励",
            "----------------",
            "💰 [交易与物品]",
            "商店                 - 查看可购商品",
            "购买 [物品]*N        - 购买商品",
            "使用 [物品]*N        - 使用消耗品",
            "换上 [装备] [部位]   - 穿戴 (部位可选)",
            "卸下 [装备名]        - 脱下装备",
            "----------------",
            "🤝 [社交互动]",
            "赠送 @人 [货币]*N   - 个人转账",
            "送道具 @人 [物品]*N  - 转送物品",
            "排行榜 [类型]        - 查看总榜或(货币/属性等)分榜"
        ]
    },
    "2": {
        "keys": ["戏录", "剧情", "drama", "2"],
        "title": "📜 戏录与剧情",
        "desc": "剧情归档与活跃统计",
        "cmds": [
            "戏录列表 - 浏览最近已存档的剧情",
            "查询戏录 [编号] - 阅读详细存档内容",
            "今日戏录 - 需私聊机器人，查看全域累计数据",
            "戏录榜   - 查看本群今日活跃排行",
        ]
    },
    "3": {
        "keys": ["娱乐", "骰子", "dice", "3"],
        "title": "🎲 骰子与工具",
        "desc": "TRPG 风格骰子",
        "cmds": [
            ".r [表达式] [原因] - 基础掷骰 (如 .r d100)",
            ".rh [表达式] - 暗骰 (结果私聊)",
            ".r [属性名] - 属性检定 (如 .r 魅力/魅力)",
            "回复列表 - 查看所有关键词回复列表"
        ]
    }
}

# 管理员菜单 (4) - 通用版
ADMIN_HELP = {
    "keys": ["管理", "admin", "op", "4"],
    "title": "🛠️ 管理员操作手册",
    "desc": "仅限管理员使用的后台指令",
    "sections": {
        "⚙️ [核心管理]": [
            "重载配置           - 热更新配置/商品/规则",
            "添加管理员 [QQ]    - 授权临时管理员",
            "删除管理员 [QQ]    - 移除权限",
            "设置回复 [键] [值] - 添加自定义回复",
            "删除回复 [键]      - 删除自定义回复"
        ],
        "🎭 [RPG后台]": [
            "录入 @人 字段 内容 - 批量修改档案(支持姓名/属性)",
            "录入档案 @人       - (换行模式)批量录入多行数据",
            "增加/扣除 @人 [属性/物品]*N - 奖惩数值或道具",
            "加全员/减全员 [属性]*N      - 全服发放/扣除",
            "删除 @人           - 彻底删除用户数据(进回收站)",
            "发薪资             - 根据【薪资】字段发放货币"
        ],
        "📜 [戏录后台]": [
            "存戏 [日期]+[标题]+[对戏人] - 录入剧情(仅管理员)",
            "设置戏录群 / 移除戏录群 - 开启/关闭本群统计",
            "删除戏录 [编号]      - 软删除某条存档",
            "废弃戏录列表         - 查看回收站"
        ],
        "🆔 [名片后台]": [
            "添加同步群 [群号] - 添加名片自动同步群",
            "删除同步群 [群号] - 移除同步群",
            "同步群列表 - 查看当前同步群列表",
            "查看名片模板 - 查看当前名片格式",
            "设置名片模板 [格式] - 自定义名片格式",
            "重置名片模板 - 恢复默认名片格式"
        ]
    }
}

# ================= 🚀 标准结构 =================

class Event(object):
    def init(plugin_event, Proc):
        DB.init_tables()
        print("[通用RPG核心] 插件已加载 | 帮助系统V3.0 (通用版)")

    def private_message(plugin_event, Proc):
        process_cmd(plugin_event, Proc, is_private=True)

    def group_message(plugin_event, Proc):
        process_cmd(plugin_event, Proc, is_private=False)

    def save(plugin_event, Proc): pass
    def menu(plugin_event, Proc): pass
    def poke(plugin_event, Proc): pass

# ================= 核心业务逻辑 =================

def process_cmd(plugin_event, Proc, is_private=False):
    try:
        msg = plugin_event.data.message.strip()
        sender_id = str(plugin_event.data.user_id)
        if hasattr(plugin_event.data, 'sender_id'): sender_id = str(plugin_event.data.sender_id)
    except: return
    
    if msg.startswith("/"): msg = msg[1:].strip()
    args = msg.split()
    if not args: return
    
    cmd = args[0]
    
    def send_reply(text):
        content = str(text).strip()
        if is_private: plugin_event.reply(content)
        else: plugin_event.reply(f"[CQ:at,qq={sender_id}]\n{content}")

    # ================= 📖 帮助系统 V2 =================
    
    if cmd in ["繁笼帮助", "帮助", "菜单", "指令", "help"]:
        
        # 1. 检查是否有参数
        query = args[1].lower() if len(args) > 1 else ""

        # --- A. 玩家查询具体模块 (1-6) ---
        for key, data in USER_HELP.items():
            if query in data["keys"]:
                cmds_str = "\n".join([f"▫ {c}" for c in data["cmds"]])
                send_reply(f"{data['title']}\n{data['desc']}\n----------------\n{cmds_str}")
                return

        # --- B. 查询管理员手册 (4) ---
        if query in ADMIN_HELP["keys"]:
            # 🟢 [修改] 如果不是管理员，假装没找到，或者拒绝访问
            if sender_id not in GlobalConfig:
                send_reply("❌ 权限不足：该手册仅限管理员查阅。")
                return

            lines = [f"{ADMIN_HELP['title']}", "=================="]
            for section, cmds in ADMIN_HELP["sections"].items():
                lines.append(section)
                lines.extend([f"  • {c}" for c in cmds])
                lines.append("") 
            send_reply("\n".join(lines))
            return

        # --- C. 无参数：显示主菜单 ---
        if not query:
            menu_lines = ["📖 通用RPG系统·功能索引", "=================="]

            for key, data in USER_HELP.items():
                menu_lines.append(f"【{key}】 {data['title']}")

            menu_lines.append("------------------")

            # 🟢 [修改] 只有管理员才显示第 4 项
            if sender_id in GlobalConfig:
                menu_lines.append(f"【4】 {ADMIN_HELP['title']}")

            menu_lines.append("==================")
            menu_lines.append("💡 回复【帮助 序号】查看详情\n示例：帮助 1")

            send_reply("\n".join(menu_lines))
            return

        # --- D. 未找到 ---
        send_reply(f"❌ 未找到模块：{query}\n请回复【帮助】查看目录。")
        return

    # ... 在 process_cmd 函数内部 ...

    # ================= 🛡️ 核心管理指令 =================
    
    # 判定发送者是否在 GlobalConfig (此时 GlobalConfig 已经包含从数据库加载的名单)
    if sender_id in GlobalConfig:
        
        # --- 添加管理员 ---
        if cmd == "添加管理员" and len(args) > 1:
            target_id = args[1]
            
            # 检查是否已经是管理员
            if target_id not in GlobalConfig:
                # 1. 内存与JSON生效 (调用 config.py 的方法)
                GlobalConfig.add_admin(target_id)
                
                # 2. ✅ 写入数据库 (永久生效)
                try:
                    # 使用 REPLACE INTO 避免主键冲突，level 1 代表普通管理员
                    DB.execute("INSERT OR REPLACE INTO admins (user_id, level, added_by) VALUES (?, ?, ?)", 
                               (target_id, 1, sender_id))
                    send_reply(f"✅ 已将 {target_id} 添加为永久管理员。")
                except Exception as e:
                    send_reply(f"⚠️ 内存已添加，但数据库写入失败: {e}")
            else:
                send_reply("⚠️ 该用户已是管理员。")
            return
        
        # --- 删除管理员 ---
        if cmd == "删除管理员" and len(args) > 1:
            target_id = args[1]
            
            if target_id in GlobalConfig:
                # 1. 内存与JSON移除
                GlobalConfig.remove_admin(target_id)
                
                # 2. ✅ 数据库移除
                try:
                    DB.execute("DELETE FROM admins WHERE user_id = ?", (target_id,))
                    send_reply(f"🗑️ 已移除 {target_id} 的权限。")
                except Exception as e:
                    send_reply(f"⚠️ 内存已移除，但数据库同步失败: {e}")
            else:
                send_reply("⚠️ 该用户不是管理员。")
            return