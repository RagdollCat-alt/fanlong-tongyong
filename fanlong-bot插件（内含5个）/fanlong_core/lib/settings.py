# 文件路径：fanlong_core/lib/settings.py
import re
from .db import DB

class SettingsManager:
    def __init__(self):
        self.cache = {}
        # 初始化时加载
        self.reload()

    def reload(self):
        """从数据库加载配置，支持 int/float/string 混合读取"""
        try:
            # 🟢 1. 强制刷新数据库事务 (关键：让机器人看到外部工具修改的数据)
            if hasattr(DB, 'conn'):
                DB.conn.commit()

            # 2. 读取所有配置
            rows = DB.query("SELECT key, value FROM game_config")
            
            new_cache = {}
            for r in rows:
                key = r[0]
                val = r[1] # 数据库里通常是字符串格式
                
                # 🟢 3. 智能类型转换 (修复 int() 报错导致中断的问题)
                if val is None:
                    parsed_val = 0
                # 尝试转整数
                elif str(val).lstrip('-').isdigit(): 
                    parsed_val = int(val)
                # 尝试转小数 (支持 0.5, 99.9 等)
                elif self._is_float(val):
                    parsed_val = float(val)
                # 否则保留为字符串 (例如 "幸运碎片")
                else:
                    parsed_val = str(val)
                    
                new_cache[key] = parsed_val

            self.cache = new_cache
            print(f"[Core] Settings重载完成 | 已加载 {len(self.cache)} 项配置")
            
            # 🟢 4. 打印几个关键值，方便您确认 (可选)
            # print(f"   - 盲盒概率: {self.cache.get('box_fragment_rate')}")
            # print(f"   - 基金波动: {self.cache.get('fund_market_min')}~{self.cache.get('fund_market_max')}")

        except Exception as e:
            print(f"[Core] ❌ 数值配置加载严重失败: {e}")
            # 出错时不覆盖旧缓存，防止系统瘫痪

    def get(self, key, default=0):
        """获取数值，支持直接返回正确类型"""
        return self.cache.get(key, default)

    def _is_float(self, s):
        """辅助函数：判断是不是小数"""
        try:
            float(s)
            return True
        except ValueError:
            return False

# 单例模式
Settings = SettingsManager()