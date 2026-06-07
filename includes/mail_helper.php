<?php
/**
 * RJPES Email Helper
 * Handles HTML email styling, standard PHP mail() sending, and visual logging of outbox items.
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Returns the absolute base URL of the RJPES installation.
 */
function rjpes_get_base_url() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:3031';
    
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $base_path = '/';
    if (!empty($script_name)) {
        $dir = dirname($script_name);
        $dir = str_replace('\\', '/', $dir);
        $dir = rtrim($dir, '/');
        if (preg_match('/(\/(author|reviewer|admin))$/i', $dir)) {
            $dir = preg_replace('/(\/(author|reviewer|admin))$/i', '', $dir);
        }
        $base_path = rtrim($dir, '/') . '/';
    }
    
    return $protocol . '://' . $host . $base_path;
}

/**
 * Returns a styled HTML email layout using RJPES theme.
 */
function rjpes_get_email_template($title, $content_html) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>' . htmlspecialchars($title) . '</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background-color: #f6f9fc;
                margin: 0;
                padding: 0;
                -webkit-font-smoothing: antialiased;
                color: #334155;
            }
            .email-container {
                max-width: 600px;
                margin: 20px auto;
                background-color: #ffffff;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 4px 12px rgba(0,0,0,0.05);
                border: 1px solid #e2e8f0;
            }
            .email-header {
                background-color: #0b2240;
                border-bottom: 4px solid #d4af37;
                padding: 25px 30px;
                text-align: center;
            }
            .email-header h1 {
                color: #ffffff;
                margin: 0;
                font-size: 18px;
                font-weight: 700;
                letter-spacing: 0.5px;
            }
            .email-header p {
                color: #d4af37;
                margin: 5px 0 0 0;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .email-body {
                padding: 30px;
                line-height: 1.6;
                font-size: 15px;
            }
            .email-body p {
                margin-top: 0;
                margin-bottom: 16px;
            }
            .details-box {
                background-color: #f8fafc;
                border: 1px solid #e2e8f0;
                border-left: 4px solid #0b2240;
                border-radius: 6px;
                padding: 16px;
                margin: 20px 0;
            }
            .details-table {
                width: 100%;
                border-collapse: collapse;
            }
            .details-table td {
                padding: 5px 0;
                vertical-align: top;
                font-size: 14px;
            }
            .details-table td.label {
                font-weight: 600;
                color: #475569;
                width: 140px;
            }
            .details-table td.value {
                color: #0f172a;
            }
            .btn {
                display: inline-block;
                background-color: #0b2240;
                color: #ffffff !important;
                text-decoration: none;
                padding: 10px 20px;
                border-radius: 6px;
                font-weight: 600;
                font-size: 13px;
                margin: 15px 0;
                text-align: center;
                border-bottom: 2px solid #d4af37;
            }
            .email-footer {
                background-color: #f1f5f9;
                padding: 20px;
                text-align: center;
                font-size: 11px;
                color: #64748b;
                border-top: 1px solid #e2e8f0;
            }
            .email-footer p {
                margin: 4px 0;
            }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="email-header">
                <h1>RJPES JOURNAL PORTAL</h1>
                <p>Research Journal on Physical Education and Sports</p>
            </div>
            <div class="email-body">
                ' . $content_html . '
            </div>
            <div class="email-footer">
                <p>&copy; ' . date('Y') . ' RJPES. All rights reserved.</p>
                <p>This is an automated notification. Please do not reply directly to this email.</p>
                <p>Contact: <a href="mailto:journals@rjpes.in" style="color: #0b2240; text-decoration: underline;">journals@rjpes.in</a></p>
            </div>
        </div>
    </body>
    </html>
    ';
}

/**
 * Core send mail wrapper.
 * Attempts native mail() and writes a visual HTML outbox log for local testing/debugging.
 */
function rjpes_send_mail($to_email, $to_name, $subject, $content_html) {
    $full_html = rjpes_get_email_template($subject, $content_html);

    // Standard SMTP/PHP headers for HTML mail
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: RJPES Editorial Office <editor@rjpes.in>\r\n";
    $headers .= "Reply-To: journals@rjpes.in\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // Attempt to send email
    @mail($to_email, $subject, $full_html, $headers);

    // Write to local outbox logs for verification
    $log_file = __DIR__ . '/../uploads/email_logs.html';
    $dir = dirname($log_file);
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
    }

    $card_html = '
    <div class="log-card">
        <div class="log-meta">
            <span class="meta-label">Logged At:</span> ' . date('Y-m-d H:i:s') . ' | 
            <span class="meta-label">Recipient Name:</span> ' . htmlspecialchars($to_name) . ' | 
            <span class="meta-label">Email:</span> ' . htmlspecialchars($to_email) . ' | 
            <span class="meta-label">Subject:</span> ' . htmlspecialchars($subject) . '
        </div>
        <div class="log-preview-btn" onclick="togglePreview(this)">👁 Toggle HTML Email Layout</div>
        <div class="log-preview-content" style="display:none; border:1px solid #cbd5e1; margin-top:10px; border-radius:6px; padding:10px; background:#f8fafc;">
            ' . $full_html . '
        </div>
    </div>';

    if (!file_exists($log_file)) {
        $initial_html = '
        <!DOCTYPE html>
        <html>
        <head>
            <title>RJPES Local Email Logs</title>
            <meta charset="utf-8">
            <style>
                body { font-family: sans-serif; background: #eef2f3; margin: 0; padding: 20px; color: #333; }
                .container { max-width: 850px; margin: 0 auto; }
                h1 { font-family: serif; color: #0b2240; border-bottom: 2px solid #d4af37; padding-bottom: 10px; margin-bottom: 5px; }
                .log-card { background: #fff; border-radius: 6px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); padding: 15px; margin-bottom: 15px; border-left: 5px solid #0b2240; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; }
                .log-meta { font-size: 0.88rem; margin-bottom: 8px; color: #475569; }
                .meta-label { font-weight: bold; color: #0b2240; }
                .log-preview-btn { display: inline-block; cursor: pointer; color: #2563eb; font-size: 0.85rem; text-decoration: underline; margin-bottom: 5px; font-weight: 500; }
            </style>
            <script>
                function togglePreview(btn) {
                    var content = btn.nextElementSibling;
                    if (content.style.display === "none") {
                        content.style.display = "block";
                        btn.textContent = "🙈 Hide HTML Email Layout";
                    } else {
                        content.style.display = "none";
                        btn.textContent = "👁 Toggle HTML Email Layout";
                    }
                }
            </script>
        </head>
        <body>
            <div class="container">
                <h1>RJPES Outbox Log Feed (Local Testing Only)</h1>
                <p style="font-size:0.85rem; color:#64748b; margin-bottom: 25px;">All outgoing notifications generated by the portal are captured and logged below (newest first). Click Toggle button to inspect the layout.</p>
                <div id="logs-container">' . $card_html . '</div>
            </div>
        </body>
        </html>';
        @file_put_contents($log_file, $initial_html);
    } else {
        $content = @file_get_contents($log_file);
        $placeholder = '<div id="logs-container">';
        $pos = strpos($content, $placeholder);
        if ($pos !== false) {
            $insert_pos = $pos + strlen($placeholder);
            $new_content = substr($content, 0, $insert_pos) . "\n" . $card_html . substr($content, $insert_pos);
            @file_put_contents($log_file, $new_content);
        } else {
            @file_put_contents($log_file, $card_html, FILE_APPEND);
        }
    }
    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// EVENT SPECIFIC NOTIFICATION TRIGGERS
// ══════════════════════════════════════════════════════════════════════════════

/** Triggered when a new journal is submitted */
function rjpes_mail_submission($journal, $author) {
    $subject = "[RJPES] Submission Received: " . $journal['journal_number'];
    $html = '
    <p>Dear <strong>' . htmlspecialchars($author['fullname']) . '</strong>,</p>
    <p>Thank you for submitting your research manuscript to the <strong>Research Journal on Physical Education and Sports (RJPES)</strong>.</p>
    <p>Your submission has been successfully received and is currently in the queue for administrative screening. You can track the status of your manuscript directly on your Author Dashboard.</p>
    
    <div class="details-box">
        <table class="details-table">
            <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
            <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
            <tr><td class="label">Subject Area:</td><td class="value">' . htmlspecialchars($journal['subject_domain']) . '</td></tr>
            <tr><td class="label">Submitted On:</td><td class="value">' . date('d F Y, h:i A', strtotime($journal['created_at'])) . '</td></tr>
        </table>
    </div>
    
    <p>We will keep you informed as your manuscript progresses through the editorial and peer evaluation workflow.</p>
    <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Access Author Portal</a>
    ';
    return rjpes_send_mail($author['email'], $author['fullname'], $subject, $html);
}

/** Triggered when an author submits a revised manuscript */
function rjpes_mail_revision($journal, $author, $version_number, $author_notes) {
    $subject = "[RJPES] Revision Received: " . $journal['journal_number'];
    $html = '
    <p>Dear <strong>' . htmlspecialchars($author['fullname']) . '</strong>,</p>
    <p>This is to confirm that the revised version (v' . intval($version_number) . ') of your manuscript has been successfully uploaded.</p>
    
    <div class="details-box">
        <table class="details-table">
            <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
            <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
            <tr><td class="label">Version Uploaded:</td><td class="value">Version ' . intval($version_number) . ' (Revision)</td></tr>
            <tr><td class="label">Your Revision Notes:</td><td class="value">"' . htmlspecialchars($author_notes) . '"</td></tr>
        </table>
    </div>
    
    <p>Your manuscript has been routed back to the verifier panel. We will communicate the final outcome to you upon completion of evaluation.</p>
    <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Access Author Portal</a>
    ';
    return rjpes_send_mail($author['email'], $author['fullname'], $subject, $html);
}

/** Triggered to notify verifiers about a new revision file uploaded */
function rjpes_mail_revision_to_verifier($journal, $verifier, $version_number, $author_notes) {
    $subject = "[RJPES] Revised Manuscript for Verification: " . $journal['journal_number'];
    $html = '
    <p>Dear <strong>' . htmlspecialchars($verifier['fullname']) . '</strong>,</p>
    <p>A revised version (v' . intval($version_number) . ') of the manuscript assigned to you for verification has been submitted by the author.</p>
    
    <div class="details-box">
        <table class="details-table">
            <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
            <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
            <tr><td class="label">Revision Notes:</td><td class="value">"' . htmlspecialchars($author_notes) . '"</td></tr>
        </table>
    </div>
    
    <p>Please log in to your Verifier Dashboard to review the revised manuscript and submit your final recommendation.</p>
    <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Go to Verifier Dashboard</a>
    ';
    return rjpes_send_mail($verifier['email'], $verifier['fullname'], $subject, $html);
}

/** Triggered when admin assigns a reviewer (sent to Author) */
function rjpes_mail_assignment_to_author($journal, $author) {
    $subject = "[RJPES] Peer Review Started & Acceptance Letter: " . $journal['journal_number'];
    $html = '
    <p>Dear <strong>' . htmlspecialchars($author['fullname']) . '</strong>,</p>
    <p>We are pleased to inform you that your manuscript has passed administrative screening and has been assigned to our peer verifiers for formal evaluation.</p>
    <p>Your official <strong>Letter of Acceptance for Review</strong> has been generated and is now available. You can view and download it directly from your Author Dashboard.</p>
    
    <div class="details-box">
        <table class="details-table">
            <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
            <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
            <tr><td class="label">Current Status:</td><td class="value">Under Peer Review</td></tr>
        </table>
    </div>
    
    <p><em>Please note: To ensure objective and blind peer evaluation, the identity of the assigned verifier remains confidential.</em></p>
    <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Download Acceptance Letter</a>
    ';
    return rjpes_send_mail($author['email'], $author['fullname'], $subject, $html);
}

/** Triggered when admin assigns a reviewer (sent to Verifier) */
function rjpes_mail_assignment_to_verifier($journal, $verifier) {
    $subject = "[RJPES] New Manuscript Assigned for Verification: " . $journal['journal_number'];
    $html = '
    <p>Dear <strong>' . htmlspecialchars($verifier['fullname']) . '</strong>,</p>
    <p>You have been assigned as the expert verifier for a newly submitted research manuscript. Please review the details below:</p>
    
    <div class="details-box">
        <table class="details-table">
            <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
            <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
            <tr><td class="label">Subject Domain:</td><td class="value">' . htmlspecialchars($journal['subject_domain']) . '</td></tr>
        </table>
    </div>
    
    <p><strong>Abstract Preview:</strong></p>
    <p style="font-style: italic; background: #f8fafc; padding: 12px; border-left: 4px solid #0b2240; font-size: 14px; color: #475569; line-height: 1.5;">
        "' . htmlspecialchars($journal['abstract']) . '"
    </p>
    
    <p>Please log in to the Verifier Portal to view the full manuscript draft, download the text, and submit your peer review recommendation.</p>
    <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Go to Verifier Portal</a>
    ';
    return rjpes_send_mail($verifier['email'], $verifier['fullname'], $subject, $html);
}

/** Triggered when verifier submits a review outcome recommendation */
function rjpes_mail_review_outcome($journal, $author, $recommendation, $comments) {
    $subject = "";
    $html = "";
    
    if ($recommendation === 'approve') {
        $subject = "[RJPES] Manuscript Peer Review Approved: " . $journal['journal_number'];
        $html = '
        <p>Dear <strong>' . htmlspecialchars($author['fullname']) . '</strong>,</p>
        <p>We are delighted to inform you that following evaluation by our verifier panel, your manuscript has been **Approved** for publication in RJPES!</p>
        <p>The Editorial Desk will now set the publication and processing fee. Once finalized, you will receive a notification to complete the payment and upload transaction proof.</p>
        
        <div class="details-box">
            <table class="details-table">
                <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
                <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
                <tr><td class="label">Review Outcome:</td><td class="value" style="color: #16a34a; font-weight:700;">Approved / Ready for Publish</td></tr>
            </table>
        </div>
        
        <p>Thank you for contributing your research to RJPES.</p>
        <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Access Author Portal</a>
        ';
    } elseif ($recommendation === 'revision') {
        $subject = "[RJPES] Revisions Required: " . $journal['journal_number'];
        $html = '
        <p>Dear <strong>' . htmlspecialchars($author['fullname']) . '</strong>,</p>
        <p>The verifier panel has completed evaluation of your manuscript and has requested **revisions** before publication can proceed.</p>
        <p>Please review the verifier feedback below, prepare your revised manuscript, and upload it via the Author Portal.</p>
        
        <div class="details-box">
            <table class="details-table">
                <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
                <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
                <tr><td class="label">Review Outcome:</td><td class="value" style="color: #d97706; font-weight:700;">Revisions Required</td></tr>
            </table>
        </div>
        
        <p><strong>Verifier Review Comments & Revisions Needed:</strong></p>
        <p style="background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #d97706; padding: 16px; border-radius: 6px; font-style: italic; font-size: 14px; color: #92400e; line-height: 1.5;">
            "' . htmlspecialchars($comments) . '"
        </p>
        
        <p>Please submit your revision at your earliest convenience to avoid publication delays.</p>
        <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Upload Revised Manuscript</a>
        ';
    } else { // reject
        $subject = "[RJPES] Editorial Decision - Manuscript Rejected: " . $journal['journal_number'];
        $html = '
        <p>Dear <strong>' . htmlspecialchars($author['fullname']) . '</strong>,</p>
        <p>We regret to inform you that following peer evaluation, the verifiers have recommended **rejection** of your manuscript. As a result, we cannot proceed with its publication in RJPES.</p>
        
        <div class="details-box">
            <table class="details-table">
                <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
                <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
                <tr><td class="label">Review Outcome:</td><td class="value" style="color: #dc2626; font-weight:700;">Rejected</td></tr>
            </table>
        </div>
        
        <p><strong>Verifier Review Comments:</strong></p>
        <p style="background: #fef2f2; border: 1px solid #fee2e2; border-left: 4px solid #dc2626; padding: 16px; border-radius: 6px; font-style: italic; font-size: 14px; color: #991b1b; line-height: 1.5;">
            "' . htmlspecialchars($comments) . '"
        </p>
        
        <p>We appreciate your interest in RJPES and wish you success with your future research work.</p>
        ';
    }
    return rjpes_send_mail($author['email'], $author['fullname'], $subject, $html);
}

/** Triggered when admin fixes publication fee (sent to Author) */
function rjpes_mail_fee_fixed($journal, $author) {
    $subject = "[RJPES] Publication Fee Payment Request: " . $journal['journal_number'];
    $html = '
    <p>Dear <strong>' . htmlspecialchars($author['fullname']) . '</strong>,</p>
    <p>The processing and publication fee for your approved manuscript has been finalized by editorial administration.</p>
    <p>Please review the details below, proceed to transfer the amount, and upload the transaction receipt/screenshot to proceed with publication.</p>
    
    <div class="details-box">
        <table class="details-table">
            <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
            <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
            <tr><td class="label">Required Amount:</td><td class="value" style="font-weight: 700; color: #0b2240; font-size: 16px;">₹' . number_format($journal['payment_amount'], 2) . '</td></tr>
        </table>
    </div>
    
    <p>Please log in to your Author Dashboard and click the "Pay Now" link to proceed to checkout and submission.</p>
    <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Proceed to Payment Portal</a>
    ';
    return rjpes_send_mail($author['email'], $author['fullname'], $subject, $html);
}

/** Triggered when author uploads payment proof (sent to Admin) */
function rjpes_mail_payment_submitted($journal, $author, $payment, $admin_email = 'admin@portal.com') {
    $subject = "[RJPES Admin] Payment Receipt Submitted: " . $journal['journal_number'];
    $html = '
    <p>Dear Administrator,</p>
    <p>The author <strong>' . htmlspecialchars($author['fullname']) . '</strong> has uploaded the payment proof for publication fee verification of manuscript <strong>' . htmlspecialchars($journal['journal_number']) . '</strong>.</p>
    
    <div class="details-box">
        <table class="details-table">
            <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
            <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
            <tr><td class="label">Transaction Ref No:</td><td class="value">' . htmlspecialchars($payment['transaction_id']) . '</td></tr>
            <tr><td class="label">Payment Method:</td><td class="value">' . htmlspecialchars(strtoupper(str_replace('_', ' ', $payment['payment_method'] ?? 'upi'))) . '</td></tr>
            <tr><td class="label">Fee Amount:</td><td class="value">₹' . number_format($journal['payment_amount'], 2) . '</td></tr>
        </table>
    </div>
    
    <p>Please log in to the Editorial Administration dashboard to inspect the submitted receipt proof and publish the manuscript.</p>
    <a href="' . rjpes_get_base_url() . 'login.php" class="btn">Access Admin Dashboard</a>
    ';
    return rjpes_send_mail($admin_email, 'System Administrator', $subject, $html);
}

/** Triggered when admin publishes journal (sent to Author) */
function rjpes_mail_published($journal, $author) {
    $subject = "[RJPES] Manuscript Published Successfully: " . $journal['journal_number'];
    $html = '
    <p>Dear <strong>' . htmlspecialchars($author['fullname']) . '</strong>,</p>
    <p>We are delighted to inform you that your research paper has been **officially published** in the <strong>Research Journal on Physical Education and Sports (RJPES)</strong>!</p>
    <p>Your publication is now available in RJPES online repository. You can download your official **Certificate of Publication** and the final **Published Article** from your dashboard.</p>
    
    <div class="details-box">
        <table class="details-table">
            <tr><td class="label">Manuscript ID:</td><td class="value">' . htmlspecialchars($journal['journal_number']) . '</td></tr>
            <tr><td class="label">Paper Title:</td><td class="value"><em>' . htmlspecialchars($journal['title']) . '</em></td></tr>
            <tr><td class="label">Published Edition:</td><td class="value" style="font-weight:600; color:#0b2240;">Volume ' . htmlspecialchars($journal['volume']) . ', Issue ' . htmlspecialchars($journal['issue']) . '</td></tr>
            <tr><td class="label">Publication Date:</td><td class="value">' . date('d F Y', strtotime($journal['published_at'] ?? date('Y-m-d'))) . '</td></tr>
        </table>
    </div>
    
    <p>Congratulations on your publication! Thank you for choosing RJPES to share your work.</p>
    <a href="' . rjpes_get_base_url() . 'login.php" class="btn">View on Author Dashboard</a>
    ';
    return rjpes_send_mail($author['email'], $author['fullname'], $subject, $html);
}
?>
