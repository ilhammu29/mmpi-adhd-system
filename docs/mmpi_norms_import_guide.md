# MMPI Norms Import Guide

## Expected CSV Format
Use file: `docs/mmpi_norms_import_template.csv`

Required columns:
- `scale_code` (example: `L`, `F`, `K`, `Hs`, `D`, `Hy`, `Pd`, `Mf`, `Pa`, `Pt`, `Sc`, `Ma`, `Si`, etc.)
- `gender` (`male` or `female`)
- `raw_score` (integer)
- `t_score` (integer)

## Import Command
```bash
php /srv/http/mmpi-adhd-system/tools/import_mmpi_norms_csv.php /path/to/mmpi_norms_full.csv
```

## Notes
- Import uses upsert by unique key `(scale_code, gender, raw_score)`.
- Existing rows will be updated with new `t_score`.
- Current baseline profile key in DB: `mmpi_norms_profile`.
