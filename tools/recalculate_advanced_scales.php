<?php
// Usage:
// php tools/recalculate_advanced_scales.php
// php tools/recalculate_advanced_scales.php --result-id=47

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/scoring_functions.php';

$db = getDB();
$resultIdFilter = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--result-id=') === 0) {
        $resultIdFilter = intval(substr($arg, strlen('--result-id=')));
    }
}

$sql = "
    SELECT tr.id AS result_id, tr.user_id, tr.supplementary_scales, ts.mmpi_answers, u.gender
    FROM test_results tr
    JOIN test_sessions ts ON ts.id = tr.test_session_id
    JOIN users u ON u.id = tr.user_id
    WHERE ts.mmpi_answers IS NOT NULL
";
$params = [];
if ($resultIdFilter) {
    $sql .= " AND tr.id = ?";
    $params[] = $resultIdFilter;
}
$sql .= " ORDER BY tr.id";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No matching result found.\n";
    exit(0);
}

$updated = 0;
$skipped = 0;
$changed = 0;
$db->beginTransaction();

$updateStmt = $db->prepare("UPDATE test_results SET supplementary_scales = ?, updated_at = NOW() WHERE id = ?");
$logStmt = $db->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (NULL, ?, ?, '127.0.0.1', 'cli:recalculate_advanced_scales.php')");

foreach ($rows as $row) {
    $answers = json_decode($row['mmpi_answers'] ?? '', true);
    if (!is_array($answers) || empty($answers)) {
        $skipped++;
        continue;
    }

    $gender = strtolower((string)($row['gender'] ?? 'male'));
    if (in_array($gender, ['female', 'perempuan', 'wanita', 'p'], true)) {
        $gender = 'female';
    } else {
        $gender = 'male';
    }

    $supp = calculateSupplementaryScales($db, $answers, $gender);

    // Keep backward compatibility: preserve existing keys if needed, overwrite with new calc.
    $old = json_decode($row['supplementary_scales'] ?? '', true);
    if (!is_array($old)) {
        $old = [];
    }
    $merged = array_merge($old, $supp);
    $oldJson = json_encode($old);
    $newJson = json_encode($merged);

    $updateStmt->execute([$newJson, $row['result_id']]);
    if ($oldJson !== $newJson) {
        $changed++;
        $logStmt->execute(['scoring_audit_recalculate', "cli result_id={$row['result_id']} before={$oldJson} after={$newJson}"]);
    }
    $updated++;
}

$logStmt->execute(['scoring_audit_recalculate', "cli summary updated={$updated} changed={$changed} skipped={$skipped}" ]);

$db->commit();

echo "Recalculation done. updated=$updated skipped=$skipped\n";
