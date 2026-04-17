  </div><!-- /.page-body -->

  <footer class="text-center text-muted small py-4 border-top mx-4">
    © 2026 繁笼后台管理系统 v<?php echo APP_VERSION; ?> · <?php echo date('Y-m-d'); ?>
  </footer>
</div><!-- /#main-content -->

<!-- ===== Scripts ===== -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ===== 主题切换 =====
(function(){
  var t = localStorage.getItem('fl_theme') || 'light';
  var icon = document.getElementById('themeIcon');
  if(icon) icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
})();
document.getElementById('themeToggle')?.addEventListener('click', function(){
  var html = document.documentElement;
  var cur  = html.getAttribute('data-bs-theme');
  var next = cur === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-bs-theme', next);
  localStorage.setItem('fl_theme', next);
  document.getElementById('themeIcon').className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
});

// ===== 侧边栏折叠 =====
document.getElementById('sidebarToggle')?.addEventListener('click', function(){
  document.getElementById('sidebar').classList.toggle('collapsed');
  document.getElementById('main-content').classList.toggle('expanded');
  localStorage.setItem('fl_sidebar', document.getElementById('sidebar').classList.contains('collapsed') ? '1' : '0');
});
(function(){
  if(localStorage.getItem('fl_sidebar') === '1'){
    document.getElementById('sidebar')?.classList.add('collapsed');
    document.getElementById('main-content')?.classList.add('expanded');
  }
})();

// ===== DataTables 中文初始化 =====
$(document).ready(function(){
  $('.datatable').DataTable({
    language:{
      decimal:"",emptyTable:"暂无数据",
      info:"显示第 _START_ 至 _END_ 条，共 _TOTAL_ 条",
      infoEmpty:"共 0 条",infoFiltered:"(从 _MAX_ 条中筛选)",
      thousands:",",lengthMenu:"每页 _MENU_ 条",
      loadingRecords:"加载中...",processing:"处理中...",
      search:"搜索：",zeroRecords:"未找到匹配记录",
      paginate:{first:"首页",last:"末页",next:"下一页",previous:"上一页"}
    },
    pageLength:25, responsive:true, order:[]
  });
  // 全局 Select2（非 modal 内）
  $('.select2').not('.modal .select2').select2({ theme:'bootstrap-5', width:'100%' });
  // Modal 内 Select2：设 dropdownParent，使下拉渲染在 modal 内部，避免 Bootstrap 5 focus trap 拦截点击
  $(document).on('show.bs.modal', function(e){
    var $modal = $(e.target);
    $modal.find('.select2').each(function(){
      if($(this).hasClass('select2-hidden-accessible')){ $(this).select2('destroy'); }
      $(this).select2({ theme:'bootstrap-5', width:'100%', dropdownParent: $modal });
    });
  });
  // Flash 自动消失
  setTimeout(function(){ $('#flashMsg .alert').alert('close'); }, 4000);
});

// ===== 实时更新（每 30 秒刷新统计数据） =====
function refreshLiveData(){
  fetch('api/refresh.php')
    .then(r=>r.json())
    .then(data=>{
      document.getElementById('lastUpdate').textContent = data.time || '';
      // 仪表盘数字更新
      if(data.users      !== undefined && document.getElementById('stat-users'))      document.getElementById('stat-users').textContent      = data.users;
      if(data.items      !== undefined && document.getElementById('stat-items'))      document.getElementById('stat-items').textContent      = data.items;
      if(data.today_users!== undefined && document.getElementById('stat-today'))      document.getElementById('stat-today').textContent      = data.today_users;
      if(data.admins     !== undefined && document.getElementById('stat-admins'))     document.getElementById('stat-admins').textContent     = data.admins;
})
    .catch(()=>{});
}
setInterval(refreshLiveData, 30000);

// ===== 全局工具 =====
function confirmDelete(form, msg){
  if(confirm(msg || '确认删除？此操作不可撤销！')){ form.submit(); } return false;
}
function showToast(type, msg){
  var el = document.createElement('div');
  el.className = 'position-fixed top-0 end-0 p-3' ;
  el.style.zIndex = 9999;
  el.innerHTML = '<div class="toast show align-items-center text-bg-' + (type==='success'?'success':'danger') + ' border-0" role="alert">'
    +'<div class="d-flex"><div class="toast-body"><i class="fas fa-' + (type==='success'?'check':'times') + '-circle me-2"></i>' + msg + '</div>'
    +'<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
  document.body.appendChild(el);
  setTimeout(()=>el.remove(), 3500);
}
</script>
</body>
</html>
