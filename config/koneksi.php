<?php
/**
 * Konfigurasi Koneksi Database MySQLi & Helper Sistem
 * Aplikasi: Sistem Inventory Cooler City - Icecream & Tea
 */

// ==============================================================
// DETEKSI OTOMATIS: LOKAL (XAMPP) ATAU CLOUD (INFINITYFREE)
// ==============================================================
$http_host = $_SERVER['HTTP_HOST'] ?? '';
$is_local  = (
    empty($http_host) ||
    $http_host === 'localhost' ||
    $http_host === '127.0.0.1' ||
    strpos($http_host, 'localhost:') === 0 ||
    strpos($http_host, '127.0.0.1:') === 0
);

if ($is_local) {
    // 1. Kredensial untuk XAMPP Lokal di Komputer Anda
    $db_host = '127.0.0.1';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'db_inventory_coolercity';
} else {
    // 2. Kredensial untuk Hosting InfinityFree (Online)
    $db_host = 'sql204.infinityfree.com';
    $db_user = 'if0_43439046';
    $db_pass = 'Paju12345';
    $db_name = 'if0_43439046_db_inventory_coolercity';
}

// Menonaktifkan laporan exception fatal mysqli agar dapat ditangani secara manual
mysqli_report(MYSQLI_REPORT_OFF);

// Inisialisasi koneksi MySQLi
$koneksi = @new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($is_local && $koneksi->connect_error) {
    $db_host = 'localhost';
    $koneksi = @new mysqli($db_host, $db_user, $db_pass, $db_name);
}

// Penanganan error koneksi yang rapi
if ($koneksi->connect_error) {
    $error_msg = $koneksi->connect_error;
    $error_no = $koneksi->connect_errno;
    
    // Tampilan pesan error yang ramah dan informatif
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Koneksi Database Gagal - Cooler City</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: 'Inter', sans-serif; background-color: #F3F4F6; color: #1E293B; }
            .card-error { max-width: 540px; border: none; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.06); }
            .btn-cyan { background-color: #00B4D8; color: #fff; font-weight: 600; border-radius: 8px; }
            .btn-cyan:hover { background-color: #0096B4; color: #fff; }
        </style>
    </head>
    <body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
        <div class="card card-error bg-white p-4 p-md-5 text-center">
            <div class="mb-3">
                <span class="badge bg-danger-subtle text-danger px-3 py-2 rounded-pill fs-6">Gagal Terhubung ke Database</span>
            </div>
            <h4 class="fw-bold mb-2 text-dark">Koneksi Database Terputus</h4>
            <p class="text-secondary small mb-4">
                Sistem tidak dapat terhubung ke MySQL database <strong><?= htmlspecialchars($db_name) ?></strong> pada <strong><?= htmlspecialchars($db_host) ?></strong>.
            </p>
            <div class="alert alert-light border text-start small text-muted mb-4 font-monospace">
                <div><strong>Kode Error:</strong> <?= $error_no ?></div>
                <div><strong>Keterangan:</strong> <?= htmlspecialchars($error_msg) ?></div>
            </div>
            <div class="card bg-light border-0 p-3 text-start small mb-4">
                <strong class="mb-1 text-dark">Langkah Penyelesaian:</strong>
                <ol class="ps-3 mb-0 text-secondary">
                    <li>Pastikan module <strong>MySQL</strong> pada <strong>XAMPP Control Panel</strong> sudah berstatus <em>Running</em>.</li>
                    <li>Pastikan database <code><?= htmlspecialchars($db_name) ?></code> telah dibuat atau impor skrip <code>database.sql</code> via phpMyAdmin.</li>
                </ol>
            </div>
            <a href="javascript:location.reload()" class="btn btn-cyan py-2">Coba Muat Ulang Halaman</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Set karakter encoding ke utf8mb4 untuk kompatibilitas penuh
$koneksi->set_charset("utf8mb4");

// Inisialisasi Session jika belum berjalan
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Helper: Menghasilkan URL absolut dinamis proyek
 * Kompatibel secara otomatis untuk XAMPP lokal maupun hosting (InfinityFree, cPanel, VPS)
 */
if (!function_exists('base_url')) {
    function base_url($path = '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Deteksi subfolder relatif terhadap DOCUMENT_ROOT secara presisi
        $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $app_root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        
        $base_folder = '';
        if (!empty($doc_root) && strpos($app_root, $doc_root) === 0) {
            $base_folder = substr($app_root, strlen($doc_root));
        }
        
        $base = $protocol . $host . $base_folder;
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

/**
 * Helper: Proteksi autentikasi halaman
 * Mencegah akses ke halaman internal tanpa login
 */
if (!function_exists('check_login')) {
    function check_login() {
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            header('Location: ' . base_url('login.php'));
            exit;
        }
    }
}

/**
 * Helper: Format mata uang Rupiah (IDR)
 */
if (!function_exists('format_rupiah')) {
    function format_rupiah($angka) {
        return 'Rp ' . number_format((float)$angka, 0, ',', '.');
    }
}

/**
 * Helper: Format tanggal bahasa Indonesia
 */
if (!function_exists('tanggal_indo')) {
    function tanggal_indo($tanggal) {
        if (!$tanggal || $tanggal == '0000-00-00') return '-';
        $bulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];
        $split = explode('-', $tanggal);
        if (count($split) === 3) {
            return (int)$split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
        }
        return $tanggal;
    }
}

/**
 * Helper: Hitung status stok berdasarkan aturan bisnis
 */
if (!function_exists('hitung_status_stok')) {
    function hitung_status_stok($stok, $stok_minimum = 10) {
        $stok = (int)$stok;
        $stok_minimum = (int)$stok_minimum;
        if ($stok <= 0) {
            return 'Habis';
        } elseif ($stok <= $stok_minimum) {
            return 'Menipis';
        } else {
            return 'Aman';
        }
    }
}

