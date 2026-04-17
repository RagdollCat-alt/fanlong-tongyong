import os
import math
import time
import random
from PIL import Image, ImageDraw, ImageFont, ImageFilter

# ================= 配置区 =================
# 🟢 核心升级：4倍超采样 (4K分辨率渲染)
# 画布在内存中是 4000x2400，最后缩放输出，彻底解决字体模糊问题
SCALE = 4 
WIDTH = 1000 * SCALE
HEIGHT = 600 * SCALE

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
FONT_DIR = os.path.join(CURRENT_DIR, 'resources', 'fonts')
TEMP_DIR = os.path.join(CURRENT_DIR, 'temp_cards')
if not os.path.exists(TEMP_DIR): os.makedirs(TEMP_DIR)

# 字体加载
PATH_BOLD = os.path.join(FONT_DIR, 'bold.ttf')
PATH_REG = os.path.join(FONT_DIR, 'regular.ttf')

def load_font(size, weight='bold'):
    path = PATH_BOLD if weight == 'bold' else PATH_REG
    try:
        return ImageFont.truetype(path, size * SCALE)
    except:
        try:
            return ImageFont.truetype(PATH_BOLD, size * SCALE)
        except:
            return ImageFont.load_default()

# ================= 🎨 核心绘图类 =================

class GlowPainter:
    def __init__(self, citizen_type):
        self.citizen = citizen_type
        
        # === 1. 🩸 罪奴籍 (CRIMINAL) ===
        if citizen_type == '罪奴籍':
            self.c = {
                'bg_fill': (12, 10, 10),
                'bg_pattern': (50, 20, 20),
                'panel_bg': (25, 20, 20, 240),
                'panel_border': (60, 40, 40),
                'accent': (200, 180, 180),
                'name_color': (255, 60, 60),
                'name_glow': (200, 0, 0),
                'radar_line': (180, 160, 160), # 网格线颜色
                'radar_data_line': (255, 100, 100), # 数据轮廓线
                'radar_fill': (255, 50, 50, 30), # 🟢 降低透明度，防止遮挡
                'radar_node': (255, 100, 100),
                'bar_fill': (220, 200, 200),
                'badge_bg': (180, 50, 50),
                'badge_txt': (0, 0, 0)
            }
            
        # === 2. ⛓️ 奴籍 (SLAVE) ===
        elif citizen_type == '奴籍':
            self.c = {
                'bg_fill': (15, 15, 18),
                'bg_pattern': (30, 30, 40),
                'panel_bg': (30, 30, 35, 240),
                'panel_border': (50, 50, 60),
                'accent': (200, 200, 220),
                'name_color': (255, 255, 255),
                'name_glow': (200, 220, 255),
                'radar_line': (160, 170, 180),
                'radar_data_line': (200, 220, 255),
                'radar_fill': (200, 220, 255, 25), # 🟢 降低透明度
                'radar_node': (255, 255, 255),
                'bar_fill': (200, 200, 220),
                'badge_bg': (180, 180, 190),
                'badge_txt': (0, 0, 0)
            }
            
        # === 3. 👑 公民籍 (MASTER) ===
        else:
            self.c = {
                'bg_fill': (40, 5, 5),
                'bg_pattern': (70, 20, 20),
                'panel_bg': (60, 10, 10, 200),
                'panel_border': (100, 40, 40),
                'accent': (255, 200, 150),
                'name_color': (255, 215, 0),
                'name_glow': (255, 80, 0),
                'radar_line': (200, 150, 50),
                'radar_data_line': (255, 215, 0),
                'radar_fill': (255, 165, 0, 35), # 🟢 降低透明度
                'radar_node': (255, 255, 200),
                'bar_fill': (255, 215, 0),
                'badge_bg': (255, 215, 0),
                'badge_txt': (0, 0, 0)
            }

    def draw_background(self, draw):
        # 1. 填充底色
        draw.rectangle((0, 0, WIDTH, HEIGHT), fill=self.c['bg_fill'])
        color = self.c['bg_pattern']
        
        if self.citizen in ['奴籍', '罪奴籍']:
            # 细腻网格
            step = 50 * SCALE
            for x in range(-HEIGHT, WIDTH + HEIGHT, step):
                draw.line([(x, 0), (x + HEIGHT, HEIGHT)], fill=color, width=1*SCALE)
                draw.line([(x + HEIGHT, 0), (x, HEIGHT)], fill=color, width=1*SCALE)
        else:
            # 罗盘光环
            cx, cy = 250 * SCALE, 300 * SCALE
            for r in [220, 380, 550, 700]:
                width = 2 * SCALE if r % 100 != 0 else 8 * SCALE
                draw.ellipse((cx-r*SCALE, cy-r*SCALE, cx+r*SCALE, cy+r*SCALE), outline=color, width=width)
            draw.line([(cx, 0), (cx, HEIGHT)], fill=color, width=2*SCALE)
            draw.line([(0, cy), (WIDTH, cy)], fill=color, width=2*SCALE)
            for i in range(0, 360, 45):
                angle = math.radians(i)
                x2 = cx + 800 * SCALE * math.cos(angle)
                y2 = cy + 800 * SCALE * math.sin(angle)
                draw.line([(cx, cy), (x2, y2)], fill=color, width=4*SCALE)

    def draw_panel_box(self, canvas, box):
        x, y, w, h = box
        draw = ImageDraw.Draw(canvas, 'RGBA')
        draw.rounded_rectangle((x, y, x+w, y+h), radius=12*SCALE, fill=self.c['panel_bg'])
        draw.rounded_rectangle((x, y, x+w, y+h), radius=12*SCALE, outline=self.c['panel_border'], width=1*SCALE)

    def draw_glow_text(self, canvas, xy, text, font, fill_color, glow_color, glow_radius=15, anchor="mm"):
        # 4K模式下光晕半径要乘比例
        r_scale = glow_radius * 2 # 稍微加强一点
        glow_layer = Image.new('RGBA', canvas.size, (0,0,0,0))
        d = ImageDraw.Draw(glow_layer)
        d.text(xy, text, font=font, fill=glow_color+(100,), anchor=anchor)
        d.text(xy, text, font=font, fill=glow_color+(180,), anchor=anchor)
        glow_layer = glow_layer.filter(ImageFilter.GaussianBlur(r_scale))
        canvas.alpha_composite(glow_layer)
        draw = ImageDraw.Draw(canvas)
        draw.text(xy, text, font=font, fill=fill_color, anchor=anchor)

    def draw_barcode(self, canvas, x, y, w, h, seed_str):
        draw = ImageDraw.Draw(canvas)
        bar_color = (180, 180, 180)
        random.seed(seed_str)
        curr_x = x
        end_x = x + w
        while curr_x < end_x:
            bar_w = random.choice([2, 4, 6, 8]) * SCALE
            if curr_x + bar_w > end_x: break
            if random.random() > 0.3:
                draw.rectangle((curr_x, y, curr_x+bar_w, y+h), fill=bar_color)
            curr_x += bar_w + 2 * SCALE
        
        f_code = load_font(10, 'regular')
        text = "PROPERTY OF FANLONG SECTOR"
        tw = draw.textlength(text, font=f_code)
        draw.text((x + w//2 - tw//2, y + h + 8*SCALE), text, font=f_code, fill=(100,100,100))

    def render(self, user_data, stats):
        img = Image.new('RGBA', (WIDTH, HEIGHT))
        draw = ImageDraw.Draw(img)
        self.draw_background(draw)

        m = 30 * SCALE
        left_w = 340 * SCALE
        
        # 面板框
        self.draw_panel_box(img, (m, m, left_w, HEIGHT-2*m))
        
        right_x = m + left_w + 20*SCALE
        right_w = WIDTH - right_x - m
        top_h = 380 * SCALE
        self.draw_panel_box(img, (right_x, m, right_w, top_h))
        
        bot_y = m + top_h + 20*SCALE
        bot_h = HEIGHT - bot_y - m
        self.draw_panel_box(img, (right_x, bot_y, right_w, bot_h))

        # === 左侧 ===
        profile = user_data.get('profile', {})
        name = user_data.get('name', '未知')
        citizen_txt = self.citizen if self.citizen != '公民籍' else '公 民 籍'
        lcx = m + left_w // 2
        
        draw.text((lcx, 80*SCALE), citizen_txt, font=load_font(12, 'regular'), fill=self.c['accent'], anchor="mm")
        
        # 姓名 (大字)
        self.draw_glow_text(img, (lcx, 150*SCALE), name, load_font(64, 'bold'), 
                           self.c['name_color'], self.c['name_glow'], glow_radius=10) # 半径修正适应4K
        
        draw.line([(lcx-100*SCALE, 210*SCALE), (lcx+100*SCALE, 210*SCALE)], fill=self.c['panel_border'], width=2*SCALE)
        
        draw.text((lcx, 240*SCALE), "IDENTIFICATION", font=load_font(10, 'regular'), fill=self.c['accent']+(100,), anchor="mm")
        uid_str = f"ID-{str(user_data.get('uid','')).zfill(6)}"
        draw.text((lcx, 270*SCALE), uid_str, font=load_font(24, 'bold'), fill=self.c['accent'], anchor="mm")
        
        family = profile.get('家世', '无')
        job = profile.get('职位', '无')
        draw.text((lcx, 340*SCALE), family, font=load_font(13, 'regular'), fill=self.c['accent']+(150,), anchor="mm")
        
        job_glow = self.c['name_glow'] if self.citizen not in ['奴籍', '罪奴籍'] else self.c['accent']
        job_col = self.c['name_color'] if self.citizen not in ['奴籍', '罪奴籍'] else self.c['accent']
        self.draw_glow_text(img, (lcx, 380*SCALE), job, load_font(24, 'bold'), job_col, job_glow, 5)
        
        if self.citizen in ['奴籍', '罪奴籍']:
            self.draw_barcode(img, lcx - 120*SCALE, 460*SCALE, 240*SCALE, 70*SCALE, uid_str)
        else:
            motto = "“凡王之血，必以剑终”"
            draw.text((lcx, 490*SCALE), motto, font=load_font(14, 'regular'), fill=self.c['name_color'], anchor="mm")
            draw.line([(lcx-120*SCALE, 460*SCALE), (lcx+120*SCALE, 460*SCALE)], fill=self.c['name_color']+(40,), width=1*SCALE)

        # === 右上雷达 ===
        radar_cx = right_x + right_w // 2
        radar_cy = m + top_h // 2
        radius = 115 * SCALE
        self.draw_radar(img, stats, radar_cx, radar_cy, radius)
        
        # 属性列表
        keys = list(stats.keys())
        y_start = m + 60*SCALE
        for i, k in enumerate(keys[:4]):
            self.draw_stat_bar(img, right_x + 50*SCALE, y_start + i*75*SCALE, k, stats[k], 'left')
        for i, k in enumerate(keys[4:8]):
            self.draw_stat_bar(img, right_x + right_w - 50*SCALE, y_start + i*75*SCALE, k, stats[k], 'right')

        # === 右下资产 ===
        bot_cy = bot_y + bot_h // 2
        yu = f"{user_data['currency'].get('yuCoin', 0):,}"
        rep = f"{user_data['currency'].get('reputation', 0):,}"
        
        self.draw_asset(img, right_x + 80*SCALE, bot_cy, "NET CURRENCY", yu, "YU")
        self.draw_asset(img, right_x + 300*SCALE, bot_cy, "SOCIAL INFLUENCE", rep, "PTS")
        
        status_txt = "REGULATED" if self.citizen in ['奴籍', '罪奴籍'] else "VERIFIED"
        bx = right_x + right_w - 120*SCALE
        draw.rounded_rectangle((bx-60*SCALE, bot_cy-18*SCALE, bx+60*SCALE, bot_cy+18*SCALE), radius=6*SCALE, fill=self.c['badge_bg'])
        draw.text((bx, bot_cy), status_txt, font=load_font(11, 'bold'), fill=self.c['badge_txt'], anchor="mm")
        draw.text((bx, bot_cy-35*SCALE), "AUTH STATUS", font=load_font(9, 'regular'), fill=self.c['accent']+(100,), anchor="mm")

        # 🟢 最终输出缩放 (LANCZOS 算法是抗锯齿的关键)
        final = img.resize((int(WIDTH/SCALE), int(HEIGHT/SCALE)), Image.Resampling.LANCZOS)
        path = os.path.join(TEMP_DIR, f"card_{user_data.get('uid',0)}_{int(time.time())}.jpg")
        final.convert('RGB').save(path, quality=95)
        return path

    def draw_stat_bar(self, canvas, x, y, label, val, align):
        draw = ImageDraw.Draw(canvas)
        f_lbl = load_font(11, 'bold')
        f_val = load_font(11, 'regular')
        bar_w = 130 * SCALE
        
        if align == 'left':
            draw.text((x, y-12*SCALE), label, font=f_lbl, fill=self.c['accent'], anchor="ls")
            draw.text((x+bar_w, y-12*SCALE), str(val), font=f_val, fill=self.c['accent'], anchor="rs")
            draw.rectangle((x, y, x+bar_w, y+4*SCALE), fill=(50,50,50))
            # 🟢 修改点：200 -> 500
            draw.rectangle((x, y, x+(min(val, 500)/500)*bar_w, y+4*SCALE), fill=self.c['bar_fill'])
        else:
            draw.text((x, y-12*SCALE), label, font=f_lbl, fill=self.c['accent'], anchor="rs")
            draw.text((x-bar_w, y-12*SCALE), str(val), font=f_val, fill=self.c['accent'], anchor="ls")
            start_x = x - bar_w
            draw.rectangle((start_x, y, start_x+bar_w, y+4*SCALE), fill=(50,50,50))
            # 🟢 修改点：200 -> 500
            draw.rectangle((start_x, y, start_x+(min(val, 500)/500)*bar_w, y+4*SCALE), fill=self.c['bar_fill'])
            
    def draw_asset(self, canvas, x, y, label, val, unit):
        draw = ImageDraw.Draw(canvas)
        draw.text((x, y-25*SCALE), label, font=load_font(9, 'regular'), fill=self.c['accent']+(120,), anchor="ls")
        draw.text((x, y+15*SCALE), val, font=load_font(30, 'bold'), fill=self.c['name_color'], anchor="ls")
        w = draw.textlength(val, font=load_font(30, 'bold'))
        draw.text((x+w+10*SCALE, y+15*SCALE), unit, font=load_font(12, 'bold'), fill=self.c['accent']+(150,), anchor="ls")

    def draw_radar(self, canvas, stats, cx, cy, r):
        """
        绘制雷达图
        优化点：
        1. 属性标签向外推，防止遮挡
        2. 填充透明度降低，防止遮挡网格
        """
        draw = ImageDraw.Draw(canvas, 'RGBA')
        keys = list(stats.keys())
        N = len(keys)
        
        # 1. 网格 (线更细，颜色更淡)
        for i in range(1, 5):
            pts = []
            cr = r * (i/4)
            for j in range(N):
                ang = (2*math.pi/N)*j - math.pi/2
                pts.append((cx + cr*math.cos(ang), cy + cr*math.sin(ang)))
            draw.polygon(pts, outline=self.c['radar_line']+(40,), width=1*SCALE)
            
        # 2. 轴线
        for j in range(N):
            ang = (2*math.pi/N)*j - math.pi/2
            draw.line([(cx, cy), (cx+r*math.cos(ang), cy+r*math.sin(ang))], fill=self.c['radar_line']+(30,), width=1*SCALE)
            
        # 3. 数据
        dpts = []
        for i, k in enumerate(keys):
            v = min(stats[k], 500)
            cr = r * (v/500)
            ang = (2*math.pi/N)*i - math.pi/2
            dpts.append((cx + cr*math.cos(ang), cy + cr*math.sin(ang)))
        
        # 填充 (透明度低)
        draw.polygon(dpts, fill=self.c['radar_fill'])
        # 数据轮廓线 (高亮)
        draw.line(dpts+[dpts[0]], fill=self.c['radar_data_line'], width=2*SCALE)
        
        # 节点
        for p in dpts:
            draw.ellipse((p[0]-4*SCALE, p[1]-4*SCALE, p[0]+4*SCALE, p[1]+4*SCALE), fill=self.c['radar_node'])

def render(user_data, stats_dict):
    profile = user_data.get('profile', {})
    citizen = profile.get('户籍', '公民籍')
    painter = GlowPainter(citizen)
    return painter.render(user_data, stats_dict)