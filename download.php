<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pdf_helper.php';

/**
 * MIME type by file extension — no finfo extension needed.
 */
function getMimeByExtension(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'txt'  => 'text/plain',
        'rtf'  => 'application/rtf',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/** Stream a physical file to the browser and exit. */
function streamFile(string $file_path, string $dl_name): void {
    header('Content-Type: '        . getMimeByExtension($file_path));
    header('Content-Disposition: attachment; filename="' . $dl_name . '"');
    header('Content-Length: '      . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    readfile($file_path);
    exit;
}

/** Stream a raw string (PDF bytes) to the browser and exit. */
function streamRawPdf(string $data, string $dl_name): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $dl_name . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $data;
    exit;
}

// ── Input ────────────────────────────────────────────────────────────────────
$id   = isset($_GET['id'])   ? intval($_GET['id'])              : 0;
$type = isset($_GET['type']) ? trim($_GET['type'])              : 'article';

if ($id <= 0) die("Invalid journal ID.");
if (!in_array($type, ['certificate', 'article', 'acceptance', 'invoice'])) $type = 'article';

try {
    // ── Fetch journal ─────────────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT j.*, u.fullname AS author_name, u.email AS author_email
         FROM journals j JOIN users u ON j.author_id = u.id
         WHERE j.id = ?"
    );
    $stmt->execute([$id]);
    $journal = $stmt->fetch();
    if (!$journal) die("Journal not found.");

    // ── Authorization ─────────────────────────────────────────────────────────
    $is_authorized = false;
    if ($type === 'invoice') {
        // Strict: only author and admins can download invoice
        if (is_logged_in()) {
            $user = get_logged_in_user();
            if ($user['role'] === 'admin' || $user['id'] == $journal['author_id']) {
                $is_authorized = true;
            }
        }
    } else {
        if ($journal['status'] === 'published') {
            $is_authorized = true;
        } elseif (is_logged_in()) {
            $user = get_logged_in_user();
            if ($user['role'] === 'admin') {
                $is_authorized = true;
            } elseif ($user['id'] == $journal['author_id']) {
                if ($type === 'article') {
                    // Author can only download the article if payment is approved
                    $pay_stmt = $pdo->prepare("SELECT status FROM payments WHERE journal_id = ?");
                    $pay_stmt->execute([$id]);
                    $pay_status = $pay_stmt->fetchColumn();
                    if ($pay_status === 'approved') {
                        $is_authorized = true;
                    }
                } else {
                    $is_authorized = true;
                }
            } else {
                $rev = $pdo->prepare("SELECT id FROM reviewer_assignments WHERE journal_id=? AND reviewer_id=?");
                $rev->execute([$id, $user['id']]);
                if ($rev->fetch()) $is_authorized = true;
            }
        }
    }
    if (!$is_authorized) die("You are not authorized to download this file.");

    $base_name = "RJPES_" . str_replace('-', '_', $journal['journal_number']);

    // ════════════════════════════════════════════════════════════════════════
    // TYPE = acceptance  →  Generated Acceptance Letter PDF
    // ════════════════════════════════════════════════════════════════════════
    if ($type === 'acceptance') {
        $assign_stmt = $pdo->prepare("SELECT assigned_at FROM reviewer_assignments WHERE journal_id = ? ORDER BY assigned_at ASC LIMIT 1");
        $assign_stmt->execute([$id]);
        $assigned_at = $assign_stmt->fetchColumn();
        if (!$assigned_at) {
            die("Acceptance letter is not available yet as no verifier has been assigned.");
        }
        
        $pdf      = new RJPES_PDF();
        $pdf_data = $pdf->generateAcceptanceLetter($journal, $assigned_at);
        streamRawPdf($pdf_data, $base_name . '_Acceptance_Letter.pdf');
    }

    // ════════════════════════════════════════════════════════════════════════
    // TYPE = invoice  →  Generated GST Invoice PDF
    // ════════════════════════════════════════════════════════════════════════
    if ($type === 'invoice') {
        if (empty($journal['bill_number'])) {
            die("GST Invoice is not generated yet. Payments must be verified and approved first.");
        }
        
        // Fetch payment details
        $pay_stmt = $pdo->prepare("SELECT transaction_id, created_at, status FROM payments WHERE journal_id = ?");
        $pay_stmt->execute([$id]);
        $payment = $pay_stmt->fetch();
        if (!$payment || $payment['status'] !== 'approved') {
            die("GST Invoice is not generated yet. Payments must be verified and approved first.");
        }
        $journal['transaction_id'] = $payment['transaction_id'];
        $journal['payment_date'] = $payment['created_at'];
        
        $pdf      = new RJPES_PDF();
        $pdf_data = $pdf->generateGSTInvoice($journal);
        streamRawPdf($pdf_data, $base_name . '_GST_Invoice.pdf');
    }

    // ════════════════════════════════════════════════════════════════════════
    // TYPE = certificate  →  Generated cover/certificate PDF
    // ════════════════════════════════════════════════════════════════════════
    if ($type === 'certificate') {
        $pdf      = new RJPES_PDF();
        $pdf_data = $pdf->generate($journal);
        streamRawPdf($pdf_data, $base_name . '_Certificate.pdf');
    }

    // ════════════════════════════════════════════════════════════════════════
    // TYPE = article  →  Latest revision file → original file → cover PDF
    // ════════════════════════════════════════════════════════════════════════

    // Priority 1 – latest revision from journal_versions
    $ver = $pdo->prepare(
        "SELECT manuscript_file FROM journal_versions
         WHERE journal_id = ?
         ORDER BY version_number DESC, uploaded_at DESC LIMIT 1"
    );
    $ver->execute([$id]);
    $latest = $ver->fetch();

    if ($latest && !empty($latest['manuscript_file'])) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . ltrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $latest['manuscript_file']),
            DIRECTORY_SEPARATOR
        );
        if (file_exists($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            streamFile($path, $base_name . '_Article.' . $ext);
        }
    }

    // Priority 2 – original submitted manuscript
    if (!empty($journal['manuscript_file'])) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . ltrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $journal['manuscript_file']),
            DIRECTORY_SEPARATOR
        );
        if (file_exists($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            streamFile($path, $base_name . '_Article.' . $ext);
        }
    }

    // Priority 3 – no file on disk, fall back to generated PDF
    $pdf      = new RJPES_PDF();
    $pdf_data = $pdf->generate($journal);
    streamRawPdf($pdf_data, $base_name . '_Article.pdf');

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
