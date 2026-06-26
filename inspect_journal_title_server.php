<?php
/**
 * Temporary script to inspect a live journal.
 */
require_once __DIR__ . '/config/db.php';
header('Content-Type: text/plain; charset=utf-8');

$manuscript_file = 'uploads/manuscript_1782482219_8204.pdf';

try {
    $stmt = $pdo->prepare("SELECT * FROM journals WHERE manuscript_file LIKE ?");
    $stmt->execute(['%manuscript_1782482219_8204.pdf%']);
    $journal = $stmt->fetch();

    if (!$journal) {
        die("Journal not found for file $manuscript_file.");
    }

    echo "Found Journal Details:\n";
    echo "ID: " . $journal['id'] . "\n";
    echo "Number: " . $journal['journal_number'] . "\n";
    echo "Title: " . $journal['title'] . "\n";
    echo "Status: " . $journal['status'] . "\n";
    echo "Manuscript File: " . $journal['manuscript_file'] . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
