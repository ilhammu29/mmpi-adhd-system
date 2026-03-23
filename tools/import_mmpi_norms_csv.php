<?php
// Usage:
// php tools/import_mmpi_norms_csv.php /path/to/mmpi_norms_full.csv
// CSV header required: scale_code,gender,raw_score,t_score

require_once __DIR__ . '/../includes/config.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/import_mmpi_norms_csv.php /path/to/mmpi_norms_full.csv\n");
    exit(1);
}

$csvPath = $argv[1];
if (!is_file($csvPath)) {
    fwrite(STDERR, "File not found: $csvPath\n");
    exit(1);
}

$db = getDB();
$inserted = 0;
$updated = 0;
$skipped = 0;

$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh);
if (!$header) {
    fwrite(STDERR, "Empty CSV\n");
    exit(1);
}

$header = array_map('trim', $header);
$required = ['scale_code', 'gender', 'raw_score', 't_score'];
foreach ($required as $r) {
    if (!in_array($r, $header, true)) {
        fwrite(STDERR, "Missing required column: $r\n");
        exit(1);
    }
}

$idx = array_flip($header);

$db->beginTransaction();
try {
    // Make uniqueness deterministic for upsert.
    $db->exec("ALTER TABLE mmpi_norms ADD UNIQUE KEY uq_norm (scale_code, gender, raw_score)");
} catch (Exception $e) {
    // Ignore if already exists.
}

$stmt = $db->prepare("\n    INSERT INTO mmpi_norms (scale_code, gender, raw_score, t_score)\n    VALUES (?, ?, ?, ?)\n    ON DUPLICATE KEY UPDATE\n      t_score = VALUES(t_score)\n");

while (($row = fgetcsv($fh)) !== false) {
    $scale = strtoupper(trim($row[$idx['scale_code']] ?? ''));
    $gender = strtolower(trim($row[$idx['gender']] ?? ''));
    $raw = trim($row[$idx['raw_score']] ?? '');
    $t = trim($row[$idx['t_score']] ?? '');

    if ($scale === '' || ($gender !== 'male' && $gender !== 'female') || !is_numeric($raw) || !is_numeric($t)) {
        $skipped++;
        continue;
    }

    $stmt->execute([$scale, $gender, intval($raw), intval($t)]);
    if ($stmt->rowCount() === 1) {
        $inserted++;
    } else {
        $updated++;
    }
}

fclose($fh);
$db->commit();

echo "Import done. inserted=$inserted updated=$updated skipped=$skipped\n";
