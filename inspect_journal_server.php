<?php
/**
 * Temporary script to inspect a live journal.
 */
require_once __DIR__ . '/config/db.php';
header('Content-Type: text/plain; charset=utf-8');

$manuscript_file = 'uploads/manuscript_1782462758_6186.pdf';

try {
    $stmt = $pdo->prepare("SELECT * FROM journals WHERE manuscript_file = ?");
    $stmt->execute([$manuscript_file]);
    $journal = $stmt->fetch();

    if (!$journal) {
        // Try loose search
        $stmt = $pdo->prepare("SELECT * FROM journals WHERE manuscript_file LIKE ?");
        $stmt->execute(['%manuscript_1782462758_6186.pdf%']);
        $journal = $stmt->fetch();
    }

    if (!$journal) {
        die("Journal not found for file $manuscript_file.");
    }

    echo "Found Journal Details:\n";
    echo "ID: " . $journal['id'] . "\n";
    echo "Number: " . $journal['journal_number'] . "\n";
    echo "Title: " . $journal['title'] . "\n";
    echo "Status: " . $journal['status'] . "\n";
    echo "PDF Path: " . $journal['pdf_path'] . "\n";
    echo "Manuscript File: " . $journal['manuscript_file'] . "\n";
    echo "\nRaw Abstract in DB:\n";
    echo "----------------------------------------\n";
    echo $journal['abstract'] . "\n";
    echo "----------------------------------------\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
