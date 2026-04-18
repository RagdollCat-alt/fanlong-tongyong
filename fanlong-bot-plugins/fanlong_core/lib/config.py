# 文件：fanlong_core/lib/config.py
import os
import json
import sqlite3 # 引入 sqlite3 用于初始化读取

# 动态定位核心插件的根目录
LIB_DIR = os.path.dirname(os.path.abspath(__file__))
CORE_DIR = os.path.dirname(LIB_DIR)
DATA_DIR = os.path.join(CORE_DIR, 'data')
CONFIG_PATH = os.path.join(DATA_DIR, 'config.json')
DB_PATH = os.path.join(DATA_DIR, 'fanlong.db') # 假设你的数据库文件名是 data.db

if not os.path.exists(DATA_DIR): os.makedirs(DATA_DIR)

class ConfigManager:
    def __init__(self):
        self.admins = ["821605970"] # 默认管理员（保底）
        self.load()       # 1. 先加载本地 JSON 配置
        self.sync_db()    # 2. 再尝试从数据库合并管理员

    def load(self):
        if os.path.exists(CONFIG_PATH):
            try:
                with open(CONFIG_PATH, 'r', encoding='utf-8') as f:
                    data = json.load(f)
                    if "admins" in data: 
                        # 合并去重
                        current_set = set(self.admins)
                        file_set = set(data["admins"])
                        self.admins = list(current_set | file_set)
            except: pass
        else:
            self.save()

    def sync_db(self):
        """从数据库同步管理员列表到内存"""
        if not os.path.exists(DB_PATH):
            return # 数据库还没生成，跳过

        try:
            # 直接连接读取，避免循环引用 fanlong_core.lib.db
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            # 尝试查询 admins 表
            cursor.execute("SELECT user_id FROM admins")
            rows = cursor.fetchall()
            
            db_admins = [str(row[0]) for row in rows]
            
            # 将数据库里的管理员合并到内存中（去重）
            for uid in db_admins:
                if uid not in self.admins:
                    self.admins.append(uid)
            
            print(f"[CoreConfig] 已从数据库同步 {len(db_admins)} 名管理员。")
            conn.close()
        except Exception as e:
            # 表可能不存在，或者数据库损坏，忽略错误保证程序能启动
            print(f"[CoreConfig] DB Sync skipped: {e}")

    def save(self):
        try:
            with open(CONFIG_PATH, 'w', encoding='utf-8') as f:
                json.dump({"admins": self.admins}, f, ensure_ascii=False, indent=2)
        except Exception as e:
            print(f"[CoreConfig] Save failed: {e}")

    def __contains__(self, item):
        return str(item) in self.admins

    # 这里的 add/remove 仅作为内存/Config操作，具体数据库写入交给 main.py
    def add_admin(self, uid):
        if str(uid) not in self.admins:
            self.admins.append(str(uid))
            self.save()
            return True
        return False

    def remove_admin(self, uid):
        if str(uid) in self.admins:
            self.admins.remove(str(uid))
            self.save()
            return True
        return False

GlobalConfig = ConfigManager()