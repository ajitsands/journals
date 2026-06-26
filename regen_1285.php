<?php
/**
 * ONE-TIME script to regenerate the PDF for RJPES-2026-1285.
 * DELETE this file immediately after running.
 */
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/word_helper.php';

// Find journal by journal_number
$stmt = $pdo->prepare("SELECT id, journal_number, title, status FROM journals WHERE journal_number = ?");
$stmt->execute(['RJPES-2026-1285']);
$journal = $stmt->fetch();

if (!$journal) {
    echo "ERROR: Journal RJPES-2026-1285 not found in database.\n";
    exit(1);
}

echo "Found journal:\n";
echo "  ID: " . $journal['id'] . "\n";
echo "  Number: " . $journal['journal_number'] . "\n";
echo "  Title: " . $journal['title'] . "\n";
echo "  Status: " . $journal['status'] . "\n\n";

echo "Regenerating PDF...\n";
$result = rjpes_regenerate_journal_pdf($journal['id']);

if ($result) {
    echo "SUCCESS: PDF regenerated cleanly for " . $journal['journal_number'] . ".\n";
    echo "Check: https://rjpes.in/uploads/manuscript_1782482219_8204.pdf\n";
} else {
    echo "FAILED: rjpes_regenerate_journal_pdf() returned false.\n";
    echo "Check PHP error log for details.\n";
}
