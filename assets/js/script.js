/**
 * Javascript Utama - Sistem Inventory Cooler City
 */

document.addEventListener('DOMContentLoaded', function () {
  // 1. Mobile Sidebar Toggle
  const sidebar = document.getElementById('appSidebar');
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');

  function openSidebar() {
    if (sidebar) sidebar.classList.add('show');
    if (sidebarBackdrop) sidebarBackdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('show');
    if (sidebarBackdrop) sidebarBackdrop.classList.remove('show');
    document.body.style.overflow = '';
  }

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function (e) {
      e.preventDefault();
      if (sidebar && sidebar.classList.contains('show')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });
  }

  if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeSidebar);
  }

  // 2. Toggle Password Visibility (Login & Profile Forms)
  const togglePasswordBtns = document.querySelectorAll('.btn-toggle-password');
  togglePasswordBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      const targetId = this.getAttribute('data-target') || 'password';
      const targetInput = document.getElementById(targetId);
      const icon = this.querySelector('.material-symbols-outlined');

      if (targetInput) {
        if (targetInput.type === 'password') {
          targetInput.type = 'text';
          if (icon) icon.textContent = 'visibility';
        } else {
          targetInput.type = 'password';
          if (icon) icon.textContent = 'visibility_off';
        }
      }
    });
  });

  // 3. Auto-dismiss Alert Messages after 5 seconds
  const autoAlerts = document.querySelectorAll('.alert-dismiss-auto');
  autoAlerts.forEach(function (alert) {
    setTimeout(function () {
      try {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
      } catch (e) {
        alert.remove();
      }
    }, 5000);
  });
});

