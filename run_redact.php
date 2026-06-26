<?php
/**
 * Script to run PDF redaction on the server.
 * Open this in your browser: https://rjpes.in/run_redact.php
 * After running, DELETE this file and redact_script.py from your server for security.
 */
header('Content-Type: text/plain; charset=utf-8');

// Change directory to repo root
chdir(__DIR__);

echo "Starting PDF Redaction fix...\n";

$py_exe = (DIRECTORY_SEPARATOR === '\\') ? 'python' : 'python3';
$cmd_prefix = (DIRECTORY_SEPARATOR === '\\') ? '' : 'export HOME=/home/rjpes && export XDG_CACHE_HOME=/home/rjpes/.cache && ';
$cmd = $cmd_prefix . "$py_exe redact_script.py 2>&1";

echo "Running command: $cmd\n\n";
$output = shell_exec($cmd);

echo "Output:\n" . $output . "\n";
echo "Done. Please verify the PDF at: https://rjpes.in/uploads/manuscript_1782092424_7622.pdf\n";
echo "IMPORTANT: Delete both run_redact.php and redact_script.py from the server once verified.\n";
?>
