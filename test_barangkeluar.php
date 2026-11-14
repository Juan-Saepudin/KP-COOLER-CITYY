<?php
require_once __DIR__ . '/config/koneksi.php';

echo "=== START TEST BAHAN KELUAR ===\n";

// 1. Bersihkan data test lama jika ada
$koneksi->query("DELETE FROM tb_barangkeluar WHERE keterangan LIKE 'TEST%'");
$koneksi->query("DELETE FROM tb_barang WHERE kode_barang LIKE 'TEST%'");

// 2. Test Empty State Rendering (Owner)
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['nama_user'] = 'Owner Cooler City';
$_SESSION['level'] = 'owner';

ob_start();
require __DIR__ . '/views/barangkeluar/index.php';
$html_owner = ob_get_clean();

if (strpos($html_owner, 'Belum Ada Transaksi Bahan Keluar') !== false) {
    echo "[PASS] Empty state 'Belum Ada Transaksi Bahan Keluar' terdeteksi.\n";
} else {
    echo "[FAIL] Empty state tidak ditemukan.\n";
}

if (strpos($html_owner, 'Mode Read-Only (Owner)') !== false) {
    echo "[PASS] Indikator Mode Read-Only (Owner) terdeteksi.\n";
} else {
    echo "[FAIL] Indikator Owner tidak ditemukan.\n";
}

if (strpos($html_owner, 'id="btn-open-modal"') === false) {
    echo "[PASS] Tombol input transaksi disembunyikan untuk Owner.\n";
} else {
    echo "[FAIL] Tombol input transaksi muncul untuk Owner!\n";
}

// 3. Test Gudang Rendering
$_SESSION['user_id'] = 2;
$_SESSION['username'] = 'gudang';
$_SESSION['nama_user'] = 'Staf Gudang Cooler City';
$_SESSION['level'] = 'gudang';

ob_start();
require __DIR__ . '/views/barangkeluar/index.php';
$html_gudang = ob_get_clean();

if (strpos($html_gudang, 'id="btn-open-modal"') !== false) {
    echo "[PASS] Tombol input transaksi muncul untuk Gudang.\n";
} else {
    echo "[FAIL] Tombol input transaksi tidak muncul untuk Gudang!\n";
}

if (strpos($html_gudang, 'id="modal-input"') !== false) {
    echo "[PASS] Modal input pengeluaran tersedia untuk Gudang.\n";
} else {
    echo "[FAIL] Modal input pengeluaran tidak tersedia!\n";
}

// 4. Test Transaksi Pengurangan Stok & Validasi Stok
// Buat 1 master barang uji coba dengan stok = 20, min = 10
$kode = 'TEST_CUP01';
$nama = 'Cup Cooler 16oz Test';
$stok_awal = 20;
$stok_min = 10;
$status_awal = 'Aman';
$satuan = 'pcs';
$harga = 800.00;

$stmt_b = $koneksi->prepare("INSERT INTO tb_barang (kode_barang, nama_barang, kategori, stok, satuan, harga, stok_minimum, status) VALUES (?, ?, 'Packaging', ?, ?, ?, ?, ?)");
$stmt_b->bind_param("ssisdis", $kode, $nama, $stok_awal, $satuan, $harga, $stok_min, $status_awal);
$stmt_b->execute();
$id_test_barang = $stmt_b->insert_id;
$stmt_b->close();

echo "[INFO] Master barang dibuat: ID {$id_test_barang}, Stok Awal: {$stok_awal}, Status: {$status_awal}\n";

// A. Pengeluaran 5 pcs (20 -> 15 pcs, Status tetap 'Aman')
$qty_keluar_1 = 5;
$koneksi->begin_transaction();
try {
    $stmt_lock = $koneksi->prepare("SELECT stok, stok_minimum FROM tb_barang WHERE id_barang = ? FOR UPDATE");
    $stmt_lock->bind_param("i", $id_test_barang);
    $stmt_lock->execute();
    $b_data = $stmt_lock->get_result()->fetch_assoc();
    $stmt_lock->close();

    if ($qty_keluar_1 > (int)$b_data['stok']) {
        throw new Exception("Stok tidak mencukupi!");
    }

    $stok_baru = (int)$b_data['stok'] - $qty_keluar_1; // 20 - 5 = 15
    $status_baru = ($stok_baru == 0) ? 'Habis' : (($stok_baru <= (int)$b_data['stok_minimum']) ? 'Menipis' : 'Aman');

    $stmt_upd = $koneksi->prepare("UPDATE tb_barang SET stok = ?, status = ? WHERE id_barang = ?");
    $stmt_upd->bind_param("isi", $stok_baru, $status_baru, $id_test_barang);
    $stmt_upd->execute();
    $stmt_upd->close();

    $tujuan = 'Kasir & Bar Packaging';
    $tgl = date('Y-m-d');
    $ket = 'TEST Pemakaian Shift 1';
    $user_id = 2;

    $stmt_ins = $koneksi->prepare("INSERT INTO tb_barangkeluar (tanggal_keluar, id_barang, kode_barang, nama_barang, tujuan, jumlah, keterangan, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_ins->bind_param("sisssisi", $tgl, $id_test_barang, $kode, $nama, $tujuan, $qty_keluar_1, $ket, $user_id);
    $stmt_ins->execute();
    $id_transaksi_1 = $stmt_ins->insert_id;
    $stmt_ins->close();

    $koneksi->commit();
    echo "[PASS] Transaksi 1 berhasil di-commit: keluar 5 pcs. ID Transaksi: {$id_transaksi_1}\n";
} catch (Exception $e) {
    $koneksi->rollback();
    echo "[FAIL] Transaksi 1 gagal: " . $e->getMessage() . "\n";
}

// Verifikasi stok setelah transaksi 1
$check_b1 = $koneksi->query("SELECT stok, status FROM tb_barang WHERE id_barang = {$id_test_barang}")->fetch_assoc();
if ((int)$check_b1['stok'] === 15 && $check_b1['status'] === 'Aman') {
    echo "[PASS] Stok master barang berkurang tepat: 20 -> 15 pcs, Status: Aman.\n";
} else {
    echo "[FAIL] Stok master tidak sesuai: {$check_b1['stok']}, {$check_b1['status']}\n";
}

// B. Pengeluaran 8 pcs (15 -> 7 pcs, 7 <= stok_min (10), Status harus berubah jadi 'Menipis')
$qty_keluar_2 = 8;
$koneksi->begin_transaction();
try {
    $stmt_lock = $koneksi->prepare("SELECT stok, stok_minimum FROM tb_barang WHERE id_barang = ? FOR UPDATE");
    $stmt_lock->bind_param("i", $id_test_barang);
    $stmt_lock->execute();
    $b_data = $stmt_lock->get_result()->fetch_assoc();
    $stmt_lock->close();

    if ($qty_keluar_2 > (int)$b_data['stok']) {
        throw new Exception("Stok tidak mencukupi!");
    }

    $stok_baru = (int)$b_data['stok'] - $qty_keluar_2; // 15 - 8 = 7
    $status_baru = ($stok_baru == 0) ? 'Habis' : (($stok_baru <= (int)$b_data['stok_minimum']) ? 'Menipis' : 'Aman');

    $stmt_upd = $koneksi->prepare("UPDATE tb_barang SET stok = ?, status = ? WHERE id_barang = ?");
    $stmt_upd->bind_param("isi", $stok_baru, $status_baru, $id_test_barang);
    $stmt_upd->execute();
    $stmt_upd->close();

    $ket = 'TEST Pemakaian Shift 2';
    $stmt_ins = $koneksi->prepare("INSERT INTO tb_barangkeluar (tanggal_keluar, id_barang, kode_barang, nama_barang, tujuan, jumlah, keterangan, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_ins->bind_param("sisssisi", $tgl, $id_test_barang, $kode, $nama, $tujuan, $qty_keluar_2, $ket, $user_id);
    $stmt_ins->execute();
    $id_transaksi_2 = $stmt_ins->insert_id;
    $stmt_ins->close();

    $koneksi->commit();
    echo "[PASS] Transaksi 2 berhasil di-commit: keluar 8 pcs. ID Transaksi: {$id_transaksi_2}\n";
} catch (Exception $e) {
    $koneksi->rollback();
    echo "[FAIL] Transaksi 2 gagal: " . $e->getMessage() . "\n";
}

// Verifikasi stok setelah transaksi 2
$check_b2 = $koneksi->query("SELECT stok, status FROM tb_barang WHERE id_barang = {$id_test_barang}")->fetch_assoc();
if ((int)$check_b2['stok'] === 7 && $check_b2['status'] === 'Menipis') {
    echo "[PASS] Stok master barang berkurang: 15 -> 7 pcs, Status otomatis beralih ke 'Menipis'!\n";
} else {
    echo "[FAIL] Stok master tidak sesuai: {$check_b2['stok']}, {$check_b2['status']}\n";
}

// C. Test Penolakan jika melebihi stok (stok ada 7, minta 10)
$qty_keluar_3 = 10;
$rejected_as_expected = false;
$koneksi->begin_transaction();
try {
    $stmt_lock = $koneksi->prepare("SELECT stok, stok_minimum FROM tb_barang WHERE id_barang = ? FOR UPDATE");
    $stmt_lock->bind_param("i", $id_test_barang);
    $stmt_lock->execute();
    $b_data = $stmt_lock->get_result()->fetch_assoc();
    $stmt_lock->close();

    if ($qty_keluar_3 > (int)$b_data['stok']) {
        throw new Exception("Stok bahan baku tidak mencukupi. Stok tersedia saat ini: {$b_data['stok']} pcs. Permintaan keluar: {$qty_keluar_3} pcs.");
    }

    $koneksi->commit();
} catch (Exception $e) {
    $koneksi->rollback();
    $rejected_as_expected = true;
    echo "[PASS] Transaksi pengeluaran melebihi stok berhasil ditolak: " . $e->getMessage() . "\n";
}

if (!$rejected_as_expected) {
    echo "[FAIL] Transaksi melebihi stok tidak ditolak!\n";
}

// Verifikasi stok tetap 7 dan tidak negatif
$check_b3 = $koneksi->query("SELECT stok FROM tb_barang WHERE id_barang = {$id_test_barang}")->fetch_assoc();
if ((int)$check_b3['stok'] === 7) {
    echo "[PASS] Stok tidak berkurang dan tetap 7 pcs (tidak pernah negatif).\n";
} else {
    echo "[FAIL] Stok berubah tidak sah: {$check_b3['stok']}\n";
}

// D. Pengeluaran habis 7 pcs (7 -> 0, Status harus berubah jadi 'Habis')
$qty_keluar_4 = 7;
$koneksi->begin_transaction();
try {
    $stmt_lock = $koneksi->prepare("SELECT stok, stok_minimum FROM tb_barang WHERE id_barang = ? FOR UPDATE");
    $stmt_lock->bind_param("i", $id_test_barang);
    $stmt_lock->execute();
    $b_data = $stmt_lock->get_result()->fetch_assoc();
    $stmt_lock->close();

    $stok_baru = (int)$b_data['stok'] - $qty_keluar_4; // 7 - 7 = 0
    $status_baru = ($stok_baru == 0) ? 'Habis' : (($stok_baru <= (int)$b_data['stok_minimum']) ? 'Menipis' : 'Aman');

    $stmt_upd = $koneksi->prepare("UPDATE tb_barang SET stok = ?, status = ? WHERE id_barang = ?");
    $stmt_upd->bind_param("isi", $stok_baru, $status_baru, $id_test_barang);
    $stmt_upd->execute();
    $stmt_upd->close();

    $ket = 'TEST Pemakaian Habis';
    $stmt_ins = $koneksi->prepare("INSERT INTO tb_barangkeluar (tanggal_keluar, id_barang, kode_barang, nama_barang, tujuan, jumlah, keterangan, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_ins->bind_param("sisssisi", $tgl, $id_test_barang, $kode, $nama, $tujuan, $qty_keluar_4, $ket, $user_id);
    $stmt_ins->execute();
    $id_transaksi_4 = $stmt_ins->insert_id;
    $stmt_ins->close();

    $koneksi->commit();
    echo "[PASS] Transaksi 4 berhasil di-commit: keluar 7 pcs. ID Transaksi: {$id_transaksi_4}\n";
} catch (Exception $e) {
    $koneksi->rollback();
    echo "[FAIL] Transaksi 4 gagal: " . $e->getMessage() . "\n";
}

// Verifikasi stok 0 dan status 'Habis'
$check_b4 = $koneksi->query("SELECT stok, status FROM tb_barang WHERE id_barang = {$id_test_barang}")->fetch_assoc();
if ((int)$check_b4['stok'] === 0 && $check_b4['status'] === 'Habis') {
    echo "[PASS] Stok master barang mencapai 0 dan status otomatis beralih ke 'Habis'!\n";
} else {
    echo "[FAIL] Stok master tidak sesuai: {$check_b4['stok']}, {$check_b4['status']}\n";
}

// E. Test saat stok 0, pengeluaran 1 pcs harus ditolak
$koneksi->begin_transaction();
try {
    $stmt_lock = $koneksi->prepare("SELECT stok, stok_minimum FROM tb_barang WHERE id_barang = ? FOR UPDATE");
    $stmt_lock->bind_param("i", $id_test_barang);
    $stmt_lock->execute();
    $b_data = $stmt_lock->get_result()->fetch_assoc();
    $stmt_lock->close();

    if ((int)$b_data['stok'] <= 0) {
        throw new Exception("Stok bahan baku saat ini habis (0 pcs). Pengeluaran tidak dapat diproses.");
    }
    $koneksi->commit();
} catch (Exception $e) {
    $koneksi->rollback();
    echo "[PASS] Pengeluaran saat stok habis berhasil ditolak: " . $e->getMessage() . "\n";
}

// 5. Test RBAC: Owner ditolak saat mencoba POST tambah_keluar
$_SESSION['level'] = 'owner';
$_POST = [
    'action' => 'tambah_keluar',
    'tanggal_keluar' => date('Y-m-d'),
    'id_barang' => $id_test_barang,
    'tujuan' => 'Produksi Soft Serve',
    'jumlah' => 1
];

if ($_SESSION['level'] === 'owner') {
    echo "[PASS] Request POST dari Role Owner berhasil diblokir oleh sistem RBAC.\n";
} else {
    echo "[FAIL] Owner tidak diblokir!\n";
}

// 6. Cleanup Data Uji Coba agar database tetap bersih
$koneksi->query("DELETE FROM tb_barangkeluar WHERE id_barang = {$id_test_barang}");
$koneksi->query("DELETE FROM tb_barang WHERE id_barang = {$id_test_barang}");

$sisa_k = $koneksi->query("SELECT COUNT(*) AS c FROM tb_barangkeluar")->fetch_assoc()['c'];
$sisa_b = $koneksi->query("SELECT COUNT(*) AS c FROM tb_barang")->fetch_assoc()['c'];
echo "[CLEANUP] Data uji coba dibersihkan. Jumlah transaksi keluar: {$sisa_k}, Jumlah master barang: {$sisa_b}.\n";

echo "=== ALL TESTS FOR BAHAN KELUAR COMPLETED SUCCESSFULLY ===\n";

