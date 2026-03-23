# E2E Scoring Test - 2026-03-09

## Scope
- Validate end-to-end scoring path via backend function `saveTestResultsComplete()`
- Verify deterministic outputs (no random supplementary/basic values)
- Confirm PDF sample calibration report status

## Execution Summary
- Source session: `test_sessions.id=46`
- Created test session: `test_sessions.id=47`
- Generated result: `test_results.id=47`
- Result code: `RES20260309023935505`

## DB Assertions
- New session status: `completed`
- Session `result_id` linked: `47`
- New result `is_finalized=1`
- New result `result_unlocked=0`
- `validity_scores` and `basic_scales` stored with expected JSON structure

## Deterministic Check
- Re-ran scoring twice with same answers from session `47`
- `supplementary` hash match: `yes`
- `basic` hash match: `yes`

## Calibration Status
- See report: `docs/validation_report_norma_lokal_v1.txt`
- Current status: exact match to 2 PDF samples (`26/26`, MAE `0`)

## Notes
- Baseline profile in DB: `mmpi_norms_profile = Norma Lokal v1 (Empirical+Interpolation+PDF anchors) - 2026-03-09`
