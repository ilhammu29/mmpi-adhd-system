<?php
// Usage:
// php tools/import_mmpi_rc_mapping_csv.php /path/to/mmpi_rc_mapping.csv
// CSV header required: scale_code,scale_name,question_numbers

require_once __DIR__ . '/../includes/config.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/import_mmpi_rc_mapping_csv.php /path/to/mmpi_rc_mapping.csv\n");
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
$required = ['scale_code', 'scale_name', 'question_numbers'];
foreach ($required as $r) {
    if (!in_array($r, $header, true)) {
        fwrite(STDERR, "Missing required column: $r\n");
        exit(1);
    }
}
$idx = array_flip($header);

try {
    $db->exec("ALTER TABLE mmpi_rc_mapping ADD UNIQUE KEY uq_mmpi_rc_scale_code (scale_code)");
} catch (Exception $e) {
    // ignore if already exists
}

$stmt = $db->prepare("\n    INSERT INTO mmpi_rc_mapping (scale_code, scale_name, question_numbers)\n    VALUES (?, ?, ?)\n    ON DUPLICATE KEY UPDATE\n        scale_name = VALUES(scale_name),\n        question_numbers = VALUES(question_numbers)\n");

$db->beginTransaction();
while (($row = fgetcsv($fh)) !== false) {
    $scale = trim($row[$idx['scale_code']] ?? '');
    $name = trim($row[$idx['scale_name']] ?? '');
    $numbers = trim($row[$idx['question_numbers']] ?? '');

    if ($scale === '' || $name === '' || $numbers === '') {
        $skipped++;
        continue;
    }

    $scale = strtoupper($scale);

    // sanitize list: keep positive integers only, preserve order unique
    $parts = array_map('trim', explode(',', $numbers));
    $seen = [];
    $clean = [];
    foreach ($parts as $p) {
        if (!is_numeric($p)) {
            continue;
        }
        $n = intval($p);
        if ($n <= 0 || isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $clean[] = $n;
    }

    if (empty($clean)) {
        $skipped++;
        continue;
    }

    $cleanNumbers = implode(',', $clean);
    $stmt->execute([$scale, $name, $cleanNumbers]);
    if ($stmt->rowCount() === 1) {
        $inserted++;
    } else {
        $updated++;
    }
}
$db->commit();
fclose($fh);

echo "Import done. inserted=$inserted updated=$updated skipped=$skipped\n";
