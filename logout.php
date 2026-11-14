<?php
/**
 * Logout Handler - Sistem Inventory Cooler City
 * Menghancurkan session dan mengarahkan kembali ke halaman login
 */

require_once __DIR__ . '/config/koneksi.php';

// Kosongkan array session
$_SESSION = [];

// Hapus cookie session jika ada
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Hancurkan session
session_destroy();

// Alihkan pengguna ke login.php
header('Location: ' . base_url('login.php'));
exit;

