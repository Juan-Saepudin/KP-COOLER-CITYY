-- ==============================================================
-- DATABASE: db_inventory_coolercity
-- Aplikasi: Sistem Inventory Cooler City - Icecream & Tea
-- Dibuat berdasarkan PRD dan Panduan Arsitektur Sistem
-- ==============================================================

CREATE DATABASE IF NOT EXISTS `db_inventory_coolercity`
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `db_inventory_coolercity`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `tb_laporan`;
DROP TABLE IF EXISTS `tb_barangkeluar`;
DROP TABLE IF EXISTS `tb_barangmasuk`;
DROP TABLE IF EXISTS `tb_barang`;
DROP TABLE IF EXISTS `tb_user`;
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------------
-- 1. Tabel: tb_user
-- Menyimpan kredensial otentikasi & hak akses pengguna (Owner & Gudang)
-- --------------------------------------------------------------
CREATE TABLE `tb_user` (
  `id_user` INT(11) NOT NULL AUTO_INCREMENT,
  `nama_user` VARCHAR(100) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `level` ENUM('owner', 'gudang') NOT NULL DEFAULT 'gudang',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- Data Dummy Default untuk tb_user
-- 1. Akun: admin  | Password: admin123  (BCRYPT hash) | Level: owner
-- 2. Akun: gudang | Password: gudang123 (BCRYPT hash) | Level: gudang
-- --------------------------------------------------------------
INSERT INTO `tb_user` (`nama_user`, `username`, `password`, `level`) VALUES
('Owner Cooler City', 'admin', '$2y$10$39Eq8jcNcpqOnpTKGj79I.ZuSo1gkwAa70Bsk2Y2iMszP0eq/Pcn2', 'owner'),
('Staf Gudang Cooler City', 'gudang', '$2y$10$vkuTfef0iTvnQ86zCirrHuRWav49mYB0cti3gCglyESwEkVvZW87m', 'gudang');

-- --------------------------------------------------------------
-- 2. Tabel: tb_barang
-- Master data bahan baku (TANPA DATA DUMMY OTOMATIS)
-- Status stok dihitung berdasarkan logika:
-- - stok == 0                -> 'Habis'
-- - stok > 0 && stok <= stok_minimum -> 'Menipis'
-- - stok > stok_minimum       -> 'Aman'
-- --------------------------------------------------------------
CREATE TABLE `tb_barang` (
  `id_barang` INT(11) NOT NULL AUTO_INCREMENT,
  `kode_barang` VARCHAR(50) NOT NULL,
  `nama_barang` VARCHAR(150) NOT NULL,
  `kategori` VARCHAR(50) NOT NULL,
  `stok` INT(11) NOT NULL DEFAULT 0,
  `satuan` VARCHAR(30) NOT NULL,
  `harga` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `stok_minimum` INT(11) NOT NULL DEFAULT 10,
  `status` ENUM('Aman', 'Menipis', 'Habis') NOT NULL DEFAULT 'Habis',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_barang`),
  UNIQUE KEY `uk_kode_barang` (`kode_barang`),
  KEY `idx_kategori` (`kategori`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CATATAN: Sesuai aturan proyek (.cursorrules), tabel tb_barang 
-- TIDAK diisi data dummy instan saat inisialisasi database.

-- --------------------------------------------------------------
-- 3. Tabel: tb_barangmasuk
-- Riwayat transaksi penerimaan bahan baku dari supplier
-- Menambah stok pada tb_barang secara atomik
-- --------------------------------------------------------------
CREATE TABLE `tb_barangmasuk` (
  `id_masuk` INT(11) NOT NULL AUTO_INCREMENT,
  `tanggal_masuk` DATE NOT NULL,
  `id_barang` INT(11) NOT NULL,
  `kode_barang` VARCHAR(50) DEFAULT NULL,
  `nama_barang` VARCHAR(150) DEFAULT NULL,
  `supplier` VARCHAR(100) NOT NULL,
  `jumlah` INT(11) NOT NULL,
  `harga_beli` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `keterangan` TEXT DEFAULT NULL,
  `id_user` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_masuk`),
  KEY `fk_masuk_barang` (`id_barang`),
  KEY `fk_masuk_user` (`id_user`),
  KEY `idx_tanggal_masuk` (`tanggal_masuk`),
  CONSTRAINT `fk_masuk_barang` FOREIGN KEY (`id_barang`) REFERENCES `tb_barang` (`id_barang`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_masuk_user` FOREIGN KEY (`id_user`) REFERENCES `tb_user` (`id_user`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- 4. Tabel: tb_barangkeluar
-- Riwayat transaksi pengeluaran/pemakaian bahan baku untuk operasional
-- Mengurangi stok pada tb_barang (tidak boleh melebihi sisa stok)
-- --------------------------------------------------------------
CREATE TABLE `tb_barangkeluar` (
  `id_keluar` INT(11) NOT NULL AUTO_INCREMENT,
  `tanggal_keluar` DATE NOT NULL,
  `id_barang` INT(11) NOT NULL,
  `kode_barang` VARCHAR(50) DEFAULT NULL,
  `nama_barang` VARCHAR(150) DEFAULT NULL,
  `tujuan` VARCHAR(100) NOT NULL,
  `jumlah` INT(11) NOT NULL,
  `keterangan` TEXT DEFAULT NULL,
  `id_user` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_keluar`),
  KEY `fk_keluar_barang` (`id_barang`),
  KEY `fk_keluar_user` (`id_user`),
  KEY `idx_tanggal_keluar` (`tanggal_keluar`),
  CONSTRAINT `fk_keluar_barang` FOREIGN KEY (`id_barang`) REFERENCES `tb_barang` (`id_barang`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_keluar_user` FOREIGN KEY (`id_user`) REFERENCES `tb_user` (`id_user`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------
-- 5. Tabel: tb_laporan
-- Rekapitulasi berkala transaksi masuk, keluar, pembelian, penjualan & profit
-- --------------------------------------------------------------
CREATE TABLE `tb_laporan` (
  `id_laporan` INT(11) NOT NULL AUTO_INCREMENT,
  `periode` VARCHAR(50) NOT NULL,
  `total_masuk` INT(11) NOT NULL DEFAULT 0,
  `total_keluar` INT(11) NOT NULL DEFAULT 0,
  `nilai_pembelian` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `nilai_penjualan` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `profit` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
  `id_user` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_laporan`),
  KEY `fk_laporan_user` (`id_user`),
  CONSTRAINT `fk_laporan_user` FOREIGN KEY (`id_user`) REFERENCES `tb_user` (`id_user`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

