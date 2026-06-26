<?php
header('Content-Type: text/plain; charset=utf-8');

// Clear OPcache if available
if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo "OPcache reset: " . ($result ? "SUCCESS" : "FAILED") . "\n";
} else {
    echo "OPcache not available (opcache_reset not found)\n";
}

// Also check if opcache is enabled
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status();
    echo "OPcache enabled: " . ($status ? "YES" : "NO") . "\n";
    echo "Cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
} else {
    echo "opcache_get_status not available\n";
}

// Show PHP version
echo "PHP version: " . phpversion() . "\n";

// Test the fix is in place - check first 10 lines of pdf_helper.php on server
echo "\n=== pdf_helper.php snippet (line ~48-90) ===\n";
$lines = file(__DIR__ . '/includes/pdf_helper.php');
for ($i = 47; $i <= 90 && $i < count($lines); $i++) {
    echo ($i+1) . ": " . $lines[$i];
}
