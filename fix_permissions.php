<?php
// fix_permissions.php
// Jalankan sekali untuk setup folder dengan permission yang benar

echo "=== MMPI Payment System Permission Fix ===\n\n";

// Daftar folder yang perlu dibuat
$folders = [
    'assets/uploads',
    'assets/uploads/payment_proofs',
    'assets/qris',
    'assets/qris/temp',
    'includes/cache',
    'includes/logs'
];

// Daftar permission yang benar
$correctPermissions = [
    'assets/uploads' => 0755,
    'assets/uploads/payment_proofs' => 0777, // Agar PHP bisa write
    'assets/qris' => 0755,
    'assets/qris/temp' => 0755,
    'includes/cache' => 0755,
    'includes/logs' => 0777
];

$successCount = 0;
$errorCount = 0;

foreach ($folders as $folder) {
    $path = __DIR__ . '/' . $folder;
    
    if (!file_exists($path)) {
        if (mkdir($path, 0755, true)) {
            echo "✓ Folder created: $folder\n";
            $successCount++;
        } else {
            echo "✗ Failed to create folder: $folder\n";
            $errorCount++;
        }
    } else {
        echo "✓ Folder exists: $folder\n";
        $successCount++;
    }
    
    // Set permission jika folder sudah ada
    if (file_exists($path) && isset($correctPermissions[$folder])) {
        if (chmod($path, $correctPermissions[$folder])) {
            echo "  ✓ Permission set to: 0" . decoct($correctPermissions[$folder]) . "\n";
        } else {
            echo "  ✗ Failed to set permission for: $folder\n";
            $errorCount++;
        }
    }
}

// Cek permission web server user
echo "\n=== Checking Web Server User ===\n";
echo "PHP User: " . get_current_user() . "\n";
echo "PHP Process User: " . exec('whoami') . "\n";
echo "Web Server User: " . exec('ps aux | grep -E "[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx" | grep -v root | head -1 | awk \'{print $1}\'') . "\n";

// Cek apakah PHP bisa write ke folder
echo "\n=== Testing Write Access ===\n";

$testFolders = [
    'assets/uploads/payment_proofs',
    'includes/logs'
];

foreach ($testFolders as $testFolder) {
    $testFile = __DIR__ . '/' . $testFolder . '/test_write.tmp';
    
    if (file_put_contents($testFile, 'test')) {
        echo "✓ Can write to: $testFolder\n";
        unlink($testFile);
    } else {
        echo "✗ Cannot write to: $testFolder\n";
        $errorCount++;
    }
}

// Check upload settings
echo "\n=== PHP Upload Settings ===\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "\n";

// Fix ownership (jika di Linux)
if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
    echo "\n=== Linux Ownership Fix ===\n";
    
    $webUser = trim(shell_exec('ps aux | grep -E "[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx" | grep -v root | head -1 | awk \'{print $1}\''));
    
    if ($webUser) {
        $command = "chown -R $webUser:www-data " . __DIR__ . "/assets/uploads";
        echo "Running: $command\n";
        system($command, $returnCode);
        
        if ($returnCode === 0) {
            echo "✓ Ownership fixed for assets/uploads\n";
        } else {
            echo "✗ Failed to fix ownership\n";
        }
    }
}

echo "\n=== Summary ===\n";
echo "Success: $successCount\n";
echo "Errors: $errorCount\n";

if ($errorCount > 0) {
    echo "\n⚠️  Ada error yang perlu diperbaiki secara manual:\n";
    echo "1. Pastikan folder 'assets/uploads/payment_proofs' ada\n";
    echo "2. Set permission: chmod 777 assets/uploads/payment_proofs\n";
    echo "3. Pastikan web server user (www-data/apache) punya akses write\n";
}
?>