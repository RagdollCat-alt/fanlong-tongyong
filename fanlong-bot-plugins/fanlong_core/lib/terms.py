from .db import DB

class TermManager:
    def __init__(self):
        self.cache = {}
        self.visible_cache = set()  # 存储可见的字段key (is_hidden=1)
        # 暂时不在 init 里调用 reload，避免 DB 还没准备好

    def reload(self):
        """从数据库重新加载术语"""
        try:
            # 先检查表是否存在，防止初始化阶段报错
            check = DB.query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='game_terms'")
            if not check or check[0][0] == 0:
                return

            rows = DB.query("SELECT key, text, is_hidden FROM game_terms")
            self.cache = {r[0]: r[1] for r in rows}
            # 加载可见字段 (is_hidden=1 表示可见)
            self.visible_cache = {r[0] for r in rows if r[2] == 1}
            print(f"[Core] 已加载 {len(self.cache)} 个游戏术语配置，其中 {len(self.visible_cache)} 个可见")
        except Exception as e:
            print(f"[Core] 术语加载失败: {e}")

    def get(self, key, default=None):
        """获取术语文本"""
        return self.cache.get(key, default or key)

    def is_visible(self, key):
        """检查字段是否可见 (is_hidden=1)"""
        return key in self.visible_cache

    def get_reverse_dict(self, prefix="stat_"):
        """获取反向映射 (显示名 -> 内部Key)"""
        res = {}
        for k, v in self.cache.items():
            if k.startswith(prefix):
                res[v] = k
        return res

# 单例模式
Terms = TermManager()