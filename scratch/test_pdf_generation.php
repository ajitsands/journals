<?php
/**
 * Temporary script to test PDF title encoding.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/pdf_helper.php';

header('Content-Type: text/plain; charset=utf-8');

$journal_id = 38;

try {
    $stmt = $pdo->prepare("SELECT j.*, u.fullname AS author_name, u.email AS author_email FROM journals j JOIN users u ON j.author_id = u.id WHERE j.id = ?");
    $stmt->execute([$journal_id]);
    $journal = $stmt->fetch();

    if (!$journal) {
        die("Journal not found.");
    }

    $title = html_entity_decode($journal['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    echo "Raw Title in PHP: " . $title . "\n";
    echo "Bytes of Title:\n";
    for ($i = 0; $i < strlen($title); $i++) {
        $c = $title[$i];
        echo "  [$i] character: " . var_export($c, true) . " (code: " . ord($c) . ")\n";
    }

    $pdf = new RJPES_PDF();
    
    // Use reflection to call private method sanitize_utf8_for_pdf
    $ref = new ReflectionClass('RJPES_PDF');
    $method = $ref->getMethod('sanitize_utf8_for_pdf');
    $method->setAccessible(true);
    
    $clean_title = $method->invoke($pdf, $title);
    echo "\nClean Title in PHP: " . $clean_title . "\n";
    echo "Bytes of Clean Title:\n";
    for ($i = 0; $i < strlen($clean_title); $i++) {
        $c = $clean_title[$i];
        echo "  [$i] character: " . var_export($c, true) . " (code: " . ord($c) . ")\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
