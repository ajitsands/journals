<?php
/**
 * Log viewer for diagnosing PDF compilation errors on the server.
 */

header('Content-Type: text/plain; charset=utf-8');

$possible_paths = [
    __DIR__ . '/error_log',
    __DIR__ . '/author/error_log',
    ini_get('error_log')
];

echo "=== RJPES ERROR LOG AUDIT ===\n\n";

$found = false;
foreach ($possible_paths as $path) {
    if (empty($path)) continue;
    
    if (file_exists($path) && is_readable($path)) {
        $found = true;
        echo "Found error log at: $path\n";
        echo str_repeat('-', 50) . "\n";
        
        // Read last 2000 bytes/lines of file
        $content = file_get_contents($path);
        $lines = explode("\n", $content);
        $last_lines = array_slice($lines, -50); // Get last 50 lines
        
        echo implode("\n", $last_lines);
        echo "\n" . str_repeat('-', 50) . "\n\n";
    }
}

if (!$found) {
    echo "No standard error_log file found in: " . implode(', ', array_filter($possible_paths)) . "\n";
    echo "Please check if your hosting cPanel has an 'Errors' icon or log viewer, and copy the last few lines here.\n";
}
