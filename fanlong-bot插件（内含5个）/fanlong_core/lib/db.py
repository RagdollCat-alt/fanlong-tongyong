# 文件：fanlong_core/lib/db.py
import sqlite3
import threading
import os

# 定位到 fanlong_core/data/fanlong.db
LIB_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(os.path.dirname(LIB_DIR), 'data', 'fanlong.db')

class DatabaseManager:
    def __init__(self):
        # 1. 自动定位数据库文件路径（无论从哪个插件调用都能准确找到）
        # 获取当前 db.py 所在目录，并定位到 fanlong_core/data/fanlong.db
        current_file_dir = os.path.dirname(os.path.abspath(__file__))
        db_file_path = os.path.join(current_file_dir, '..', 'data', 'fanlong.db')
        
        # 确保数据文件夹存在，防止 sqlite3 连接失败
        os.makedirs(os.path.dirname(db_file_path), exist_ok=True)

        # 2. 建立连接
        # check_same_thread=False 允许在 OlivOS 多线程环境中使用
        # timeout=20 增加等待时长，缓解 database is locked 问题
        self.conn = sqlite3.connect(db_file_path, timeout=20, check_same_thread=False)
        self.cursor = self.conn.cursor()
        
        # 3. 线程锁
        self.lock = threading.Lock() 
        
        # 4. 初始化
        self.init_tables()
        print(f"[繁笼DB] 数据库已成功连接至: {db_file_path}")

    def init_tables(self):
        """初始化所有表结构"""
        with self.lock:
            # 1. 戏录表
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS drama_archives (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title TEXT,
                    date_str TEXT,
                    content TEXT,
                    participants TEXT,
                    note TEXT,
                    recorder TEXT,
                    group_id TEXT,
                    is_deleted INTEGER DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            self.conn.commit()

            # 5. 历史战绩表 (新增)
            # 记录每个人、每天、在每个群的战绩
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS history_stats (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    date TEXT,        -- 日期 (YYYY-MM-DD)
                    user_id TEXT,     -- QQ号
                    group_id TEXT,    -- 群号
                    word_count INTEGER, -- 今日字数
                    line_count INTEGER, -- 今日行数
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            # 加上索引，以后查“某人历史总榜”会飞快
            self.cursor.execute('CREATE INDEX IF NOT EXISTS idx_history_user ON history_stats (user_id)')
            self.cursor.execute('CREATE INDEX IF NOT EXISTS idx_history_date ON history_stats (date)')
            
            # ... (保留之前的 drama_archives, fund_holdings, family_logs, auction_history, families) ...

            # 6. RPG 用户基础表
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS users (
                    id TEXT PRIMARY KEY,
                    uid INTEGER UNIQUE,
                    name TEXT,
                    currency TEXT,  -- JSON: {"yuCoin": 100, "reputation": 50}
                    profile TEXT,   -- JSON: {"age": 18, ...}
                    limits TEXT,    -- JSON: {"lastSign": "...", ...}
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')

            # 7. RPG 属性表 (用于快速排行)
            # 8大属性独立字段
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS user_stats (
                    user_id TEXT PRIMARY KEY,
                    stat_face INTEGER DEFAULT 0,
                    stat_charm INTEGER DEFAULT 0,
                    stat_intel INTEGER DEFAULT 0,
                    stat_biz INTEGER DEFAULT 0,
                    stat_talk INTEGER DEFAULT 0,
                    stat_body INTEGER DEFAULT 0,
                    stat_art INTEGER DEFAULT 0,
                    stat_obed INTEGER DEFAULT 0
                )
            ''')

            # 8. RPG 背包表
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS user_bag (
                    user_id TEXT,
                    item_name TEXT,
                    count INTEGER,
                    PRIMARY KEY (user_id, item_name)
                )
            ''')

            # 9. RPG 装备表
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS user_equip (
                    user_id TEXT PRIMARY KEY,
                    hair TEXT, top TEXT, bottom TEXT,
                    head TEXT, neck TEXT,
                    inner1 TEXT, inner2 TEXT,
                    acc1 TEXT, acc2 TEXT, acc3 TEXT, acc4 TEXT
                )
            ''')
            
            # 10. 全局配置表 (存储 uidCounter 等)
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS system_vars (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
            ''')

            # 11. 自定义回复表
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS custom_replies (
                    key TEXT PRIMARY KEY,
                    value TEXT
                )
            ''')
            
            # ... (保留之前的 1-11 号表) ...

            # 12. 物品表 (New!)
            # 属性和效果结构复杂，依然用 JSON 存字段，但基础信息独立分列
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS items (
                    name TEXT PRIMARY KEY,
                    price INTEGER,
                    currency TEXT,  -- yuCoin 或 reputation
                    type TEXT,      -- equip 或 consumable
                    slot TEXT,      -- 装备位置
                    desc TEXT,      -- 描述
                    stats TEXT,     -- JSON: {"stat_face": 5, ...}
                    effect TEXT,    -- JSON: {"reputation": 10, ...}
                    is_selling INTEGER DEFAULT 1, -- 1上架 0下架
                    stock_qty INTEGER DEFAULT -1,   -- 全服库存，-1为无限制
                    max_hold INTEGER DEFAULT 0,      -- 个人最大持有量，0为不限制
                    purchase_limit INTEGER DEFAULT 0 -- 限购数量（与max_hold相同，保留兼容性）
                )
            ''')

            # 自动升级：如果表已存在但没有新字段，则添加
            try:
                self.cursor.execute("SELECT stock_qty FROM items LIMIT 1")
            except Exception:
                self.cursor.execute("ALTER TABLE items ADD COLUMN stock_qty INTEGER DEFAULT -1")
                self.cursor.execute("ALTER TABLE items ADD COLUMN max_hold INTEGER DEFAULT 0")
                self.cursor.execute("ALTER TABLE items ADD COLUMN purchase_limit INTEGER DEFAULT 0")
                print("[DB] items 表已升级，添加 stock_qty、max_hold、purchase_limit 字段")

            # 13. 管理员表 (用于网页登录或指令鉴权)
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS admins (
                    user_id TEXT PRIMARY KEY,
                    level INTEGER DEFAULT 1,  -- 1:普通管理 999:超级管理员
                    added_by TEXT,            -- 是谁提拔的他
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            
            # 顺手把你自己设为超级管理员 (防止新表空了没权限)
            # 请把 821605970 换成你的 QQ 号
            self.cursor.execute('''
                INSERT OR IGNORE INTO admins (user_id, level, added_by)
                VALUES (?, ?, ?)
            ''', ("821605970", 999, "SYSTEM"))

            # ... (保留之前的代码) ...

            # 14. 游戏术语配置表 (补充档案字段 + 显示控制字段)
            # is_hidden: 0=不展示(隐藏), 1=展示
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS game_terms (
                    key TEXT PRIMARY KEY,
                    text TEXT,
                    is_hidden INTEGER DEFAULT 1
                )
            ''')

            # 自动升级：如果表已存在但没有 is_hidden 列，则添加
            try:
                self.cursor.execute("SELECT is_hidden FROM game_terms LIMIT 1")
            except Exception:
                self.cursor.execute("ALTER TABLE game_terms ADD COLUMN is_hidden INTEGER DEFAULT 1")
                print("[DB] game_terms 表已升级，添加 is_hidden 字段")

            # 初始化默认术语 (检测是否已存在，不存在则插入)
            # 注意：我在这里增加了 profile_xxx 的字段
            check_sql = "SELECT count(*) FROM game_terms WHERE key='profile_age'"
            self.cursor.execute(check_sql)
            if self.cursor.fetchone()[0] == 0:
                defaults = [
                    # --- 档案名称 (默认全部展示: is_hidden=1) ---
                    ("profile_name", "姓名", 1),
                    ("profile_age", "年龄", 1),
                    ("profile_sex", "性别", 1), # 预留
                    ("profile_char", "性格", 1),
                    ("profile_look", "外貌", 1),
                    ("profile_height", "身高", 1),
                    ("profile_family", "家世", 1),
                    ("profile_job", "职位", 1),
                    ("profile_bg", "背景", 1),
                    ("profile_like", "喜恶", 1),
                    ("profile_taboo", "禁忌", 1),
                    ("profile_citizen", "户籍", 1),
                    ("profile_salary", "薪资", 1),
                    ("profile_group", "隶属", 1),
                    ("profile_note", "备注", 1),

                    # --- 货币名称 ---
                    ("term_yuCoin", "虞元", 1),
                    ("term_reputation", "名誉", 1),

                    # --- 属性名称 ---
                    ("stat_face", "颜值", 1),
                    ("stat_charm", "魅力", 1),
                    ("stat_intel", "智力", 1),
                    ("stat_biz", "商业", 1),
                    ("stat_talk", "口才", 1),
                    ("stat_body", "体能", 1),
                    ("stat_art", "才艺", 1),
                    ("stat_obed", "服从_威慑", 1),

                    # --- 装备位置 ---
                    ("slot_hair", "发型", 1),
                    ("slot_top", "上衣", 1),
                    ("slot_bottom", "下装", 1),
                    ("slot_head", "头饰", 1),
                    ("slot_neck", "颈饰", 1),
                    ("slot_inner1", "内饰1", 1),
                    ("slot_inner2", "内饰2", 1),
                    ("slot_acc1", "配饰1", 1),
                    ("slot_acc2", "配饰2", 1),
                    ("slot_acc3", "配饰3", 1),
                    ("slot_acc4", "配饰4", 1),


                ]
                self.cursor.executemany("INSERT OR IGNORE INTO game_terms (key, text, is_hidden) VALUES (?, ?, ?)", defaults)
                print("[DB] 已初始化游戏术语，共{}项".format(len(defaults)))

            # 🔥🔥🔥 关键补丁：查漏补缺（自动补充缺失的术语）🔥🔥🔥
            required_terms = {
                # --- 档案名称 ---
                "profile_name": ("姓名", 1),
                "profile_age": ("年龄", 1),
                "profile_sex": ("性别", 1),
                "profile_char": ("性格", 1),
                "profile_look": ("外貌", 1),
                "profile_height": ("身高", 1),
                "profile_family": ("家世", 1),
                "profile_job": ("职位", 1),
                "profile_bg": ("背景", 1),
                "profile_like": ("喜恶", 1),
                "profile_taboo": ("禁忌", 1),
                "profile_citizen": ("户籍", 1),
                "profile_salary": ("薪资", 1),
                "profile_group": ("隶属", 1),
                "profile_note": ("备注", 1),

                # --- 货币名称 ---
                "term_yuCoin": ("虞元", 1),
                "term_reputation": ("名誉", 1),

                # --- 属性名称 ---
                "stat_face": ("颜值", 1),
                "stat_charm": ("魅力", 1),
                "stat_intel": ("智力", 1),
                "stat_biz": ("商业", 1),
                "stat_talk": ("口才", 1),
                "stat_body": ("体能", 1),
                "stat_art": ("才艺", 1),
                "stat_obed": ("服从_威慑", 1),

                # --- 装备位置 ---
                "slot_hair": ("发型", 1),
                "slot_top": ("上衣", 1),
                "slot_bottom": ("下装", 1),
                "slot_head": ("头饰", 1),
                "slot_neck": ("颈饰", 1),
                "slot_inner1": ("内饰1", 1),
                "slot_inner2": ("内饰2", 1),
                "slot_acc1": ("配饰1", 1),
                "slot_acc2": ("配饰2", 1),
                "slot_acc3": ("配饰3", 1),
                "slot_acc4": ("配饰4", 1),
            }

            # 检查并补充缺失的术语
            self.cursor.execute("SELECT key FROM game_terms")
            existing_keys = {row[0] for row in self.cursor.fetchall()}
            missing_terms = []

            for key, (text, is_hidden) in required_terms.items():
                if key not in existing_keys:
                    missing_terms.append((key, text, is_hidden))
                    print(f"[DB] 补充缺失术语: {key} = {text}")

            if missing_terms:
                self.cursor.executemany("INSERT OR IGNORE INTO game_terms (key, text, is_hidden) VALUES (?, ?, ?)", missing_terms)
                print(f"[DB] 已补充 {len(missing_terms)} 个缺失的术语")

            # 15. 游戏数值参数表
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS game_config (
                    key TEXT PRIMARY KEY,
                    value TEXT,  -- 修改为TEXT类型，支持整数、浮点数、字符串
                    desc TEXT
                )
            ''')

            # 🔥🔥🔥 关键补丁：自动升级表结构 🔥🔥🔥
            # 如果旧表是INTEGER类型，自动转换为TEXT
            try:
                self.cursor.execute("SELECT value FROM game_config LIMIT 1")
                # 尝试插入浮点数测试
                self.cursor.execute("INSERT OR IGNORE INTO game_config (key, value, desc) VALUES ('__test_float__', '20.5', 'test')")
                self.cursor.execute("DELETE FROM game_config WHERE key = '__test_float__'")
            except Exception:
                print("[DB] game_config 表结构已升级，value字段支持字符串类型")
                # SQLite不支持直接修改字段类型，需要重建表
                self.cursor.execute("CREATE TABLE game_config_new (key TEXT PRIMARY KEY, value TEXT, desc TEXT)")
                self.cursor.execute("INSERT INTO game_config_new SELECT key, CAST(value AS TEXT), desc FROM game_config")
                self.cursor.execute("DROP TABLE game_config")
                self.cursor.execute("ALTER TABLE game_config_new RENAME TO game_config")

            # 初始化默认数值 (如果表是空的)
            self.cursor.execute("SELECT count(*) FROM game_config")
            if self.cursor.fetchone()[0] == 0:
                configs = [
                    # === 角色创建相关 ===
                    ("stat_cap", "500", "单项属性上限"),
                    ("init_stat_min", "1", "创建人物属性随机下限"),
                    ("init_stat_max", "20", "创建人物属性随机上限"),
                    ("init_money_min", "1", "初始金钱随机下限"),
                    ("init_money_max", "20", "初始金钱随机上限"),
                    ("init_rep_min", "0", "初始名誉随机下限"),
                    ("init_rep_max", "0", "初始名誉随机上限"),

                    # === 日常功能相关 ===
                    ("daily_train_limit", "2", "每日训练次数限制"),
                    ("daily_box_limit", "10", "每日盲盒次数限制"),
                    ("signin_reward_min", "1", "签到奖励下限"),
                    ("signin_reward_max", "10", "签到奖励上限"),

                    # === 盲盒系统 ===
                    ("box_cost", "4", "盲盒单价"),
                    ("box_reward_min", "-2", "盲盒奖励随机下限"),
                    ("box_reward_max", "8", "盲盒奖励随机上限"),
                    ("box_fragment_rate", "20", "盲盒碎片掉落率 (0-100)"),
                    ("box_fragment_name", "礼盒碎片", "盲盒碎片名称"),

                    # === 经济系统 ===
                    ("exchange_rate", "1000", "兑换汇率（虞元换名誉）"),

                    # === 骰子系统 ===
                    ("dice_crit_success", "5", "骰子大成功上限"),
                    ("dice_crit_fail", "96", "骰子大失败下限"),

                    # === 戏录系统 ===
                    ("drama_ooc_limit", "3", "连续OOC发言警告次数"),
                    ("drama_min_len", "2", "戏录内容最小长度"),

                    # === 其他 ===
                    ("card_update_delay", "1.0", "名片更新延迟（秒）"),
                    ("shop_page_size", "15", "商店分页大小"),
                    ("dice_max_num", "50", "骰子最大数量"),
                    ("dice_max_sides", "10000", "骰子最大面数"),
                ]
                self.cursor.executemany("INSERT OR IGNORE INTO game_config (key, value, desc) VALUES (?, ?, ?)", configs)
                print("[DB] 已初始化游戏配置，共{}项".format(len(configs)))

            # 🔥🔥🔥 关键补丁：查漏补缺（自动补充缺失的配置项）🔥🔥🔥
            # 定义所有需要的配置项
            required_configs = {
                # === 角色创建相关 ===
                "stat_cap": ("500", "单项属性上限"),
                "init_stat_min": ("1", "创建人物属性随机下限"),
                "init_stat_max": ("20", "创建人物属性随机上限"),
                "init_money_min": ("1", "初始金钱随机下限"),
                "init_money_max": ("20", "初始金钱随机上限"),
                "init_rep_min": ("0", "初始名誉随机下限"),
                "init_rep_max": ("0", "初始名誉随机上限"),

                # === 日常功能相关 ===
                "daily_train_limit": ("2", "每日训练次数限制"),
                "daily_box_limit": ("10", "每日盲盒次数限制"),
                "signin_reward_min": ("1", "签到奖励下限"),
                "signin_reward_max": ("10", "签到奖励上限"),

                # === 盲盒系统 ===
                "box_cost": ("4", "盲盒单价"),
                "box_reward_min": ("-2", "盲盒奖励随机下限"),
                "box_reward_max": ("8", "盲盒奖励随机上限"),
                "box_fragment_rate": ("20", "盲盒碎片掉落率 (0-100)"),
                "box_fragment_name": ("礼盒碎片", "盲盒碎片名称"),

                # === 经济系统 ===
                "exchange_rate": ("1000", "兑换汇率（虞元换名誉）"),

                # === 骰子系统 ===
                "dice_crit_success": ("5", "骰子大成功上限"),
                "dice_crit_fail": ("96", "骰子大失败下限"),

                # === 戏录系统 ===
                "drama_ooc_limit": ("3", "连续OOC发言警告次数"),
                "drama_min_len": ("2", "戏录内容最小长度"),

                # === 其他 ===
                "card_update_delay": ("1.0", "名片更新延迟（秒）"),
                "shop_page_size": ("15", "商店分页大小"),
                "dice_max_num": ("50", "骰子最大数量"),
                "dice_max_sides": ("10000", "骰子最大面数"),
            }

            # 检查并补充缺失的配置项
            self.cursor.execute("SELECT key FROM game_config")
            existing_keys = {row[0] for row in self.cursor.fetchall()}
            missing_configs = []

            for key, (value, desc) in required_configs.items():
                if key not in existing_keys:
                    missing_configs.append((key, value, desc))
                    print(f"[DB] 补充缺失配置项: {key} = {value}")

            if missing_configs:
                self.cursor.executemany("INSERT OR IGNORE INTO game_config (key, value, desc) VALUES (?, ?, ?)", missing_configs)
                print(f"[DB] 已补充 {len(missing_configs)} 个缺失的配置项")

            # 16. 初始化商品示例数据
            # 检查是否已有商品数据，没有则插入示例
            self.cursor.execute("SELECT count(*) FROM items")
            if self.cursor.fetchone()[0] == 0:
                sample_items = [
                    # === 装备类 (equip) ===
                    ("玉簪", 50, "yuCoin", "equip", "head", "温润的玉簪，增添几分雅致", '{"颜值": 5, "魅力": 3}', '{}', 1, -1, 5, 5),

                    # === 消耗品 (consumable) ===
                    ("美容丹", 100, "yuCoin", "consumable", "", "美容养颜，提升颜值和魅力", '{}', '{"颜值": 15, "魅力": 15}', 1, -1, 3, 3),
                    ("商业秘籍", 120, "reputation", "consumable", "", "商道纵横，提升商业能力", '{}', '{"商业": 25}', 1, -1, 3, 3),
                    ("改名卡", 500, "reputation", "consumable", "", "更换角色姓名", '{}', '{"change_name": 1}', 1, -1, 1, 1),
                ]
                self.cursor.executemany(
                    "INSERT INTO items (name, price, currency, type, slot, desc, stats, effect, is_selling, stock_qty, max_hold, purchase_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    sample_items
                )
                print("[DB] 已初始化商品示例数据，共{}个商品".format(len(sample_items)))

            self.conn.commit()          

    def execute(self, sql, params=()):
        """执行增删改"""
        with self.lock:
            try:
                self.cursor.execute(sql, params)
                self.conn.commit()
                return self.cursor.lastrowid
            except Exception as e:
                print(f"[DB Error] {e} | SQL: {sql}")
                return None

    def query(self, sql, params=()):
        """执行查询"""
        with self.lock:
            try:
                self.cursor.execute(sql, params)
                return self.cursor.fetchall()
            except Exception as e:
                print(f"[DB Error] {e} | SQL: {sql}")
                return []

# 实例化
DB = DatabaseManager()