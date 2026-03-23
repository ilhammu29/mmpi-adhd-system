<div align="center">

# 🧠 MMPI & ADHD Assessment System

**Platform Web Psikotes Profesional** untuk penilaian MMPI (*Minnesota Multiphasic Personality Inventory*) dan ADHD (*Attention-Deficit/Hyperactivity Disorder*) dengan skoring otomatis, manajemen klien terpadu, dan pembuatan laporan instan.

[![Status](https://img.shields.io/badge/Status-Development-orange?style=for-the-badge)](#)
[![Version](https://img.shields.io/badge/Version-1.0.0-success?style=for-the-badge)](#)
[![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)](#)
[![MySQL](https://img.shields.io/badge/MySQL-005C84?style=for-the-badge&logo=mysql&logoColor=white)](#)
[![Bootstrap](https://img.shields.io/badge/Bootstrap_5-563D7C?style=for-the-badge&logo=bootstrap&logoColor=white)](#)

<br/>

</div>

---

## 📖 Daftar Isi
1. [Tentang Sistem](#-tentang-sistem)
2. [Fitur Utama](#-fitur-utama)
3. [Dokumentasi Antarmuka](#-dokumentasi-antarmuka)
4. [Persyaratan Sistem](#-persyaratan-sistem)
5. [Teknologi Pendukung](#️-teknologi-pendukung)
6. [Struktur Direktori](#-struktur-direktori)
7. [Panduan Instalasi](#-panduan-instalasi)
8. [Keamanan Sistem](#-keamanan-sistem)
9. [Lisensi & Kontak](#-lisensi--kontak)

---

## 💡 Tentang Sistem

**MMPI & ADHD Assessment System** adalah solusi digital end-to-end yang dirancang khusus untuk psikolog, instansi pendidikan, dan klinik kesehatan mental. Sistem ini mendigitalisasi proses tes psikologi yang panjang dan rumit menjadi pengalaman yang *seamless* bagi peserta, sekaligus memberikan alat skoring otomatis yang akurat berdasarkan norma tes MMPI-2 dan instrumen ADHD bagi administrator.

---

## 🌟 Fitur Utama

### 🛡️ Portal Administrator (Super Admin & Psikolog)
* 📊 **Dashboard Analitik Terpusat:** Memantau metrik kunci seperti total klien aktif, tes yang sedang berlangsung, pendapatan paket, dan grafik pendaftaran bulanan.
* 👥 **Manajemen Klien Komprehensif:** * Verifikasi akun manual/otomatis.
  * Reset password dan pemblokiran akun.
  * Pantau riwayat tes spesifik per klien.
* 📝 **Manajemen Bank Soal & Paket:** * *Builder* soal interaktif (Tambah/Edit/Hapus instrumen).
  * Pembuatan paket tes kustom (misal: "Paket Rekrutmen BUMN") dengan pengaturan harga yang dinamis.
* ⚙️ **Skoring Otomatis (Auto-Calculate):** Mesin skoring canggih yang langsung mengonversi jawaban mentah menjadi T-Score (untuk MMPI) dan mengkategorikan tingkat gejala (untuk ADHD).
* 💳 **Sistem Pembayaran & Verifikasi:** Manajemen invoice, validasi bukti transfer manual, dan dukungan integrasi pembayaran instan (QRIS).
* 🖨️ **Generate Laporan PDF:** Pembuatan laporan hasil psikogram yang rapi dan siap cetak dengan dukungan library *mPDF/TCPDF*.

### 👤 Portal Klien (Peserta Tes)
* 💻 **Test Engine Anti-Distraksi:** Antarmuka pengerjaan tes yang bersih, responsif (mendukung HP/Tablet/Desktop), dilengkapi *timer* (opsional), dan fitur *auto-save* jawaban.
* 📈 **Riwayat & Akses Hasil:** Peserta dapat melihat histori tes yang pernah diambil dan mengunduh sertifikat/hasil interpretasi ringkas (jika diizinkan oleh admin).
* 🛒 **Katalog Pembelian Paket:** Halaman etalase untuk memilih dan membeli paket tes secara mandiri.
* 🎨 **Manajemen Profil & Avatar:** Pengaturan informasi dasar dan keamanan akun peserta.

---

## 📸 Dokumentasi Antarmuka

### 💻 Panel Administrator
| Dashboard Utama | Manajemen Klien |
|:---:|:---:|
| <img src="assets/screenshots/admin_dashboard.png" width="400" alt="Admin Dashboard"> | <img src="assets/screenshots/manage_clients.png" width="400" alt="Manage Clients"> |
| **Kelola Bank Soal** | **Laporan Hasil Tes** |
| <img src="assets/screenshots/manage_questions.png" width="400" alt="Manage Questions"> | <img src="assets/screenshots/manage_results.png" width="400" alt="Manage Results"> |

### 👥 Portal Klien
| Dashboard Peserta | Katalog Paket Tes |
|:---:|:---:|
| <img src="assets/screenshots/client_dashboard.png" width="400" alt="Client Dashboard"> | <img src="assets/screenshots/choose_package.png" width="400" alt="Choose Package"> |
| **Riwayat Pemeriksaan** | **Paket Aktif Saya** |
| <img src="assets/screenshots/test_history.png" width="400" alt="Test History"> | <img src="assets/screenshots/active_packages.png" width="400" alt="Active Packages"> |

---

## 🖥️ Persyaratan Sistem

Untuk menjalankan sistem ini secara optimal, server/hosting Anda memerlukan:
- **Sistem Operasi:** Linux (Direkomendasikan) / Windows
- **Web Server:** Apache 2.4+ atau Nginx
- **PHP Version:** PHP 7.4.x hingga PHP 8.2.x
- **Database:** MySQL 5.7+ atau MariaDB 10.3+
- **PHP Extensions Wajib:**
  - `pdo_mysql` (Untuk koneksi database)
  - `gd` atau `imagick` (Untuk manipulasi gambar/avatar)
  - `mbstring` (Untuk rendering PDF)
  - `json` & `cURL` (Untuk API/Payment Gateway)

---

## 🛠️ Teknologi Pendukung

* **Backend:** PHP (Native) dengan pattern arsitektur terstruktur.
* **Database:** MySQL (Eksekusi query diamankan dengan sistem PDO / Prepared Statements).
* **Frontend:** HTML5, CSS3, Bootstrap 5 Framework.
* **JavaScript:** Vanilla JS, Fetch API / AJAX (Pengerjaan tes tanpa *reload* halaman).
* **Lainnya:** FontAwesome (Ikon), SweetAlert2 (Notifikasi UI).

---

## 📂 Struktur Direktori

```text
mmpi-adhd-system/
├── admin/                  # Modul dan halaman khusus Administrator
├── assets/                 # File statis (CSS, JS, Images, Uploads)
│   ├── css/
│   ├── js/
│   └── screenshots/        # Gambar dokumentasi README
├── client/                 # Modul dan halaman untuk Peserta/Klien
├── database/               # File skema SQL (.sql) untuk instalasi
├── includes/               # File krusial (Koneksi, Fungsi Global, Helpers)
│   ├── config.php          # Konfigurasi Kredensial Database
│   └── functions.php       # Kumpulan fungsi logika utama
├── test_engine/            # Modul inti untuk eksekusi dan skoring tes
├── index.php               # Halaman utama (Landing Page)
├── login.php               # Otentikasi pengguna
└── README.md               # Dokumentasi sistem
