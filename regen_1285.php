<?php
/**
 * Clear OPcache + regenerate PDF for RJPES-2026-1285.
 * DELETE after use.
 */
header('Content-Type: text/plain; charset=utf-8');

// Clear OPcache first
if (function_exists('opcache_reset')) {
    $cleared = opcache_reset();
    echo "OPcache reset: " . ($cleared ? "OK" : "FAILED") . "\n";
} else {
    echo "OPcache: not available\n";
}

// Explicitly invalidate the key file
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/includes/pdf_helper.php', true);
    opcache_invalidate(__DIR__ . '/includes/word_helper.php', true);
    echo "Invalidated pdf_helper.php and word_helper.php\n";
}

echo "PHP version: " . phpversion() . "\n\n";

// Verify the NEW fix is in the file
$lines = file(__DIR__ . '/includes/pdf_helper.php');
$has_byte_fallback = false;
$has_new_replacements = false;
foreach ($lines as $line) {
    if (strpos($line, 'Byte-level fallback') !== false) $has_byte_fallback = true;
    if (strpos($line, 'U+2010') !== false) $has_new_replacements = true;  // new in our fix
}
echo "Fix check - byte-level fallback present: " . ($has_byte_fallback ? "YES" : "NO") . "\n";
echo "Fix check - new replacements present: " . ($has_new_replacements ? "YES" : "NO") . "\n\n";

// Re-run the regeneration
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/word_helper.php';

$stmt = $pdo->prepare("SELECT id, journal_number, title FROM journals WHERE journal_number = ?");
$stmt->execute(['RJPES-2026-1285']);
$journal = $stmt->fetch();

if (!$journal) {
    echo "ERROR: Journal not found\n";
    exit(1);
}

echo "Regenerating PDF for: " . $journal['journal_number'] . "\n";
echo "Title in DB: " . $journal['title'] . "\n\n";

$result = rjpes_regenerate_journal_pdf($journal['id']);
echo "Regeneration: " . ($result ? "SUCCESS" : "FAILED") . "\n";
echo "URL: https://rjpes.in/uploads/manuscript_1782482219_8204.pdf\n";
