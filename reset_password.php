<?php
/**
 * Script Helper: Reset Password Akun Sistem Inventory Cooler City
 * Buka file ini di browser (contoh: http://domain-anda/reset_password.php)
 * untuk memperbarui password akun 'gudang' dan 'admin' secara otomatis di database.
 */

require_once __DIR__ . '/config/koneksi.php';

// Generate hash bcrypt segar & valid
$hash_gudang = password_hash('gudang123', PASSWORD_BCRYPT);
$hash_admin  = password_hash('admin123', PASSWORD_BCRYPT);

// Update password pada tb_user
$update_gudang = $koneksi->query("UPDATE tb_user SET password = '$hash_gudang' WHERE username = 'gudang'");
$update_admin  = $koneksi->query("UPDATE tb_user SET password = '$hash_admin' WHERE username = 'admin'");

// Periksa apakah baris terpengaruh atau akun belum ada sama sekali
$check_gudang = $koneksi->query("SELECT id_user, nama_user, username, level FROM tb_user WHERE username = 'gudang'");
if ($check_gudang->num_rows === 0) {
    // Jika akun gudang belum ada di tabel, insert baru
    $koneksi->query("INSERT INTO tb_user (nama_user, username, password, level) VALUES ('Staf Gudang Cooler City', 'gudang', '$hash_gudang', 'gudang')");
}

$check_admin = $koneksi->query("SELECT id_user, nama_user, username, level FROM tb_user WHERE username = 'admin'");
if ($check_admin->num_rows === 0) {
    // Jika akun admin belum ada di tabel, insert baru
    $koneksi->query("INSERT INTO tb_user (nama_user, username, password, level) VALUES ('Owner Cooler City', 'admin', '$hash_admin', 'owner')");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password Berhasil - Cooler City</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-100 flex items-center justify-center min-vh-100 min-h-screen p-4">
  <div class="bg-white rounded-2xl shadow-lg p-8 max-w-md w-full border border-slate-200 text-center">
    <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl font-bold">
      ✓
    </div>
    
    <h2 class="text-xl font-bold text-slate-800 mb-1">Password Berhasil Diperbarui!</h2>
    <p class="text-sm text-slate-500 mb-6">Database hosting Anda telah disinkronkan dengan password yang valid.</p>
    
    <div class="bg-slate-50 rounded-xl p-4 text-left border border-slate-200 mb-6 space-y-3">
      <div class="flex items-center justify-between text-sm pb-2 border-b border-slate-200">
        <div>
          <span class="block font-semibold text-slate-700">Akun Staf Gudang</span>
          <span class="text-xs text-slate-500">Username: <strong class="text-slate-800">gudang</strong></span>
        </div>
        <span class="bg-emerald-100 text-emerald-800 text-xs px-2.5 py-1 rounded font-mono font-bold">gudang123</span>
      </div>
      
      <div class="flex items-center justify-between text-sm">
        <div>
          <span class="block font-semibold text-slate-700">Akun Owner</span>
          <span class="text-xs text-slate-500">Username: <strong class="text-slate-800">admin</strong></span>
        </div>
        <span class="bg-emerald-100 text-emerald-800 text-xs px-2.5 py-1 rounded font-mono font-bold">admin123</span>
      </div>
    </div>
    
    <a href="login.php" class="block w-full py-3 bg-[#00b4d8] hover:bg-[#0096b4] text-white font-semibold rounded-xl transition-all shadow-md">
      Masuk ke Halaman Login &rarr;
    </a>
  </div>
</body>
</html>
