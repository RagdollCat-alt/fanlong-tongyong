<?php if (!defined('APP_NAME')) { require_once 'config.php'; checkLogin(); } ?>
<div class="d-flex flex-column align-items-center justify-content-center py-5 my-5 text-center">
  <div style="width:72px;height:72px;background:oklch(0.59 0.22 22 / 0.12);border-radius:18px;
              display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
    <i class="fas fa-lock" style="font-size:1.8rem;color:var(--brand)"></i>
  </div>
  <h2 style="font-size:1.3rem;font-weight:700;color:var(--t-1);margin:0 0 8px">权限不足</h2>
  <p style="color:var(--t-2);font-size:.87rem;margin:0 0 24px;max-width:320px">
    您没有执行此操作的权限，请联系超级管理员。
  </p>
  <a href="index.php" class="btn btn-primary px-4">
    <i class="fas fa-house me-2"></i>返回仪表盘
  </a>
</div>
