# 文件：fanlong_core/lib/utils.py
import os
import json

def get_rpg_data_path():
    """向上查找 fanlong_rpg"""
    # lib -> core -> app -> plugin
    base = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    path1 = os.path.join(base, 'fanlong_rpg', 'data.json')
    path2 = os.path.join(base, 'fanlong-rpg', 'data.json')
    if os.path.exists(path1): return path1
    return path2

def get_rpg_name(user_id):
    """通用工具：获取RPG真实姓名"""
    path = get_rpg_data_path()
    try:
        if os.path.exists(path):
            with open(path, 'r', encoding='utf-8') as f:
                data = json.load(f)
                uid = str(user_id)
                if "users" in data and uid in data["users"]:
                    return data["users"][uid].get("name")
    except: pass
    return None