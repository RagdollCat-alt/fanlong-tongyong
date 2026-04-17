<?php
require_once 'config.php';

// 必须已有登录会话（哪怕是未完成的首次登录）
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php'); exit();
}

$force   = isset($_GET['force']);   // 首次登录强制设密码
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pwd     = $_POST['new_password']     ?? '';
    $confirm_pwd = $_POST['confirm_password'] ?? '';
    $old_pwd     = $_POST['old_password']     ?? '';

    if (strlen($new_pwd) < 8) {
        $error = '新密码至少 8 位';
    } elseif ($new_pwd !== $confirm_pwd) {
        $error = '两次输入的密码不一致';
    } else {
        try {
            $db    = getDB();
            $admin = $db->prepare("SELECT password_hash FROM admins WHERE user_id=?")->execute([$_SESSION['admin_id']]);
            $row   = $db->prepare("SELECT password_hash FROM admins WHERE user_id=?");
            $row->execute([$_SESSION['admin_id']]);
            $row   = $row->fetch();

            // 非首次（已有密码），需要验证旧密码
            if (!$force && !empty($row['password_hash'])) {
                if (!password_verify($old_pwd, $row['password_hash'])) {
                    $error = '原密码错误';
                }
            }

            if (!$error) {
                $hash = password_hash($new_pwd, PASSWORD_DEFAULT);
                $db->prepare("UPDATE admins SET password_hash=? WHERE user_id=?")
                   ->execute([$hash, $_SESSION['admin_id']]);
                logAction('admins', 'change_password', $_SESSION['admin_id']);
                $_SESSION['password_set'] = true;
                $success = '密码设置成功！';
                if ($force) {
                    header('refresh:2;url=index.php');
                }
            }
        } catch (Exception $e) {
            $error = '操作失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo APP_NAME; ?> · <?php echo $force ? '设置密码' : '修改密码'; ?></title>
<script>(function(){var t=localStorage.getItem('fl_theme')||'dark';document.documentElement.setAttribute('data-bs-theme',t);})();</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --brand:      oklch(0.59 0.22 22);
  --brand-glow: oklch(0.59 0.22 22 / 0.28);
  --brand-dim:  oklch(0.59 0.22 22 / 0.13);
}
[data-bs-theme="dark"] {
  --bg:      oklch(0.11 0.005 258);
  --surface: oklch(0.15 0.007 258);
  --border:  oklch(0.22 0.008 258);
  --input:   oklch(0.19 0.008 258);
  --t-1:     oklch(0.92 0.004 258);
  --t-2:     oklch(0.55 0.006 258);
  --t-3:     oklch(0.36 0.005 258);
}
[data-bs-theme="light"] {
  --bg:      oklch(0.965 0.003 258);
  --surface: oklch(1.00 0.000 0);
  --border:  oklch(0.87 0.005 258);
  --input:   oklch(0.96 0.003 258);
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
  font-size: 14px; -webkit-font-smoothing: antialiased;
}
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image:
    linear-gradient(oklch(0.59 0.22 22 / 0.03) 1px, transparent 1px),
    linear-gradient(90deg, oklch(0.59 0.22 22 / 0.03) 1px, transparent 1px);
  background-size: 48px 48px;
  pointer-events: none;
}
.wrap { position:relative;z-index:1; width:100%;max-width:420px;padding:16px; }
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 18px;
  padding: 38px 34px 30px;
  box-shadow: 0 20px 56px oklch(0 0 0 / 0.38);
  animation: card-in 0.32s cubic-bezier(0.4,0,0.2,1) both;
}
@keyframes card-in {
  from { opacity:0; transform:translateY(18px) scale(0.98); }
  to   { opacity:1; transform:translateY(0) scale(1); }
}
.brand-icon {
  width:56px;height:56px;background:var(--brand);border-radius:14px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.5rem;color:#fff;margin:0 auto 18px;
  box-shadow:0 6px 22px var(--brand-glow);
}
h3 {
  text-align:center;font-size:1.1rem;font-weight:700;
  color:var(--t-1);margin:0 0 4px;
}
.sub { text-align:center;font-size:0.74rem;color:var(--t-3);margin:0 0 24px; }

.form-group { margin-bottom:14px; }
.form-label { display:block;font-size:.77rem;font-weight:600;color:var(--t-2);margin-bottom:5px; }

.fl-input {
  width:100%; background:var(--input);
  border:1px solid var(--border); border-radius:9px;
  color:var(--t-1); font-size:.875rem;
  padding:8px 12px; outline:none;
  font-family:inherit;
  transition:border-color .15s, box-shadow .15s;
}
.fl-input:focus {
  border-color:var(--brand);
  box-shadow:0 0 0 3px var(--brand-dim);
}
.fl-input::placeholder { color:var(--t-3); }

.msg {
  border-radius:9px;font-size:.82rem;padding:8px 12px;
  margin-bottom:14px;display:flex;align-items:center;gap:8px;
}
.msg-err {
  background:oklch(0.59 0.22 22 / 0.11);
  border:1px solid oklch(0.59 0.22 22 / 0.28);
  color:oklch(0.74 0.15 22);
}
.msg-ok {
  background:oklch(0.65 0.18 152 / 0.11);
  border:1px solid oklch(0.65 0.18 152 / 0.28);
  color:oklch(0.70 0.15 152);
}

.btn-submit {
  display:flex;align-items:center;justify-content:center;gap:8px;
  width:100%;background:var(--brand);border:none;
  border-radius:10px;padding:10px;
  color:#fff;font-weight:600;font-size:.89rem;
  cursor:pointer;margin-top:6px;
  transition:all .17s;font-family:inherit;
}
.btn-submit:hover {
  background:oklch(0.64 0.20 22);
  box-shadow:0 5px 18px var(--brand-glow);
  transform:translateY(-1px);
}
.btn-cancel {
  display:block;text-align:center;
  margin-top:10px;
  font-size:.80rem;color:var(--t-2);text-decoration:none;
  padding:6px;border-radius:8px;
  transition:color .14s;
}
.btn-cancel:hover { color:var(--t-1); }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="brand-icon"><i class="fas fa-key"></i></div>
    <h3><?php echo $force ? '首次登录 · 设置密码' : '修改密码'; ?></h3>
    <p class="sub">
      <?php echo $force
        ? '为保障账号安全，请立即设置专属密码'
        : '管理员 &nbsp;' . htmlspecialchars($_SESSION['admin_id']); ?>
    </p>

    <?php if ($error): ?>
    <div class="msg msg-err"><i class="fas fa-circle-exclamation"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="msg msg-ok">
      <i class="fas fa-circle-check"></i><?php echo $success; ?>
      <?php if ($force): ?><br><small style="opacity:.7">2 秒后跳转到仪表盘…</small><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST">
      <?php if (!$force && !empty($_SESSION['password_set'])): ?>
      <div class="form-group">
        <label class="form-label">原密码</label>
        <input type="password" class="fl-input" name="old_password" required placeholder="请输入当前密码">
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label class="form-label">新密码 <span style="color:var(--t-3);font-weight:400">(至少 8 位)</span></label>
        <input type="password" class="fl-input" name="new_password" required placeholder="请输入新密码" id="np">
      </div>
      <div class="form-group">
        <label class="form-label">确认新密码</label>
        <input type="password" class="fl-input" name="confirm_password" required placeholder="再次输入新密码" id="cp">
      </div>
      <button type="submit" class="btn-submit">
        <i class="fas fa-check"></i><?php echo $force ? '设置密码并进入系统' : '保存新密码'; ?>
      </button>
      <?php if (!$force): ?>
      <a href="index.php" class="btn-cancel">取消，返回仪表盘</a>
      <?php endif; ?>
    </form>
    <?php else: ?>
    <?php if (!$force): ?>
    <a href="index.php" class="btn-submit" style="text-decoration:none">
      <i class="fas fa-house"></i>返回仪表盘
    </a>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
