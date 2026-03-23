<?php
// Regression test for payment flows (transfer + qris callback).
// Usage:
//   php tools/test_payment_flows.php
//   php tools/test_payment_flows.php --keep-data

require_once __DIR__ . '/../includes/config.php';

$db = getDB();
$keepData = in_array('--keep-data', $argv, true);

$createdOrders = [];
$createdSessions = [];
$createdFiles = [];
$settingsBackup = [];
$secureSettingKeys = ['qris_api_secret', 'qris_signature_required', 'qris_allowed_ips'];

function testOut(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name;
    if ($detail !== '') {
        echo ' - ' . $detail;
    }
    echo PHP_EOL;
}

function invokeQrisCallback(string $orderNumber, string $status): string
{
    $secret = (string)getSetting('qris_api_secret', '');
    $tx = 'TX-' . date('YmdHis');
    $amount = '100000';
    $signature = hash_hmac('sha256', $orderNumber . '|' . strtolower(trim($status)) . '|' . $amount . '|' . $tx, $secret);

    $wrapper = tempnam(sys_get_temp_dir(), 'qcb_');
    $wrapperFile = $wrapper . '.php';
    @unlink($wrapper);

    $code = <<<'PHP'
<?php
$orderNumber = $argv[1] ?? '';
$status = $argv[2] ?? '';
$transactionId = $argv[3] ?? '';
$amount = $argv[4] ?? '';
$signature = $argv[5] ?? '';
$_GET = [
  'order_number' => $orderNumber,
  'status' => $status,
  'transaction_id' => $transactionId,
  'amount' => $amount,
  'signature' => $signature
];
$_POST = [];
$_REQUEST = $_GET;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'payment-regression-test';
chdir('/srv/http/mmpi-adhd-system/client');
ob_start();
include 'payment_callback.php';
$out = trim(ob_get_clean());
echo $out . PHP_EOL;
PHP;

    file_put_contents($wrapperFile, $code);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($wrapperFile)
        . ' ' . escapeshellarg($orderNumber)
        . ' ' . escapeshellarg($status)
        . ' ' . escapeshellarg($tx)
        . ' ' . escapeshellarg($amount)
        . ' ' . escapeshellarg($signature);
    $out = trim((string)shell_exec($cmd));
    @unlink($wrapperFile);
    return $out;
}

try {
    // Backup current secure callback settings.
    $bakStmt = $db->prepare("
        SELECT setting_key, setting_value, setting_type, category, description
        FROM system_settings
        WHERE setting_key = ?
        LIMIT 1
    ");
    foreach ($secureSettingKeys as $k) {
        $bakStmt->execute([$k]);
        $row = $bakStmt->fetch(PDO::FETCH_ASSOC);
        $settingsBackup[$k] = $row ?: null;
    }

    // Enforce secure callback settings for regression.
    $db->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
        VALUES ('qris_api_secret', 'regression-secret', 'string', 'payment', 'QRIS callback secret for regression')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_type=VALUES(setting_type), category=VALUES(category), description=VALUES(description), updated_at=NOW()
    ")->execute();
    $db->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
        VALUES ('qris_signature_required', '1', 'boolean', 'payment', 'Require callback signature')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_type=VALUES(setting_type), category=VALUES(category), description=VALUES(description), updated_at=NOW()
    ")->execute();
    $db->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
        VALUES ('qris_allowed_ips', '127.0.0.1', 'string', 'payment', 'Allowed callback IPs')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_type=VALUES(setting_type), category=VALUES(category), description=VALUES(description), updated_at=NOW()
    ")->execute();

    $clientId = (int)$db->query("SELECT id FROM users WHERE role='client' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $package = $db->query("SELECT id, price, validity_days FROM packages WHERE is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if (!$clientId || !$package) {
        throw new RuntimeException('Client/package aktif tidak ditemukan.');
    }

    // A) Transfer flow: create -> upload proof -> verify -> session created.
    $orderNoA = 'TA' . date('mdHis') . rand(100, 999);
    $insOrder = $db->prepare("
        INSERT INTO orders (
            order_number, user_id, package_id, amount, payment_method,
            payment_status, order_status, test_access_granted,
            payment_expires_at, created_at, updated_at
        ) VALUES (?, ?, ?, ?, 'transfer', 'pending', 'pending', 0, DATE_ADD(NOW(), INTERVAL 1 DAY), NOW(), NOW())
    ");
    $insOrder->execute([$orderNoA, $clientId, (int)$package['id'], (float)$package['price']]);
    $orderAId = (int)$db->lastInsertId();
    $createdOrders[] = $orderAId;
    testOut('Create transfer order', $orderAId > 0, 'order_id=' . $orderAId);

    $proofFile = 'proof_regression_' . $orderAId . '.txt';
    $proofPath = rtrim(getProofUploadPath(), '/') . '/' . $proofFile;
    file_put_contents($proofPath, 'dummy proof');
    $createdFiles[] = $proofPath;

    $upProof = $db->prepare("UPDATE orders SET payment_proof=?, order_status='processing', updated_at=NOW() WHERE id=? AND user_id=?");
    $upProof->execute([$proofFile, $orderAId, $clientId]);
    $rowA = $db->prepare("SELECT payment_proof, payment_status, order_status FROM orders WHERE id=?");
    $rowA->execute([$orderAId]);
    $orderA = $rowA->fetch(PDO::FETCH_ASSOC);
    $proofOk = $orderA && $orderA['payment_proof'] === $proofFile && $orderA['payment_status'] === 'pending' && $orderA['order_status'] === 'processing';
    testOut('Upload proof flow', $proofOk, json_encode($orderA));

    $verify = $db->prepare("
        UPDATE orders
        SET payment_status='paid',
            payment_date=NOW(),
            order_status='processing',
            test_access_granted=1,
            access_granted_at=NOW(),
            test_expires_at=DATE_ADD(NOW(), INTERVAL ? DAY),
            updated_at=NOW()
        WHERE id=?
    ");
    $verify->execute([(int)($package['validity_days'] ?? 30), $orderAId]);

    $sess = $db->prepare("SELECT id FROM test_sessions WHERE order_id=? LIMIT 1");
    $sess->execute([$orderAId]);
    $existingSession = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$existingSession) {
        $sessionCode = 'TS' . date('YmdHis') . rand(100, 999);
        $insSess = $db->prepare("INSERT INTO test_sessions (session_code,user_id,order_id,package_id,status,created_at) VALUES (?, ?, ?, ?, 'not_started', NOW())");
        $insSess->execute([$sessionCode, $clientId, $orderAId, (int)$package['id']]);
        $createdSessions[] = (int)$db->lastInsertId();
    } else {
        $createdSessions[] = (int)$existingSession['id'];
    }

    $rowA2 = $db->prepare("SELECT payment_status, order_status, test_access_granted, test_expires_at FROM orders WHERE id=?");
    $rowA2->execute([$orderAId]);
    $orderA2 = $rowA2->fetch(PDO::FETCH_ASSOC);
    $verifyOk = $orderA2
        && $orderA2['payment_status'] === 'paid'
        && (int)$orderA2['test_access_granted'] === 1
        && !empty($orderA2['test_expires_at'])
        && !in_array((string)$orderA2['order_status'], ['expired', 'cancelled'], true);
    testOut('Verify transfer flow', $verifyOk, json_encode($orderA2));

    // B) Reject flow.
    $orderNoB = 'TB' . date('mdHis') . rand(100, 999);
    $insOrder->execute([$orderNoB, $clientId, (int)$package['id'], (float)$package['price']]);
    $orderBId = (int)$db->lastInsertId();
    $createdOrders[] = $orderBId;
    $reject = $db->prepare("UPDATE orders SET payment_status='failed', order_status='cancelled', updated_at=NOW() WHERE id=?");
    $reject->execute([$orderBId]);
    $rowB = $db->prepare("SELECT payment_status, order_status FROM orders WHERE id=?");
    $rowB->execute([$orderBId]);
    $orderB = $rowB->fetch(PDO::FETCH_ASSOC);
    $rejectOk = $orderB && $orderB['payment_status'] === 'failed' && $orderB['order_status'] === 'cancelled';
    testOut('Reject flow', $rejectOk, json_encode($orderB));

    // C) QRIS callback flow.
    $orderNoC = 'QC' . date('mdHis') . rand(100, 999);
    $insQris = $db->prepare("
        INSERT INTO orders (
            order_number, user_id, package_id, amount, payment_method,
            payment_status, order_status, test_access_granted,
            payment_expires_at, created_at, updated_at
        ) VALUES (?, ?, ?, ?, 'qris', 'pending', 'pending', 0, DATE_ADD(NOW(), INTERVAL 1 DAY), NOW(), NOW())
    ");
    $insQris->execute([$orderNoC, $clientId, (int)$package['id'], (float)$package['price']]);
    $orderCId = (int)$db->lastInsertId();
    $createdOrders[] = $orderCId;

    $cbPaid = invokeQrisCallback($orderNoC, 'paid');
    $cbPaidOk = str_contains($cbPaid, '"success":true');
    testOut('QRIS callback paid response', $cbPaidOk, $cbPaid);

    $rowC = $db->prepare("SELECT payment_status, order_status, test_access_granted FROM orders WHERE id=?");
    $rowC->execute([$orderCId]);
    $orderC = $rowC->fetch(PDO::FETCH_ASSOC);
    $qrisPaidOk = $orderC && $orderC['payment_status'] === 'paid' && $orderC['order_status'] === 'processing' && (int)$orderC['test_access_granted'] === 1;
    testOut('QRIS paid order update', $qrisPaidOk, json_encode($orderC));

    $sessC = $db->prepare("SELECT id FROM test_sessions WHERE order_id=?");
    $sessC->execute([$orderCId]);
    $sessRowsC = $sessC->fetchAll(PDO::FETCH_ASSOC);
    $qrisSessionOk = count($sessRowsC) >= 1;
    testOut('QRIS creates session', $qrisSessionOk, 'count=' . count($sessRowsC));
    foreach ($sessRowsC as $s) {
        $createdSessions[] = (int)$s['id'];
    }

    $cbRetry = invokeQrisCallback($orderNoC, 'paid');
    $retryOk = str_contains($cbRetry, 'Payment already verified');
    testOut('QRIS callback retry idempotent', $retryOk, $cbRetry);

    // D) QRIS failed callback flow.
    $orderNoD = 'QD' . date('mdHis') . rand(100, 999);
    $insQris->execute([$orderNoD, $clientId, (int)$package['id'], (float)$package['price']]);
    $orderDId = (int)$db->lastInsertId();
    $createdOrders[] = $orderDId;

    $cbFailed = invokeQrisCallback($orderNoD, 'failed');
    $cbFailedOk = str_contains($cbFailed, '"success":false');
    testOut('QRIS callback failed response', $cbFailedOk, $cbFailed);

    $rowD = $db->prepare("SELECT payment_status, order_status, test_access_granted FROM orders WHERE id=?");
    $rowD->execute([$orderDId]);
    $orderD = $rowD->fetch(PDO::FETCH_ASSOC);
    $qrisFailedOk = $orderD && $orderD['payment_status'] === 'failed' && $orderD['order_status'] === 'cancelled' && (int)$orderD['test_access_granted'] === 0;
    testOut('QRIS failed order update', $qrisFailedOk, json_encode($orderD));

} catch (Throwable $e) {
    testOut('Regression execution', false, $e->getMessage());
} finally {
    // Restore original callback security settings.
    foreach ($secureSettingKeys as $k) {
        $orig = $settingsBackup[$k] ?? null;
        if ($orig === null) {
            $db->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
            continue;
        }
        $db->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_type = VALUES(setting_type),
                category = VALUES(category),
                description = VALUES(description),
                updated_at = NOW()
        ")->execute([
            $orig['setting_key'],
            $orig['setting_value'],
            $orig['setting_type'],
            $orig['category'],
            $orig['description']
        ]);
    }

    if (!$keepData) {
        foreach (array_unique($createdSessions) as $sessionId) {
            $db->prepare("DELETE FROM test_sessions WHERE id=?")->execute([$sessionId]);
        }
        foreach (array_unique($createdOrders) as $orderId) {
            $db->prepare("DELETE FROM orders WHERE id=?")->execute([$orderId]);
        }
        foreach (array_unique($createdFiles) as $filePath) {
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    echo 'Cleanup ' . ($keepData ? 'skipped' : 'done')
        . ': orders=' . count(array_unique($createdOrders))
        . ', sessions=' . count(array_unique($createdSessions))
        . ', files=' . count(array_unique($createdFiles))
        . PHP_EOL;
}
