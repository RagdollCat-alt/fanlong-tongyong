<?php
require_once 'config.php';

if (isset($_SESSION['admin_id'])) { header('Location: index.php'); exit(); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id  = trim($_POST['user_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($user_id)) {
        $error = '请输入 QQ 号';
    } elseif (empty($password)) {
        $error = '请输入密码';
    } else {
        try {
            $db    = getDB();
            $admin = $db->prepare("SELECT user_id, level, password_hash, permissions, nickname FROM admins WHERE user_id = ?");
            $admin->execute([$user_id]);
            $admin = $admin->fetch();

            if (!$admin) {
                $error = '该 QQ 号不在管理员名单中，请联系超级管理员添加账号';
            } elseif (empty($admin['password_hash'])) {
                // 首次登录：将输入的密码直接设为登录密码
                if (strlen($password) < 6) {
                    $error = '首次登录请设置密码，密码长度不少于 6 位';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $db->prepare("UPDATE admins SET password_hash=?,last_login=datetime('now','localtime') WHERE user_id=?")
                       ->execute([$hash, $admin['user_id']]);
                    $_SESSION['admin_id']       = $admin['user_id'];
                    $_SESSION['admin_level']    = $admin['level'];
                    $_SESSION['admin_nickname'] = $admin['nickname'] ?? $admin['user_id'];
                    $_SESSION['permissions']    = $admin['permissions'] ?? '{}';
                    $_SESSION['password_set']   = true;
                    $_SESSION['login_time']     = time();
                    $db->prepare("INSERT INTO login_logs (user_id, ip, user_agent) VALUES (?,?,?)")
                       ->execute([$admin['user_id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                    logAction('admins', 'login', $admin['user_id']);
                    setFlash('success', '密码设置成功！欢迎首次登录系统。');
                    header('Location: index.php');
                    exit();
                }
            } elseif (password_verify($password, $admin['password_hash'])) {
                $_SESSION['admin_id']       = $admin['user_id'];
                $_SESSION['admin_level']    = $admin['level'];
                $_SESSION['admin_nickname'] = $admin['nickname'] ?? $admin['user_id'];
                $_SESSION['permissions']    = $admin['permissions'] ?? '{}';
                $_SESSION['password_set']   = true;
                $_SESSION['login_time']     = time();
                // 更新最后登录时间
                $db->prepare("UPDATE admins SET last_login=datetime('now','localtime') WHERE user_id=?")
                   ->execute([$admin['user_id']]);
                // 记录登录日志
                $db->prepare("INSERT INTO login_logs (user_id, ip, user_agent) VALUES (?,?,?)")
                   ->execute([$admin['user_id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
                logAction('admins', 'login', $admin['user_id']);
                header('Location: index.php');
                exit();
            } else {
                $error = '密码错误';
            }
        } catch (Exception $e) {
            $error = '登录失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo APP_NAME; ?> · 登录</title>
<script>(function(){var t=localStorage.getItem('fl_theme')||'dark';document.documentElement.setAttribute('data-bs-theme',t);})();</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --brand:      oklch(0.59 0.22 22);
  --brand-glow: oklch(0.59 0.22 22 / 0.30);
  --brand-dim:  oklch(0.59 0.22 22 / 0.13);
}
[data-bs-theme="dark"] {
  --bg:      oklch(0.11 0.005 258);
  --surface: oklch(0.15 0.007 258);
  --border:  oklch(0.22 0.008 258);
  --t-1:     oklch(0.92 0.004 258);
  --t-2:     oklch(0.55 0.006 258);
  --t-3:     oklch(0.36 0.005 258);
}
[data-bs-theme="light"] {
  --bg:      oklch(0.965 0.003 258);
  --surface: oklch(1.00 0.000 0);
  --border:  oklch(0.87 0.005 258);
  --t-1:     oklch(0.14 0.005 258);
  --t-2:     oklch(0.42 0.007 258);
  --t-3:     oklch(0.60 0.005 258);
}

*, *::before, *::after { box-sizing: border-box; }

body {
  margin: 0; min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  background: var(--bg);
  font-family: -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Noto Sans SC',
               'Microsoft YaHei', 'Segoe UI', sans-serif;
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
  position: relative;
  overflow: hidden;
}

/* Background texture — subtle grid */
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image:
    linear-gradient(oklch(0.59 0.22 22 / 0.03) 1px, transparent 1px),
    linear-gradient(90deg, oklch(0.59 0.22 22 / 0.03) 1px, transparent 1px);
  background-size: 48px 48px;
  pointer-events: none;
}

/* Glow orb */
body::after {
  content: '';
  position: fixed;
  width: 600px; height: 600px;
  border-radius: 50%;
  background: radial-gradient(circle, oklch(0.59 0.22 22 / 0.07) 0%, transparent 70%);
  top: -150px; left: -100px;
  pointer-events: none;
}

.login-wrap {
  position: relative; z-index: 1;
  width: 100%; max-width: 420px;
  padding: 16px;
}

.login-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 18px;
  padding: 40px 36px 32px;
  box-shadow: 0 24px 64px oklch(0 0 0 / 0.40);
  animation: card-in 0.35s cubic-bezier(0.4, 0, 0.2, 1) both;
}
@keyframes card-in {
  from { opacity: 0; transform: translateY(20px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0)    scale(1);    }
}

.login-icon {
  width: 64px; height: 64px;
  background: var(--brand);
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.8rem; color: #fff;
  margin: 0 auto 22px;
  box-shadow: 0 8px 28px var(--brand-glow);
}

.login-title {
  text-align: center;
  font-size: 1.25rem; font-weight: 700;
  color: var(--t-1); margin: 0 0 4px;
}
.login-sub {
  text-align: center;
  font-size: 0.75rem; color: var(--t-3);
  margin: 0 0 28px;
}

.form-group { margin-bottom: 16px; }
.form-label {
  display: block;
  font-size: 0.77rem; font-weight: 600;
  color: var(--t-2); margin-bottom: 6px;
}
.input-wrap {
  display: flex; align-items: stretch;
  background: oklch(0.19 0.008 258);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  transition: border-color 0.16s, box-shadow 0.16s;
}
[data-bs-theme="light"] .input-wrap { background: oklch(0.96 0.003 258); }
.input-wrap:focus-within {
  border-color: var(--brand);
  box-shadow: 0 0 0 3px var(--brand-dim);
}
.input-prefix {
  display: flex; align-items: center;
  padding: 0 12px;
  color: var(--t-3); font-size: 0.85rem;
  flex-shrink: 0;
}
.input-wrap input {
  flex: 1; border: none; background: transparent;
  color: var(--t-1); font-size: 0.875rem;
  padding: 9px 10px 9px 0;
  outline: none;
  font-family: inherit;
}
.input-wrap input::placeholder { color: var(--t-3); }
.input-suffix {
  display: flex; align-items: center;
  padding: 0 10px;
  flex-shrink: 0;
}
.pwd-toggle {
  background: none; border: none;
  color: var(--t-3); cursor: pointer;
  font-size: 0.82rem; padding: 4px;
  border-radius: 5px;
  transition: color 0.14s;
}
.pwd-toggle:hover { color: var(--t-1); }

.error-msg {
  background: oklch(0.59 0.22 22 / 0.11);
  border: 1px solid oklch(0.59 0.22 22 / 0.30);
  color: oklch(0.74 0.15 22);
  border-radius: 9px;
  font-size: 0.82rem;
  padding: 8px 12px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 8px;
}

.btn-login {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  width: 100%;
  background: var(--brand); border: none;
  border-radius: 10px; padding: 11px 16px;
  color: #fff; font-weight: 600; font-size: 0.92rem;
  cursor: pointer; margin-top: 8px;
  transition: all 0.18s;
  font-family: inherit;
}
.btn-login:hover {
  background: oklch(0.64 0.20 22);
  box-shadow: 0 6px 20px var(--brand-glow);
  transform: translateY(-1px);
}
.btn-login:active { transform: translateY(0); }

.login-hint {
  text-align: center;
  font-size: 0.72rem; color: var(--t-3);
  margin: 20px 0 0;
  line-height: 1.6;
}

/* Theme toggle */
.theme-btn {
  position: fixed; top: 18px; right: 18px;
  width: 36px; height: 36px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px; color: var(--t-2);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 0.85rem;
  transition: all 0.15s;
  z-index: 10;
}
.theme-btn:hover { color: var(--t-1); border-color: oklch(0.35 0.010 258); }
</style>
</head>
<body>

<button class="theme-btn" onclick="toggleTheme()" title="切换主题">
  <i class="fas fa-moon" id="themeIcon"></i>
</button>

<div class="login-wrap">
  <div class="login-card">
    <div class="login-icon"><i class="fas fa-dragon"></i></div>
    <h2 class="login-title"><?php echo APP_NAME; ?></h2>
    <p class="login-sub">v<?php echo APP_VERSION; ?> &nbsp;·&nbsp; 仅限管理员访问</p>

    <?php if ($error): ?>
    <div class="error-msg">
      <i class="fas fa-circle-exclamation"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <div class="form-group">
        <label class="form-label">QQ 号</label>
        <div class="input-wrap">
          <span class="input-prefix"><i class="fas fa-user"></i></span>
          <input type="text" name="user_id" placeholder="请输入 QQ 号"
                 autofocus required value="<?php echo htmlspecialchars($_POST['user_id'] ?? ''); ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">密码</label>
        <div class="input-wrap">
          <span class="input-prefix"><i class="fas fa-lock"></i></span>
          <input type="password" name="password" placeholder="请输入密码" required id="pwdInput">
          <span class="input-suffix">
            <button type="button" class="pwd-toggle" onclick="togglePwd()" title="显示/隐藏密码">
              <i class="fas fa-eye" id="pwdEye"></i>
            </button>
          </span>
        </div>
      </div>
      <button type="submit" class="btn-login">
        <i class="fas fa-right-to-bracket"></i>进入后台
      </button>
    </form>

    <p class="login-hint">
      <i class="fas fa-circle-info me-1"></i>首次登录时，您输入的密码将成为登录密码（至少 6 位）
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleTheme() {
  var html = document.documentElement;
  var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-bs-theme', next);
  localStorage.setItem('fl_theme', next);
  document.getElementById('themeIcon').className = next === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
}
function togglePwd() {
  var inp = document.getElementById('pwdInput');
  var eye = document.getElementById('pwdEye');
  inp.type = inp.type === 'password' ? 'text' : 'password';
  eye.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}
(function(){
  var t = localStorage.getItem('fl_theme') || 'dark';
  document.getElementById('themeIcon').className = t === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
})();
</script>
</body>
</html>
