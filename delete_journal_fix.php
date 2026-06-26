<?php
/**
 * Temporary script to delete journal RJPES-2026-4108 and its associated files.
 * Run this by visiting: https://rjpes.in/delete_journal_fix.php?confirm=yes&key=sandslabs_delete_7813
 */

require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

// Simple access control key
$security_key = 'sandslabs_delete_7813';

if (!isset($_GET['key']) || $_GET['key'] !== $security_key) {
    http_response_code(403);
    die("Access Denied: Invalid security key.");
}

if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    die("To perform the deletion, append &confirm=yes to the URL.\nWARNING: This action is permanent!");
}

$journal_number = 'RJPES-2026-4108';

try {
    // 1. Fetch the journal details
    $stmt = $pdo->prepare("SELECT * FROM journals WHERE journal_number = ?");
    $stmt->execute([$journal_number]);
    $journal = $stmt->fetch();

    if (!$journal) {
        die("Journal $journal_number not found in the database. It may have already been deleted.\n");
    }

    $journal_id = $journal['id'];
    echo "Found Journal:\n";
    echo "ID: $journal_id\n";
    echo "Number: $journal_number\n";
    echo "Title: " . $journal['title'] . "\n\n";

    // 2. Identify all associated files
    $files_to_delete = [];

    // Main manuscript file
    if (!empty($journal['manuscript_file'])) {
        $files_to_delete['manuscript_file'] = __DIR__ . '/' . $journal['manuscript_file'];
    }

    // Author photo
    if (!empty($journal['author_photo'])) {
        $files_to_delete['author_photo'] = __DIR__ . '/' . $journal['author_photo'];
    }

    // Co-author photos
    $ca_stmt = $pdo->prepare("SELECT photo_path FROM journal_authors WHERE journal_id = ?");
    $ca_stmt->execute([$journal_id]);
    $co_author_photos = $ca_stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($co_author_photos as $index => $photo_path) {
        if (!empty($photo_path)) {
            $files_to_delete["co_author_photo_$index"] = __DIR__ . '/' . $photo_path;
        }
    }

    // Journal version manuscripts
    $v_stmt = $pdo->prepare("SELECT manuscript_file FROM journal_versions WHERE journal_id = ?");
    $v_stmt->execute([$journal_id]);
    $version_files = $v_stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($version_files as $index => $v_file) {
        if (!empty($v_file)) {
            $files_to_delete["journal_version_$index"] = __DIR__ . '/' . $v_file;
        }
    }

    // Payment proof files
    $p_stmt = $pdo->prepare("SELECT payment_proof FROM payments WHERE journal_id = ?");
    $p_stmt->execute([$journal_id]);
    $payment_proofs = $p_stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($payment_proofs as $index => $p_file) {
        if (!empty($p_file)) {
            $files_to_delete["payment_proof_$index"] = __DIR__ . '/' . $p_file;
        }
    }

    echo "Associated Files Found:\n";
    foreach ($files_to_delete as $key => $filepath) {
        $exists = file_exists($filepath) ? "EXISTS" : "DOES NOT EXIST";
        echo " - $key: $filepath ($exists)\n";
    }
    echo "\n";

    // 3. Unlink all files
    echo "Unlinking files from disk:\n";
    foreach ($files_to_delete as $key => $filepath) {
        if (file_exists($filepath) && is_file($filepath)) {
            if (unlink($filepath)) {
                echo " - Successfully deleted $key: $filepath\n";
            } else {
                echo " - [ERROR] Failed to delete $key: $filepath\n";
            }
        } else {
            echo " - Skipping (does not exist/not a file): $filepath\n";
        }
    }
    echo "\n";

    // 4. Perform database deletion
    echo "Deleting database record for journal ID: $journal_id...\n";
    $pdo->beginTransaction();

    $del_stmt = $pdo->prepare("DELETE FROM journals WHERE id = ?");
    $del_stmt->execute([$journal_id]);

    $pdo->commit();
    echo "Successfully deleted journal and all cascade records.\n\n";

    echo "ALL STEPS COMPLETED SUCCESSFULLY.\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n[ERROR] An error occurred: " . $e->getMessage() . "\n";
}
