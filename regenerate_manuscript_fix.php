<?php
/**
 * Temporary script to regenerate the manuscript PDF for journal ID 35.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/word_helper.php';

header('Content-Type: text/plain; charset=utf-8');

$journal_id = 35;

echo "Regenerating manuscript PDF for journal ID: $journal_id...\n";

try {
    $success = rjpes_regenerate_journal_pdf($journal_id);
    if ($success) {
        echo "SUCCESS: Manuscript PDF regenerated successfully.\n";
    } else {
        echo "FAILURE: Failed to regenerate manuscript PDF.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
