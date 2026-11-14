<?php
/**
 * Komponen Footer - Sistem Inventory Cooler City
 * Desain 100% Mengacu pada Google Stitch AI
 */
?>
</div> <!-- Akhir wrapper lg:pl-64 -->

<script>
document.addEventListener('DOMContentLoaded', function () {
  // 1. Sidebar Toggle untuk Layar Mobile
  const sidebar = document.getElementById('appSidebar');
  const toggleBtn = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');
  const closeBtn = document.getElementById('sidebarCloseBtn');

  function openSidebar() {
    if (sidebar) sidebar.classList.remove('-translate-x-full');
    if (backdrop) backdrop.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (sidebar) sidebar.classList.add('-translate-x-full');
    if (backdrop) backdrop.classList.add('hidden');
    document.body.style.overflow = '';
  }

  if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
  if (backdrop) backdrop.addEventListener('click', closeSidebar);
  if (closeBtn) closeBtn.addEventListener('click', closeSidebar);

  // 2. User Menu Dropdown Toggle
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userDropdown = document.getElementById('userDropdown');
  if (userMenuBtn && userDropdown) {
    userMenuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      userDropdown.classList.toggle('hidden');
    });

    document.addEventListener('click', function () {
      userDropdown.classList.add('hidden');
    });
  }
});
</script>
</body>
</html>
