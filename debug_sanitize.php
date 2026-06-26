<?php
// v2 - OPcache clear + verify server PDF helper fix
header('Content-Type: text/plain; charset=utf-8');

echo "PHP version: " . phpversion() . "\n\n";

// Clear OPcache if available
if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo "OPcache reset: " . ($result ? "SUCCESS" : "FAILED") . "\n";
} else {
    echo "OPcache: opcache_reset() not available\n";
}

// Check pdf_helper.php on server - confirm the fix is present
echo "\n=== pdf_helper.php lines 48-90 on SERVER ===\n";
$file = __DIR__ . '/includes/pdf_helper.php';
if (file_exists($file)) {
    $lines = file($file);
    for ($i = 47; $i <= 90 && $i < count($lines); $i++) {
        echo ($i+1) . ": " . rtrim($lines[$i]) . "\n";
    }
} else {
    echo "FILE NOT FOUND: " . $file . "\n";
}
