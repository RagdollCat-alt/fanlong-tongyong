<?php
/**
 * 繁笼机器人 B端后台 v2.0 - 配置文件
 */

define('DB_PATH', 'C:/Users/Administrator/Desktop/青果核-py代码/plugin/app/fanlong_core/data/fanlong.db');
define('APP_VERSION', '2.0.0');
define('APP_NAME', '繁笼后台管理系统');

session_start();
date_default_timezone_set('Asia/Shanghai');
error_reporting(0);
ini_set('display_errors', 0);

// ====================================================================
// 权限矩阵
// ====================================================================
$PERMISSION_MATRIX = [
    'users'           => ['view', 'edit', 'delete'],
    'stats'           => ['view', 'edit'],
    'user_bag'        => ['view', 'edit', 'delete'],
    'user_equip'      => ['view', 'edit'],
    'items'           => ['view', 'add', 'edit', 'delete'],
    'game_config'     => ['view', 'edit'],
    'game_terms'      => ['view', 'add', 'edit', 'delete'],
    'custom_replies'  => ['view', 'add', 'edit', 'delete'],
    'system_vars'     => ['view', 'edit'],
    'drama_archives'  => ['view', 'add', 'edit', 'delete'],
    'history_stats'   => ['view'],
    'item_instances'  => ['view', 'edit', 'delete'],
    'broadcast'       => ['execute'],
    'admins'          => ['view', 'add', 'edit', 'delete'],
    'logs'            => ['view'],
    'backup'          => ['execute'],
    'tables'          => ['view'],
];

$MODULE_NAMES = [
    'users'           => '用户管理',
    'stats'           => '属性管理',
    'user_bag'        => '背包管理',
    'user_equip'      => '装备管理',
    'items'           => '商店管理',
    'game_config'     => '游戏配置',
    'game_terms'      => '术语翻译',
    'custom_replies'  => '自定义回复',
    'system_vars'     => '系统变量',
    'drama_archives'  => '剧情档案',
    'history_stats'   => '历史统计',
    'item_instances'  => '物品实例',
    'broadcast'       => '批量发送',
    'admins'          => '管理员',
    'logs'            => '日志查看',
    'backup'          => '数据备份',
    'tables'          => '数据表',
    'cleanup'         => '数据清理',
];

$ACTION_NAMES = [
    'view'            => '查看',
    'add'             => '新增',
    'edit'            => '编辑',
    'delete'          => '删除',
    'execute'         => '执行',
    'login'           => '登录',
    'logout'          => '退出',
    'create'          => '创建',
    'update'          => '更新',
    'change_password' => '修改密码',
    'reset_password'  => '重置密码',
    'toggle'          => '切换状态',
    'restore'         => '恢复',
];

// ====================================================================
// 数据库连接
// ====================================================================
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO('sqlite:' . DB_PATH);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('PRAGMA foreign_keys = ON;');
            $db->exec('PRAGMA journal_mode = WAL;');
        } catch (PDOException $e) {
            die('<div style="padding:40px;font-family:sans-serif;background:#1a1a2e;color:#e94560;min-height:100vh;">'
                . '<h2>⚠️ 数据库连接失败</h2>'
                . '<p style="color:#ccc">' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<p style="color:#888">当前路径: <code style="color:#f8c471">' . htmlspecialchars(DB_PATH) . '</code></p>'
                . '<p style="color:#888">请检查 config.php 中的 DB_PATH，并确认宝塔面板PHP用户对该文件有读写权限。</p>'
                . '</div>');
        }
    }
    return $db;
}

// ====================================================================
// 数据库自动迁移
// ====================================================================
function migrateDB() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();

        // 升级 admins 表
        $cols = array_column($db->query("PRAGMA table_info(admins)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('password_hash', $cols)) $db->exec("ALTER TABLE admins ADD COLUMN password_hash TEXT");
        if (!in_array('permissions',   $cols)) $db->exec("ALTER TABLE admins ADD COLUMN permissions TEXT DEFAULT '{}'");
        if (!in_array('last_login',    $cols)) $db->exec("ALTER TABLE admins ADD COLUMN last_login TEXT");
        if (!in_array('nickname',      $cols)) $db->exec("ALTER TABLE admins ADD COLUMN nickname TEXT");

        // 操作日志表
        $db->exec("CREATE TABLE IF NOT EXISTS admin_logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id   TEXT NOT NULL,
            module     TEXT NOT NULL,
            action     TEXT NOT NULL,
            target_id  TEXT,
            old_data   TEXT,
            new_data   TEXT,
            ip         TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )");

        // 登录日志表
        $db->exec("CREATE TABLE IF NOT EXISTS login_logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    TEXT NOT NULL,
            ip         TEXT,
            user_agent TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )");

        // game_terms: 确保 category 和 is_hidden 字段存在
        $tcols = array_column($db->query("PRAGMA table_info(game_terms)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('category', $tcols)) {
            $db->exec("ALTER TABLE game_terms ADD COLUMN category TEXT DEFAULT 'other'");
        }
        if (!in_array('is_hidden', $tcols)) {
            // 新增字段，默认 1（与机器人约定一致：is_hidden=1 = 可见，is_hidden=0 = 隐藏）
            $db->exec("ALTER TABLE game_terms ADD COLUMN is_hidden INTEGER DEFAULT 1");
        } else {
            // 如果所有词条都是 0（可能被旧迁移误设），批量恢复为 1（可见）
            $total   = (int)$db->query("SELECT COUNT(*) FROM game_terms")->fetchColumn();
            $all_off = (int)$db->query("SELECT COUNT(*) FROM game_terms WHERE is_hidden=0")->fetchColumn();
            if ($total > 0 && $total === $all_off) {
                $db->exec("UPDATE game_terms SET is_hidden=1");
            }
        }

    } catch (Exception $e) {
        // 静默失败
    }
}

// ====================================================================
// 认证与会话
// ====================================================================
function checkLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit();
    }
    $page   = basename($_SERVER['PHP_SELF']);
    $exempt = ['change_password.php', 'logout.php', 'login.php'];
    if (!in_array($page, $exempt) && empty($_SESSION['password_set'])) {
        header('Location: change_password.php?force=1');
        exit();
    }
}

function isSuperAdmin() {
    return isset($_SESSION['admin_level']) && intval($_SESSION['admin_level']) >= 999;
}

// ====================================================================
// 权限检查
// ====================================================================
function can($module, $action = 'view') {
    if (isSuperAdmin()) return true;
    $perms = $_SESSION['permissions'] ?? [];
    if (is_string($perms)) $perms = json_decode($perms, true) ?? [];
    return in_array($action, $perms[$module] ?? []);
}

function requirePermission($module, $action = 'view') {
    if (!can($module, $action)) {
        http_response_code(403);
        include '403.php';
        exit();
    }
}

function getDefaultPermissions() {
    global $PERMISSION_MATRIX;
    $perms = [];
    foreach ($PERMISSION_MATRIX as $mod => $actions) {
        $perms[$mod] = in_array('view', $actions) ? ['view'] : [];
    }
    return $perms;
}

// ====================================================================
// 操作日志
// ====================================================================
function logAction($module, $action, $target_id = '', $old_data = null, $new_data = null) {
    try {
        $db  = getDB();
        $uid = $_SESSION['admin_id'] ?? 'system';
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $old = ($old_data !== null) ? json_encode($old_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $new = ($new_data !== null) ? json_encode($new_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $db->prepare("INSERT INTO admin_logs (admin_id,module,action,target_id,old_data,new_data,ip) VALUES (?,?,?,?,?,?,?)")
           ->execute([$uid, $module, $action, strval($target_id), $old, $new, $ip]);
    } catch (Exception $e) { }
}

// ====================================================================
// 术语翻译系统
// ====================================================================
function getTermsCache() {
    static $cache = null;
    if ($cache === null) {
        try {
            // is_hidden=1 表示可见（与机器人约定一致）
            $rows  = getDB()->query("SELECT key, text FROM game_terms WHERE is_hidden=1 OR is_hidden IS NULL")->fetchAll();
            $cache = array_column($rows, 'text', 'key');
        } catch (Exception $e) { $cache = []; }
    }
    return $cache;
}

function getTermsCacheAll() {
    static $cache_all = null;
    if ($cache_all === null) {
        try {
            $rows       = getDB()->query("SELECT key, text FROM game_terms")->fetchAll();
            $cache_all  = array_column($rows, 'text', 'key');
        } catch (Exception $e) { $cache_all = []; }
    }
    return $cache_all;
}

/** 精确键名翻译，找不到返回 $default */
function t($key, $default = null) {
    $c = getTermsCacheAll();
    return $c[$key] ?? ($default ?? $key);
}

/**
 * 智能翻译：依次尝试原始键 → term_键 → slot_键
 * 用于自动翻译 currency (yuCoin→虞元)、slot (hair→发型) 等命名约定不一的键
 */
function tAuto($key, $default = null) {
    $c = getTermsCacheAll();
    if (isset($c[$key]))              return $c[$key];
    if (isset($c['term_' . $key]))    return $c['term_' . $key];
    if (isset($c['slot_' . $key]))    return $c['slot_' . $key];
    if (isset($c['profile_' . $key])) return $c['profile_' . $key];
    return $default ?? $key;
}

/** 翻译槽位键名（hair→slot_hair对应的中文） */
function tSlot($key, $fallback = '') {
    $c = getTermsCacheAll();
    if (isset($c['slot_' . $key])) return $c['slot_' . $key];
    if (isset($c[$key]))           return $c[$key];
    return $fallback ?: $key;
}

// ====================================================================
// 可见属性字段（尊重 game_terms.is_hidden 配置）
// ====================================================================
const ALL_STAT_FIELDS = ['stat_face','stat_charm','stat_intel','stat_biz','stat_talk','stat_body','stat_art','stat_obed'];

/**
 * 返回 is_hidden=1（对玩家可见）的属性字段数组。
 * 若 game_terms 中一条 stat_* 都未配置，回退返回全部 8 个（兼容新装库）。
 */
function getVisibleStatFields() {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = getDB()->query(
            "SELECT key FROM game_terms WHERE key LIKE 'stat_%' AND is_hidden=1"
        )->fetchAll(PDO::FETCH_COLUMN, 0);
        $visible = array_values(array_intersect(ALL_STAT_FIELDS, $rows));
        $cache = !empty($visible) ? $visible : ALL_STAT_FIELDS;
    } catch (Exception $e) {
        $cache = ALL_STAT_FIELDS;
    }
    return $cache;
}

/**
 * 返回档案字段的可见性映射：["中文字段名" => is_hidden(1=可见,0=隐藏)]
 * 用于 view 页判断 profile JSON 里每个字段是否对玩家可见。
 */
function getProfileFieldsVisibility() {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = getDB()->query(
            "SELECT text, is_hidden FROM game_terms WHERE key LIKE 'profile_%'"
        )->fetchAll();
        $cache = array_column($rows, 'is_hidden', 'text');
    } catch (Exception $e) { $cache = []; }
    return $cache;
}

/**
 * 返回装备槽位的可见性映射：["slot_code" => is_hidden(1=可见,0=隐藏)]
 * slot_code 是 hair/top/bottom 等，对应 game_terms 里的 slot_* 键。
 */
function getSlotFieldsVisibility() {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = getDB()->query(
            "SELECT REPLACE(key,'slot_','') AS slot_code, is_hidden FROM game_terms WHERE key LIKE 'slot_%'"
        )->fetchAll();
        $cache = array_column($rows, 'is_hidden', 'slot_code');
    } catch (Exception $e) { $cache = []; }
    return $cache;
}

// ====================================================================
// 物品类型标准映射（数据库中无 game_terms 条目时的回退）
// ====================================================================
function getItemTypeMap() {
    return [
        ''           => '(无类型)',
        'equip'      => '装备',
        'consumable' => '消耗品',
    ];
}

function tItemType($type) {
    $map = getItemTypeMap();
    return $map[$type] ?? t($type, $type);
}

// ====================================================================
// 工具函数
// ====================================================================
function safeInput($data) {
    return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
}

function safeJsonDecode($json, $assoc = true) {
    if (empty($json)) return $assoc ? [] : null;
    $r = json_decode($json, $assoc);
    if ($r !== null) return $r;
    $r = json_decode(htmlspecialchars_decode($json, ENT_QUOTES), $assoc);
    if ($r !== null) return $r;
    $r = json_decode(str_replace(['&quot;', '&#34;'], '"', $json), $assoc);
    return $r ?? ($assoc ? [] : null);
}

function formatBytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function getAllTables() {
    $stmt = getDB()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

function setFlash($type, $msg) { $_SESSION['flash'] = ['type' => $type, 'message' => $msg]; }
function getFlash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

// ====================================================================
// 初始化
// ====================================================================
migrateDB();
