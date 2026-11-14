<?php
/**
 * Index Gateway - Sistem Inventory Cooler City
 * Mengarahkan pengguna ke login.php jika belum login,
 * atau ke dashboard.php jika telah memiliki sesi aktif.
 */

require_once __DIR__ . '/config/koneksi.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ' . base_url('login.php'));
    exit;
} else {
    header('Location: ' . base_url('dashboard.php'));
    exit;
}

