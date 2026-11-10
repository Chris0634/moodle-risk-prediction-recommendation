# Pengembangan Model Machine Learning pada Moodle untuk Prediksi Kegagalan dalam Mata Kuliah dan Rekomendasi Materi yang Dipersonalisasi

---

## Tentang Proyek
Proyek ini dikembangkan sebagai Tugas Akhhir Teknik Informatika. Sistem ini dirancang untuk membantu institusi pendidikan mendeteksi mahasiswa yang berisiko mengalami kendala akademik lebih awal berdasarkan log aktivitas Moodle, serta memberikan rekomendasi materi belajar yang relevan secara otomatis.

---

## Fitur Utama
- **Prediksi Risiko Kegagalan:** Menggunakan algoritma *Machine Learning* untuk mengidentifikasi tingkat risiko mahasiswa berdasarkan data tugas, kuis, forum, dan aktivitas log.
- **Rekomendasi Materi Otomatis:** Sistem menyaring dan merekomendasikan materi pembelajaran di Moodle yang sesuai dengan kebutuhan personal mahasiswa.
- **Integrasi FastAPI & Moodle:** Terhubung langsung dengan arsitektur Moodle untuk pertukaran data secara *real-time*.

---

## Tech Stack
- **Backend / API:** Python, FastAPI, PHP (Moodle Plugin)
- **Machine Learning:** Scikit-Learn, Pandas, NumPy, Jupyter Notebook
- **Database:** PostgreSQL, MySQL (Moodle DB)

---

## Panduan Instalasi dan Cara Menjalankan Sistem

Sistem ini terbagi menjadi dua bagian utama yang harus dikonfigurasi: **Plugin Moodle** dan **Backend API (FastAPI & AI Engine)**.

---

### A. Pemasangan Plugin Moodle (`local_smart_lmsx`)

1. **Unduh Berkas Plugin:**
   * Siapkan berkas arsip ZIP plugin Moodle (`smart_lmsx.zip`).

2. **Ekstrak Berkas ZIP:**
   * Buka direktori root instalasi Moodle di server/komputer lokal (misalnya di `htdocs/moodle/`, `/var/www/html/moodle/`, atau direktori server web).
   * Masuk ke dalam folder: `[direktori_moodle]/local/`
   * Ekstrak file ZIP ke dalam folder tersebut dan pastikan nama foldernya adalah `smart_lmsx`.
   * Struktur direktori harus tampak seperti berikut:
     ```text
     [direktori_moodle]/local/smart_lmsx/
     ├── db/
     │   ├── events.php
     │   └── install.xml
     ├── classes/
     │   └── observer.php
     ├── lib.php
     ├── report.php
     └── version.php
     ```

3. **Registrasi dan Pembaruan Database Moodle:**
   * Buka browser dan login ke Moodle sebagai Administrator.
   * Buka menu **Site Administration (Administrasi Situs) > Notifications**.
   * Moodle akan secara otomatis mendeteksi plugin baru `local_smart_lmsx` dan meminta pembaruan database (*Upgrade Moodle database now*).
   * Klik tombol **Upgrade** hingga muncul status keberhasilan instalasi.

---

### B. Pemasangan dan Konfigurasi Backend API (FastAPI & AI Engine)

1. **Ekstrak Berkas Backend:**
   * Siapkan berkas ZIP backend API (`smart_lmsx_api.zip`).
   * Ekstrak file tersebut ke direktori kerja yang diinginkan (misalnya di `D:\smart_lmsx_api` atau `/home/user/smart_lmsx_api`).
   * Pastikan berkas-berkas pendukung pemodelan berada di dalam folder proyek tersebut:
     ```text
     smart_lmsx_api/
     ├── main.py
     ├── smart_lmsx_model_final.pkl
     ├── scaler.pkl
     ├── smart_lmsx_recom_engine.pkl
     └── requirements.txt
     ```

2. **Instalasi Dependensi Python:**
   * Buka Terminal / Command Prompt / PowerShell, lalu arahkan ke direktori backend:
     ```bash
     cd D:\smart_lmsx_api
     ```
   * *(Opsional)* Buat dan aktifkan lingkungan virtual (*virtual environment*):
     ```bash
     python -m venv venv
     # Di Windows:
     .\venv\Scripts\activate
     # Di Linux/Mac:
     source venv/bin/activate
     ```
   * Instal paket pustaka yang diperlukan:
     ```bash
     pip install fastapi uvicorn psycopg2-binary pandas scikit-learn shap sentence-transformers beautifulsoup4 joblib
     ```

3. **Konfigurasi Koneksi Basis Data PostgreSQL:**
   * Buka berkas `main.py` menggunakan teks editor (misalnya VS Code).
   * Sesuaikan parameter kredensial PostgreSQL (`DB_CONFIG`) agar sesuai dengan konfigurasi server database Moodle Anda:
     ```python
     DB_CONFIG = {
         "dbname": "moodle",          # Nama database Moodle
         "user": "postgres",          # Pengguna database
         "password": "password_db",   # Kata sandi database
         "host": "localhost",         # Host database
         "port": 5432                 # Port default PostgreSQL
     }
     ```

4. **Menjalankan Peladen Backend FastAPI:**
   * Jalankan server API menggunakan Uvicorn melalui terminal:
     ```bash
     uvicorn main:app --host 127.0.0.1 --port 8000 --reload
     ```
   * Pastikan terminal menampilkan pesan status bahwa server telah berjalan di [http://127.0.0.1:8000](http://127.0.0.1:8000).

---

### C. Verifikasi dan Pengujian Sistem

1. **Uji Komunikasi API:**
   * Pastikan rute API aktif dengan mengakses [http://127.0.0.1:8000/docs](http://127.0.0.1:8000/docs) di browser untuk melihat dokumentasi interaktif Swagger UI.

2. **Uji Integrasi End-to-End di Moodle:**
   * Masuk ke salah satu mata kuliah di Moodle sebagai pengajar atau mahasiswa.
   * Lakukan aktivitas pemicu (*event trigger*), seperti menilai tugas mahasiswa di Grader Report atau mengirim postingan di forum diskusi.
   * Periksa terminal FastAPI; pastikan log menampilkan penerimaan payload `POST /predict HTTP/1.1 200 OK` dan pesan penyimpanan data berhasil.
   * Muat ulang halaman dasbor laporan pengajar (`report.php`) atau halaman utama mata kuliah mahasiswa (`lib.php`) untuk memastikan indikator risiko, faktor SHAP, dan widget rekomendasi materi telah muncul secara instan.
