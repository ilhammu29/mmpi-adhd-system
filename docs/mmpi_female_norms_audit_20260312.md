# Audit Norma MMPI Female - 2026-03-12

## Ringkasan
Scoring utama aplikasi sudah membedakan `male` dan `female`.
Masalah utama saat ini bukan di logika gender, tetapi di kelengkapan tabel `mmpi_norms`, khususnya untuk `female`.

## Temuan Kunci
- Jalur scoring utama menggunakan `includes/scoring_functions.php` dan meneruskan parameter gender ke `calculateTScoreForScale()`.
- Input gender seperti `Perempuan`, `female`, `wanita`, `p` dinormalisasi menjadi `female`.
- Untuk banyak raw score female, exact lookup di `mmpi_norms` tidak ditemukan, sehingga sistem jatuh ke fallback formula mean/SD.
- Ini berarti hasil `female` tetap berbeda dari `male`, tetapi belum selalu setepat norma raw-to-T resmi.

## Bukti Jalur Gender Berbeda
Contoh perhitungan raw score yang sama dengan scorer aplikasi saat ini:

| Scale | Raw | Male T | Female T |
|---|---:|---:|---:|
| Hs | 10 | 75 | 33 |
| D  | 20 | 70 | 49 |
| Hy | 20 | 63 | 58 |
| Pd | 20 | 62 | 36 |
| Mf | 30 | 55 | 65 |
| Pa | 10 | 49 | 59 |
| Pt | 20 | 63 | 30 |
| Sc | 20 | 62 | 30 |
| Ma | 15 | 33 | 37 |
| Si | 20 | 44 | 43 |

Kesimpulan: jalur gender memang aktif dan hasil male/female tidak sama.

## Coverage `mmpi_norms` Saat Ini

| Scale | Male Count | Male Range | Female Count | Female Range |
|---|---:|---|---:|---|
| L  | 1 | 13..13 | 1 | 11..11 |
| F  | 1 | 8..8 | 1 | 5..5 |
| K  | 1 | 24..24 | 1 | 24..24 |
| Hs | 7 | 0..18 | 2 | 7..19 |
| D  | 8 | 0..24 | 1 | 20..20 |
| Hy | 8 | 0..29 | 1 | 26..26 |
| Pd | 7 | 0..27 | 2 | 15..25 |
| Mf | 6 | 0..30 | 1 | 30..30 |
| Pa | 6 | 0..13 | 1 | 13..13 |
| Pt | 7 | 0..30 | 2 | 7..31 |
| Sc | 6 | 0..32 | 2 | 7..31 |
| Ma | 6 | 0..19 | 2 | 13..18 |
| Si | 8 | 0..39 | 1 | 20..20 |

## Dampak
- Basic scale female yang tidak punya exact raw lookup akan memakai fallback formula.
- Risiko mismatch paling besar ada pada skala klinis inti, terutama yang sensitif ke norma female seperti `Mf`, serta skala dengan raw/corrected raw yang berada di luar anchor data sparse.

## File Template Lanjutan
Template untuk melengkapi norma female basic scales ada di:
- `docs/mmpi_norms_female_missing_basic_template.csv`

Catatan:
- File itu adalah template `missing rows`, bukan nilai resmi final.
- Kolom `t_score` harus diisi dari norma raw-to-T resmi yang kamu pakai.

## Rekomendasi
1. Lengkapi semua `female basic norms` terlebih dahulu.
2. Setelah import, lakukan retest pada sampel female PDF.
3. Samakan semua jalur admin legacy agar memakai scorer utama yang gender-aware.
