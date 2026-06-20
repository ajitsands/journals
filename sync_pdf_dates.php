<?php
/**
 * RJPES PDF & Date Sync Utility
 * 
 * Visits: https://rjpes.in/sync_pdf_dates.php
 * This script updates the database published_at values for Volume 20 Issue 1 papers
 * to match the admin setting (March 2026) and regenerates all PDFs.
 */
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/word_helper.php';

echo "RJPES PDF & Date Sync Utility\n";
echo "=============================\n\n";

try {
    // 1. Fetch the active edition date from system_settings
    $current_edition_date = rjpes_get_setting('current_edition_date', '2026-03-04');
    $formatted_date = $current_edition_date . ' 12:00:00';
    
    echo "Active Edition Settings Date: " . $current_edition_date . " (Formats to: " . date('F Y', strtotime($current_edition_date)) . ")\n";
    
    // 2. Update published_at date in the database for all Volume 20 Issue 1 papers
    echo "Updating published_at for Volume 20 Issue 1 in database...\n";
    $upd_stmt = $pdo->prepare("UPDATE journals SET published_at = ? WHERE volume = '20' AND issue = '1' AND status = 'published'");
    $upd_stmt->execute([$formatted_date]);
    echo "✓ Database rows updated successfully.\n\n";
    
    // 3. Loop through all journals and regenerate their PDFs
    echo "Regenerating all manuscript PDFs with correct headers & footers...\n";
    $stmt = $pdo->query("SELECT id, journal_number, volume, issue, published_at, manuscript_file FROM journals");
    
    $success_count = 0;
    $fail_count = 0;
    $skip_count = 0;
    
    while ($row = $stmt->fetch()) {
        if (empty($row['manuscript_file'])) {
            echo "Skipped: ID {$row['id']} ({$row['journal_number']}) - No manuscript file.\n";
            $skip_count++;
            continue;
        }
        
        $pdf_path = __DIR__ . '/' . $row['manuscript_file'];
        if (!file_exists($pdf_path)) {
            echo "Skipped: ID {$row['id']} ({$row['journal_number']}) - PDF file not found at {$row['manuscript_file']}.\n";
            $skip_count++;
            continue;
        }
        
        echo "Regenerating ID {$row['id']} ({$row['journal_number']}) to Vol '{$row['volume']}', Issue '{$row['issue']}', Date '" . ($row['published_at'] ? date('F Y', strtotime($row['published_at'])) : 'None') . "'... ";
        $success = rjpes_regenerate_journal_pdf($row['id']);
        if ($success) {
            echo "✓ Success!\n";
            $success_count++;
        } else {
            echo "✗ Failed!\n";
            $fail_count++;
        }
    }
    
    echo "\nSummary:\n";
    echo "--------\n";
    echo "Successfully Regenerated: $success_count\n";
    echo "Failed: $fail_count\n";
    echo "Skipped: $skip_count\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=============================\n";
echo "DONE. Please delete sync_pdf_dates.php from the server for safety.\n";
