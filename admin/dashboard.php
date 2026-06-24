<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$message = "";
$message_type = "";

// Fetch global default cut percentages
try {
    $set_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    while ($row = $set_stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $default_v_pct = floatval($settings['verifier_cut_pct'] ?? 50);
    $default_a_pct = floatval($settings['admin_cut_pct'] ?? 20);
    $default_p_pct = floatval($settings['portal_cut_pct'] ?? 30);
    $default_min_fee = floatval($settings['min_processing_fee'] ?? 1000);
    $default_gst_pct = floatval($settings['gst_percentage'] ?? 18);
    $default_gst_mode = in_array($settings['gst_mode'] ?? 'exclude', ['include', 'exclude']) ? $settings['gst_mode'] : 'exclude';
} catch (PDOException $e) {
    $default_v_pct = 50;
    $default_a_pct = 20;
    $default_p_pct = 30;
    $default_min_fee = 1000;
    $default_gst_pct = 18;
    $default_gst_mode = 'exclude';
}

// 1. Handle Reviewer Assignment / Reassignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_reviewer'])) {
    $journal_id = intval($_POST['journal_id']);
    $reviewer_id = intval($_POST['reviewer_id']);
    
    if ($journal_id > 0 && $reviewer_id > 0) {
        try {
            $pdo->beginTransaction();
            
            // Check if already assigned (pending review)
            $chk = $pdo->prepare("SELECT id, reviewer_id FROM reviewer_assignments WHERE journal_id = ? AND status = 'assigned'");
            $chk->execute([$journal_id]);
            $existing = $chk->fetch();
            
            if ($existing) {
                if ($existing['reviewer_id'] == $reviewer_id) {
                    $message = "This verifier is already assigned to this manuscript.";
                    $message_type = "warning";
                    $pdo->rollBack();
                } else {
                    // Update reviewer assignment
                    $stmt = $pdo->prepare("UPDATE reviewer_assignments SET reviewer_id = ?, assigned_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$reviewer_id, $existing['id']]);
                    
                    $pdo->commit();
                    $message = "Verifier reassigned successfully.";
                    $message_type = "success";
                    
                    // Send assignment emails
                    try {
                        require_once __DIR__ . '/../includes/mail_helper.php';
                        $j_stmt = $pdo->prepare("SELECT j.*, u.fullname AS author_name, u.email AS author_email FROM journals j JOIN users u ON j.author_id = u.id WHERE j.id = ?");
                        $j_stmt->execute([$journal_id]);
                        $j_info = $j_stmt->fetch();
                        
                        $v_stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
                        $v_stmt->execute([$reviewer_id]);
                        $v_info = $v_stmt->fetch();
                        
                        if ($j_info && $v_info) {
                            $author_obj = ['fullname' => $j_info['author_name'], 'email' => $j_info['author_email']];
                            rjpes_mail_assignment_to_author($j_info, $author_obj);
                            rjpes_mail_assignment_to_verifier($j_info, $v_info);
                        }
                    } catch (Exception $ex) {
                        // ignore email error
                    }
                }
            } else {
                // Add new assignment
                $stmt = $pdo->prepare("INSERT INTO reviewer_assignments (journal_id, reviewer_id) VALUES (?, ?)");
                $stmt->execute([$journal_id, $reviewer_id]);
                
                // Update journal status to under_review
                $stmt2 = $pdo->prepare("UPDATE journals SET status = 'under_review' WHERE id = ?");
                $stmt2->execute([$journal_id]);
                
                $pdo->commit();
                $message = "Verifier assigned and manuscript moved to 'Under Review' status successfully.";
                $message_type = "success";
                
                // Send assignment emails
                try {
                    require_once __DIR__ . '/../includes/mail_helper.php';
                    $j_stmt = $pdo->prepare("SELECT j.*, u.fullname AS author_name, u.email AS author_email FROM journals j JOIN users u ON j.author_id = u.id WHERE j.id = ?");
                    $j_stmt->execute([$journal_id]);
                    $j_info = $j_stmt->fetch();
                    
                    $v_stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
                    $v_stmt->execute([$reviewer_id]);
                    $v_info = $v_stmt->fetch();
                    
                    if ($j_info && $v_info) {
                        $author_obj = ['fullname' => $j_info['author_name'], 'email' => $j_info['author_email']];
                        rjpes_mail_assignment_to_author($j_info, $author_obj);
                        rjpes_mail_assignment_to_verifier($j_info, $v_info);
                    }
                } catch (Exception $ex) {
                    // ignore email error
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Assignment failed: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// 2. Handle Fixing Payment Fee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_payment'])) {
    $journal_id = intval($_POST['journal_id']);
    $amount = floatval($_POST['amount']);
    $gst_type = isset($_POST['gst_type']) && $_POST['gst_type'] === 'include' ? 'include' : 'exclude';
    $v_cut = isset($_POST['verifier_cut']) && $_POST['verifier_cut'] !== '' ? floatval($_POST['verifier_cut']) : null;
    $a_cut = isset($_POST['admin_cut']) && $_POST['admin_cut'] !== '' ? floatval($_POST['admin_cut']) : null;
    $p_cut = isset($_POST['portal_cut']) && $_POST['portal_cut'] !== '' ? floatval($_POST['portal_cut']) : null;
    
    if ($journal_id > 0 && $amount >= 0) {
        try {
            $gst_rate_factor = 1 + ($default_gst_pct / 100);
            
            if ($v_cut === null || $a_cut === null || $p_cut === null) {
                if ($gst_type === 'include') {
                    $payment_amount = $amount;
                    $base_amount = $amount / $gst_rate_factor;
                    $gst_amount = $amount - $base_amount;
                } else {
                    $base_amount = $amount;
                    $gst_amount = $amount * ($default_gst_pct / 100);
                    $payment_amount = $amount + $gst_amount;
                }
                
                $v_cut = $base_amount * ($default_v_pct / 100);
                $a_cut = $base_amount * ($default_a_pct / 100);
                $p_cut = $base_amount * ($default_p_pct / 100);
            } else {
                $base_amount = $v_cut + $a_cut + $p_cut;
                $gst_amount = $base_amount * ($default_gst_pct / 100);
                $payment_amount = $base_amount + $gst_amount;
            }
            
            $stmt = $pdo->prepare("UPDATE journals SET payment_amount = ?, base_amount = ?, gst_type = ?, gst_amount = ?, verifier_cut = ?, admin_cut = ?, portal_cut = ?, status = 'payment_pending' WHERE id = ?");
            $stmt->execute([$payment_amount, $base_amount, $gst_type, $gst_amount, $v_cut, $a_cut, $p_cut, $journal_id]);
            $message = "Publication fee set to ₹" . number_format($payment_amount, 2) . " (Base: ₹" . number_format($base_amount, 2) . ", GST (" . $default_gst_pct . "%): ₹" . number_format($gst_amount, 2) . "). Split: Verifier ₹" . number_format($v_cut, 2) . ", Admin ₹" . number_format($a_cut, 2) . ", Portal ₹" . number_format($p_cut, 2) . "). Author has been requested to submit payment.";
            $message_type = "success";
            
            // Send payment request email to Author
            try {
                require_once __DIR__ . '/../includes/mail_helper.php';
                $j_stmt = $pdo->prepare("SELECT j.*, u.fullname AS author_name, u.email AS author_email FROM journals j JOIN users u ON j.author_id = u.id WHERE j.id = ?");
                $j_stmt->execute([$journal_id]);
                $j_info = $j_stmt->fetch();
                if ($j_info) {
                    $author_obj = ['fullname' => $j_info['author_name'], 'email' => $j_info['author_email']];
                    rjpes_mail_fee_fixed($j_info, $author_obj);
                }
            } catch (Exception $ex) {
                // ignore email error
            }
        } catch (PDOException $e) {
            $message = "Failed to set fee: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// 3. Handle Verify Payment & Publish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_journal'])) {
    $journal_id = intval($_POST['journal_id']);
    $volume = sanitize($_POST['volume']);
    $issue = sanitize($_POST['issue']);
    $publication_date = sanitize($_POST['publication_date'] ?? '');
    
    if ($journal_id > 0 && !empty($volume) && !empty($issue) && !empty($publication_date)) {
        try {
            $pdo->beginTransaction();
            
            // Update payment record to approved
            $upd_pay = $pdo->prepare("UPDATE payments SET status = 'approved', verified_by = ? WHERE journal_id = ?");
            $upd_pay->execute([$_SESSION['user_id'], $journal_id]);
            
            $pub_at = !empty($publication_date) ? $publication_date : date('Y-m-d H:i:s');
            
            // Generate bill number if not already set
            $chk_bill = $pdo->prepare("SELECT bill_number FROM journals WHERE id = ?");
            $chk_bill->execute([$journal_id]);
            $curr_bill = $chk_bill->fetchColumn();

            if (empty($curr_bill)) {
                $pub_time = strtotime($pub_at);
                $month = intval(date('m', $pub_time));
                $year = intval(date('Y', $pub_time));
                $year_short = intval(date('y', $pub_time));
                
                if ($month >= 4) {
                    $fy_start = "$year-04-01 00:00:00";
                    $fy_end = ($year + 1) . "-03-31 23:59:59";
                    $fy_label = $year_short . '-' . ($year_short + 1);
                } else {
                    $fy_start = ($year - 1) . "-04-01 00:00:00";
                    $fy_end = "$year-03-31 23:59:59";
                    $fy_label = ($year_short - 1) . '-' . $year_short;
                }
                
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM journals WHERE bill_number IS NOT NULL AND bill_number != '' AND published_at BETWEEN ? AND ?");
                $stmt_count->execute([$fy_start, $fy_end]);
                $count = intval($stmt_count->fetchColumn());
                $next_seq = sprintf("%04d", $count + 1);

                $stmt_fmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'bill_format'");
                $stmt_fmt->execute();
                $bill_format = $stmt_fmt->fetchColumn();
                if (!$bill_format) {
                    $bill_format = 'SAN/INV/ONLINE/{FY}/{SEQ}';
                }
                
                $bill_number = str_replace(['{FY}', '{SEQ}'], [$fy_label, $next_seq], $bill_format);
                
                $upd_journ_bill = $pdo->prepare("UPDATE journals SET bill_number = ? WHERE id = ?");
                $upd_journ_bill->execute([$bill_number, $journal_id]);
            }
            
            // Update journal status to published
            $upd_journ = $pdo->prepare("UPDATE journals SET status = 'published', volume = ?, issue = ?, published_at = ? WHERE id = ?");
            $upd_journ->execute([$volume, $issue, $pub_at, $journal_id]);
            
            // Regenerate the PDF cover and headers/footers with the new volume, issue, and publication date
            try {
                require_once __DIR__ . '/../includes/word_helper.php';
                rjpes_regenerate_journal_pdf($journal_id);
            } catch (Exception $pdf_ex) {
                // Keep the database update even if PDF regeneration has minor issues, but log it
                error_log("Failed to regenerate PDF on publish for journal $journal_id: " . $pdf_ex->getMessage());
            }
            
            // Record Wallet Ledger Transactions
            // A. Get the assigned verifier (reviewer) who reviewed the paper
            $stmt_rev = $pdo->prepare("SELECT reviewer_id FROM reviewer_assignments WHERE journal_id = ? AND status = 'reviewed' ORDER BY assigned_at DESC LIMIT 1");
            $stmt_rev->execute([$journal_id]);
            $rev_row = $stmt_rev->fetch();
            $reviewer_id = $rev_row ? intval($rev_row['reviewer_id']) : null;
            
            // B. Fetch journal details including cuts
            $stmt_j = $pdo->prepare("SELECT journal_number, payment_amount, base_amount, gst_type, verifier_cut, admin_cut, portal_cut FROM journals WHERE id = ?");
            $stmt_j->execute([$journal_id]);
            $j_row = $stmt_j->fetch();
            
            $total = floatval($j_row['payment_amount']);
            $base_amount = floatval($j_row['base_amount'] ?: 0);
            if ($base_amount <= 0) {
                $gst_type = $j_row['gst_type'] ?: 'exclude';
                $gst_rate_factor = 1 + ($default_gst_pct / 100);
                if ($gst_type === 'include') {
                    $base_amount = $total / $gst_rate_factor;
                } else {
                    $base_amount = $total;
                }
            }
            $v_cut = $j_row['verifier_cut'];
            $a_cut = $j_row['admin_cut'];
            $p_cut = $j_row['portal_cut'];
            
            // Fallback to default algorithm if cuts are not set (calculated off base_amount)
            if ($v_cut === null || $a_cut === null || $p_cut === null) {
                $v_cut = $base_amount * ($default_v_pct / 100);
                $a_cut = $base_amount * ($default_a_pct / 100);
                $p_cut = $base_amount * ($default_p_pct / 100);
            }
            
            $ins_trans = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, transaction_type, description) VALUES (?, ?, 'credit', ?)");
            
            // C. Credit Verifier
            if ($reviewer_id && $v_cut > 0) {
                $ins_trans->execute([$reviewer_id, $v_cut, "Verifier commission for " . $j_row['journal_number']]);
            }
            
            // D. Credit Admin (the one who verified)
            if ($a_cut > 0) {
                $ins_trans->execute([$_SESSION['user_id'], $a_cut, "Admin verification commission for " . $j_row['journal_number']]);
            }
            
            // E. Credit Portal (user_id = null)
            if ($p_cut > 0) {
                $ins_trans->execute([null, $p_cut, "Portal platform commission for " . $j_row['journal_number']]);
            }
            
            $pdo->commit();
            $message = "Payment verified and credited to wallets successfully. Journal has been officially PUBLISHED in Vol. $volume, Issue $issue!";
            $message_type = "success";
            
            // Send published email to Author
            try {
                require_once __DIR__ . '/../includes/mail_helper.php';
                $j_stmt = $pdo->prepare("SELECT j.*, u.fullname AS author_name, u.email AS author_email FROM journals j JOIN users u ON j.author_id = u.id WHERE j.id = ?");
                $j_stmt->execute([$journal_id]);
                $j_info = $j_stmt->fetch();
                if ($j_info) {
                    $author_obj = ['fullname' => $j_info['author_name'], 'email' => $j_info['author_email']];
                    rjpes_mail_published($j_info, $author_obj);
                }
            } catch (Exception $ex) {
                // ignore email error
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Publication failed: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// 4. Handle Edit Publication Info (for already-published journals)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_publication'])) {
    $journal_id = intval($_POST['journal_id']);
    $volume = sanitize($_POST['volume']);
    $issue = sanitize($_POST['issue']);
    $publication_date = sanitize($_POST['publication_date'] ?? '');

    if ($journal_id > 0 && !empty($volume) && !empty($issue) && !empty($publication_date)) {
        try {
            $upd_journ = $pdo->prepare("UPDATE journals SET volume = ?, issue = ?, published_at = ? WHERE id = ? AND status = 'published'");
            $upd_journ->execute([$volume, $issue, $publication_date, $journal_id]);
            
            // Regenerate the PDF cover and headers/footers with the updated volume, issue, and publication date
            try {
                require_once __DIR__ . '/../includes/word_helper.php';
                rjpes_regenerate_journal_pdf($journal_id);
            } catch (Exception $pdf_ex) {
                error_log("Failed to regenerate PDF on edit publication for journal $journal_id: " . $pdf_ex->getMessage());
            }
            
            $message = "Publication info updated successfully. Journal is now listed as Vol. $volume, Issue $issue.";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Update failed: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "All fields (Volume, Issue, Publication Date) are required.";
        $message_type = "warning";
    }
}

// 5. Handle Edit Submission Date
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_submission_date'])) {
    $journal_id = intval($_POST['journal_id']);
    $submission_date = sanitize($_POST['submission_date'] ?? '');

    if ($journal_id > 0 && !empty($submission_date)) {
        try {
            $formatted_date = date('Y-m-d H:i:s', strtotime($submission_date));
            $upd_journ = $pdo->prepare("UPDATE journals SET created_at = ? WHERE id = ?");
            $upd_journ->execute([$formatted_date, $journal_id]);
            
            // Regenerate PDF cover/metadata
            try {
                require_once __DIR__ . '/../includes/word_helper.php';
                rjpes_regenerate_journal_pdf($journal_id);
            } catch (Exception $pdf_ex) {
                error_log("Failed to regenerate PDF on edit submission date for journal $journal_id: " . $pdf_ex->getMessage());
            }
            
            $message = "Submission date updated successfully.";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Update failed: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Submission date is required.";
        $message_type = "warning";
    }
}

// 6. Handle Reverting Publication to Author to Pay
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revert_publication'])) {
    $journal_id = intval($_POST['journal_id']);
    
    if ($journal_id > 0) {
        try {
            $pdo->beginTransaction();
            
            // Fetch journal number and verify status is published
            $stmt_chk = $pdo->prepare("SELECT journal_number, status FROM journals WHERE id = ?");
            $stmt_chk->execute([$journal_id]);
            $j_info = $stmt_chk->fetch();
            
            if ($j_info && $j_info['status'] === 'published') {
                $journal_number = $j_info['journal_number'];
                
                // A. Delete payment proof file from disk
                $p_stmt = $pdo->prepare("SELECT payment_proof FROM payments WHERE journal_id = ?");
                $p_stmt->execute([$journal_id]);
                $p_file = $p_stmt->fetchColumn();
                if (!empty($p_file)) {
                    $proof_full_path = __DIR__ . '/../' . $p_file;
                    if (file_exists($proof_full_path)) {
                        @unlink($proof_full_path);
                    }
                }
                
                // B. Delete payment record from payments table
                $del_pay = $pdo->prepare("DELETE FROM payments WHERE journal_id = ?");
                $del_pay->execute([$journal_id]);
                
                // C. Update journal status back to 'payment_pending' and reset volume/issue/published_at/bill_number
                $upd_journ = $pdo->prepare("UPDATE journals SET status = 'payment_pending', volume = NULL, issue = NULL, published_at = NULL, bill_number = NULL WHERE id = ?");
                $upd_journ->execute([$journal_id]);
                
                // D. Delete wallet ledger credits for this journal
                $del_trans = $pdo->prepare("DELETE FROM wallet_transactions WHERE description IN (
                    CONCAT('Verifier commission for ', :jn1),
                    CONCAT('Admin verification commission for ', :jn2),
                    CONCAT('Portal platform commission for ', :jn3)
                )");
                $del_trans->execute([
                    'jn1' => $journal_number,
                    'jn2' => $journal_number,
                    'jn3' => $journal_number
                ]);
                
                // E. Re-generate the PDF cover page and body header/footers without publication info
                try {
                    require_once __DIR__ . '/../includes/word_helper.php';
                    rjpes_regenerate_journal_pdf($journal_id);
                } catch (Exception $pdf_ex) {
                    error_log("Failed to regenerate PDF on revert publication for journal $journal_id: " . $pdf_ex->getMessage());
                }
                
                $pdo->commit();
                $message = "Journal manuscript reverted to 'Payment Pending' (Author to Pay) status successfully. Wallet commissions reversed and GST invoice canceled.";
                $message_type = "success";
            } else {
                $pdo->rollBack();
                $message = "Only published journals can be reverted.";
                $message_type = "warning";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Failed to revert publication: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Fetch admin dashboard stats
try {
    $current_vol = rjpes_get_setting('current_volume', '20');
    $current_issue = rjpes_get_setting('current_issue', '1');
    $current_edition_date = rjpes_get_setting('current_edition_date', date('Y-m-d'));
    
    // Submissions for the current Volume
    $stmt_vol = $pdo->prepare("SELECT COUNT(*) FROM journals WHERE volume = ?");
    $stmt_vol->execute([$current_vol]);
    $submissions_current_vol = intval($stmt_vol->fetchColumn());
    
    // Under Process (not published and not rejected)
    $stmt_proc = $pdo->query("SELECT COUNT(*) FROM journals WHERE status NOT IN ('published', 'rejected')");
    $under_process_count = intval($stmt_proc->fetchColumn());
    
    // Completed Count (published)
    $stmt_comp = $pdo->query("SELECT COUNT(*) FROM journals WHERE status = 'published'");
    $completed_count = intval($stmt_comp->fetchColumn());
    
    // Total Amount Collected (sum of payment_amount from journals where payments.status = 'approved')
    $stmt_amt = $pdo->query("SELECT COALESCE(SUM(j.payment_amount), 0) FROM journals j JOIN payments p ON j.id = p.journal_id WHERE p.status = 'approved'");
    $total_collected = floatval($stmt_amt->fetchColumn());

    // Amount to Be Collected (journals in payment_pending status)
    $stmt_pending = $pdo->query("SELECT COALESCE(SUM(payment_amount), 0) FROM journals WHERE status = 'payment_pending'");
    $pending_collection = floatval($stmt_pending->fetchColumn());

    // Calculate payouts/allocations from actual approved journal cuts to match the Total Collected
    $total_paid_verifier = 0.00;
    $total_paid_admin = 0.00;
    $total_paid_portal = 0.00;
    
    $stmt_cuts = $pdo->query("SELECT j.payment_amount, j.verifier_cut, j.admin_cut, j.portal_cut FROM journals j JOIN payments p ON j.id = p.journal_id WHERE p.status = 'approved'");
    while ($row = $stmt_cuts->fetch()) {
        $amount = floatval($row['payment_amount']);
        $v = $row['verifier_cut'];
        $a = $row['admin_cut'];
        $p = $row['portal_cut'];
        
        if ($v === null || $a === null || $p === null) {
            $v = $amount * 0.50;
            $a = $amount * 0.20;
            $p = $amount * 0.30;
        }
        
        $total_paid_verifier += floatval($v);
        $total_paid_admin += floatval($a);
        $total_paid_portal += floatval($p);
    }
} catch (PDOException $e) {
    $submissions_current_vol = 0;
    $under_process_count = 0;
    $completed_count = 0;
    $total_collected = 0.00;
    $pending_collection = 0.00;
    $total_paid_verifier = 0.00;
    $total_paid_admin = 0.00;
    $total_paid_portal = 0.00;
}

// Fetch all journals with the most recent reviewer assignment
try {
    $stmt = $pdo->query("SELECT j.*, u.fullname as author_name, 
                        p.transaction_id, p.payment_proof, p.status as payment_status,
                        ra.id as assignment_id,
                        ra.reviewer_id as assigned_reviewer_id,
                        ra.status as assignment_status,
                        u2.fullname as assigned_reviewer,
                        (SELECT MAX(jv.version_number) FROM journal_versions jv WHERE jv.journal_id = j.id) as current_version
                        FROM journals j 
                        JOIN users u ON j.author_id = u.id 
                        LEFT JOIN reviewer_assignments ra ON j.id = ra.journal_id AND ra.id = (SELECT MAX(ra2.id) FROM reviewer_assignments ra2 WHERE ra2.journal_id = j.id)
                        LEFT JOIN users u2 ON ra.reviewer_id = u2.id
                        LEFT JOIN payments p ON j.id = p.journal_id
                        ORDER BY j.created_at DESC");
    $journals = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database query failed: " . $e->getMessage());
}

// Fetch all registered reviewers for assignments dropdown
try {
    $rev_stmt = $pdo->query("SELECT id, fullname, subject_domain FROM users WHERE role = 'reviewer' ORDER BY fullname ASC");
    $reviewers = $rev_stmt->fetchAll();
} catch (PDOException $e) {
    $reviewers = [];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <!-- Main Content -->
    <main class="main-content" style="width: 100%;">
        <div style="margin-bottom: 2rem;">
            <h2 style="font-family: var(--font-heading); color: var(--primary-color);">Editorial Administration</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Oversee reviews, process publication fees, and manage issues</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php if ($message_type == 'success'): ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php endif; ?>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <!-- Statistics Panel -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
            <!-- Card 1: Submissions in Current Volume -->
            <div class="card" style="padding: 1.5rem; display: flex; align-items: center; gap: 15px; border-left: 4px solid var(--primary-color); margin-bottom: 0;">
                <div style="font-size: 2rem; background: #eff6ff; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    📚
                </div>
                <div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">Vol. <?php echo sanitize($current_vol); ?> Submissions</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2px;">
                        <?php echo $submissions_current_vol; ?>
                    </div>
                </div>
            </div>

            <!-- Card 2: Under Process -->
            <div class="card" style="padding: 1.5rem; display: flex; align-items: center; gap: 15px; border-left: 4px solid var(--warning-color); margin-bottom: 0;">
                <div style="font-size: 2rem; background: #fffbeb; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    ⏳
                </div>
                <div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">Under Process</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2px;">
                        <?php echo $under_process_count; ?>
                    </div>
                </div>
            </div>

            <!-- Card 3: Completed Count -->
            <div class="card" style="padding: 1.5rem; display: flex; align-items: center; gap: 15px; border-left: 4px solid var(--success-color); margin-bottom: 0;">
                <div style="font-size: 2rem; background: #ecfdf5; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    ✅
                </div>
                <div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">Completed (Published)</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2px;">
                        <?php echo $completed_count; ?>
                    </div>
                </div>
            </div>

            <!-- Card 4: Total Amount Collected -->
            <div class="card" style="padding: 1.5rem; display: flex; align-items: center; gap: 15px; border-left: 4px solid var(--accent-color); margin-bottom: 0;">
                <div style="font-size: 2rem; background: #fef3c7; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    💰
                </div>
                <div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">Total Amount Collected</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2px;">
                        ₹<?php echo number_format($total_collected, 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Panel - Wallet Splits -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
            <!-- Card 5: Amount Need to Be Collected -->
            <div class="card" style="padding: 1.5rem; display: flex; align-items: center; gap: 15px; border-left: 4px solid #f97316; margin-bottom: 0;">
                <div style="font-size: 2rem; background: #fff7ed; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    💳
                </div>
                <div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">Pending Collection</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2px;">
                        ₹<?php echo number_format($pending_collection, 2); ?>
                    </div>
                </div>
            </div>

            <!-- Card 6: Total Paid to Verifiers -->
            <div class="card" style="padding: 1.5rem; display: flex; align-items: center; gap: 15px; border-left: 4px solid var(--info-color); margin-bottom: 0;">
                <div style="font-size: 2rem; background: #e0f2fe; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    🛡️
                </div>
                <div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">Total Paid to Verifiers</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2px;">
                        ₹<?php echo number_format($total_paid_verifier, 2); ?>
                    </div>
                </div>
            </div>

            <!-- Card 7: Total Paid to Admins -->
            <div class="card" style="padding: 1.5rem; display: flex; align-items: center; gap: 15px; border-left: 4px solid var(--primary-light); margin-bottom: 0;">
                <div style="font-size: 2rem; background: #f1f5f9; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    💼
                </div>
                <div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">Total Paid to Admins</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2px;">
                        ₹<?php echo number_format($total_paid_admin, 2); ?>
                    </div>
                </div>
            </div>

            <!-- Card 8: Total Paid to Portal -->
            <div class="card" style="padding: 1.5rem; display: flex; align-items: center; gap: 15px; border-left: 4px solid var(--success-color); margin-bottom: 0;">
                <div style="font-size: 2rem; background: #dcfce7; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    🌐
                </div>
                <div>
                    <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">Total Paid to Portal</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color); margin-top: 2px;">
                        ₹<?php echo number_format($total_paid_portal, 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="padding: 1.5rem;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1.5rem;">All Journal Entries</h3>
            
            <?php if (empty($journals)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.7;">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>
                    </svg>
                    <p style="font-weight: 500;">No submissions exist in the portal database yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Journal ID</th>
                                <th>Manuscript Title</th>
                                <th>Author / Domain</th>
                                <th>Submitted On</th>
                                <th>Status</th>
                                <th>Verifier Assignment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($journals as $j): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--primary-color);">
                                        <?php echo sanitize($j['journal_number']); ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;">
                                            <a href="<?php echo $path_prefix; ?>journal-detail.php?id=<?php echo $j['id']; ?>" target="_blank">
                                                <?php echo sanitize($j['title']); ?>
                                            </a>
                                        </div>
                                        <div style="margin-top: 4px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                            <?php
                                            $a_ver = intval($j['current_version'] ?? 1);
                                            $a_revised = $a_ver > 1;
                                            $a_dl_label  = $a_revised ? '⬇ View v' . $a_ver . ' (Revised)' : '⬇ View Document';
                                            $a_dl_style  = $a_revised
                                                ? 'font-size: 0.75rem; font-weight: 700; text-decoration: none; color: #92400e; background: #fef3c7; padding: 3px 8px; border-radius: 4px; border: 1px solid #fde68a; display: inline-flex; align-items: center; gap: 4px;'
                                                : 'font-size: 0.75rem; text-decoration: underline; color: var(--primary-light); display: inline-flex; align-items: center; gap: 4px;';
                                            ?>
                                            <a href="<?php echo $path_prefix . $j['manuscript_file']; ?>" target="_blank"
                                               style="<?php echo $a_dl_style; ?>">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                <?php echo $a_dl_label; ?>
                                            </a>
                                            <button onclick="openTimeline(<?php echo $j['id']; ?>, '<?php echo addslashes(sanitize($j['journal_number'])); ?>')" style="background: none; border: 1px solid #94a3b8; color: #475569; font-size: 0.72rem; cursor: pointer; padding: 2px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; font-weight: 500;">
                                                📄 Doc History
                                            </button>
                                            <?php if (!empty($j['assigned_reviewer_id'])): ?>
                                                <a href="<?php echo $path_prefix; ?>download.php?id=<?php echo $j['id']; ?>&type=acceptance" target="_blank"
                                                   style="font-size: 0.72rem; text-decoration: none; color: #047857; background: #ecfdf5; border: 1px solid #059669; padding: 3px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; font-weight: 600;">
                                                    📩 Acceptance Letter
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.9rem;"><?php echo sanitize($j['author_name']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;"><?php echo sanitize($j['subject_domain']); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.82rem; font-weight: 600; color: var(--primary-color);">
                                            <?php echo date('d M Y', strtotime($j['created_at'])); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                                            🕐 <?php echo date('h:i A', strtotime($j['created_at'])); ?>
                                        </div>
                                        <div style="margin-top: 5px;">
                                            <button onclick="openEditDateModal(<?php echo $j['id']; ?>, '<?php echo addslashes(sanitize($j['journal_number'])); ?>', '<?php echo date('Y-m-d\TH:i', strtotime($j['created_at'])); ?>')" 
                                                    style="background: none; border: 1px solid #94a3b8; color: #475569; font-size: 0.7rem; cursor: pointer; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; font-weight: 500;">
                                                ✏️ Edit Date
                                            </button>
                                        </div>
                                        <?php if ($j['updated_at'] && $j['updated_at'] != $j['created_at']): ?>
                                        <div style="font-size: 0.7rem; color: var(--accent-color); margin-top: 3px; font-weight: 500;">
                                            ↻ Updated: <?php echo date('d M Y, h:i A', strtotime($j['updated_at'])); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $j['status']; ?>">
                                            <?php 
                                            if ($j['status'] == 'submitted_waiting_review') {
                                                echo 'Submitted (Waiting Review)';
                                            } else {
                                                echo str_replace('_', ' ', $j['status']);
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        // Show assign dropdown only for unassigned submissions waiting for first review
                                        $needs_assignment = ($j['status'] === 'submitted_waiting_review' && empty($j['assigned_reviewer_id']));
                                        // Show change dropdown for already-assigned under_review papers (admin can reassign)
                                        $can_reassign = ($j['status'] === 'under_review' || $j['status'] === 'submitted_waiting_review') && !empty($j['assigned_reviewer_id']);
                                        ?>
                                        <?php if ($needs_assignment): ?>
                                            <form action="dashboard.php" method="POST" style="display: flex; gap: 5px; align-items: center;">
                                                <input type="hidden" name="journal_id" value="<?php echo $j['id']; ?>">
                                                <select name="reviewer_id" class="form-control" style="font-size: 0.75rem; padding: 4px 8px; width: 140px;" required>
                                                    <option value="">Choose Verifier...</option>
                                                    <?php foreach ($reviewers as $rev): ?>
                                                        <option value="<?php echo $rev['id']; ?>">
                                                            <?php echo sanitize($rev['fullname']); ?> (<?php echo sanitize($rev['subject_domain']); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="assign_reviewer" class="btn btn-dark" style="padding: 4px 8px; font-size: 0.75rem;">
                                                    Assign
                                                </button>
                                            </form>
                                        <?php elseif ($can_reassign): ?>
                                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                                <span style="font-size: 0.85rem; font-weight: 500;">👤 <?php echo sanitize($j['assigned_reviewer']); ?></span>
                                                <?php if ($j['status'] === 'under_review'): ?>
                                                    <span style="font-size: 0.7rem; color: var(--info-color); font-weight: 600;">⏳ Re-reviewing (Revision)</span>
                                                <?php endif; ?>
                                                <form action="dashboard.php" method="POST" style="display: flex; gap: 5px; align-items: center;">
                                                    <input type="hidden" name="journal_id" value="<?php echo $j['id']; ?>">
                                                    <select name="reviewer_id" class="form-control" style="font-size: 0.75rem; padding: 4px 8px; width: 120px;" required>
                                                        <option value="">Change to...</option>
                                                        <?php foreach ($reviewers as $rev): ?>
                                                            <option value="<?php echo $rev['id']; ?>" <?php echo ($j['assigned_reviewer_id'] == $rev['id']) ? 'selected' : ''; ?>>
                                                                <?php echo sanitize($rev['fullname']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="assign_reviewer" class="btn btn-dark" style="padding: 4px 8px; font-size: 0.75rem;">Change</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.85rem; display: block; font-weight: 500;">
                                                👤 <?php echo sanitize($j['assigned_reviewer'] ?? 'Not assigned'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($j['status'] === 'ready_for_publish'): ?>
                                            <!-- Fix Fee Button -->
                                            <button onclick="openFeeModal(<?php echo $j['id']; ?>, '<?php echo addslashes(sanitize($j['journal_number'])); ?>', null, null, null, '<?php echo $j['gst_type']; ?>')" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem; background-color: var(--accent-color); color: var(--primary-dark);">
                                                Fix Publication Fee
                                            </button>
                                        <?php elseif ($j['status'] === 'payment_pending'): ?>
                                            <?php if ($j['payment_status'] === 'pending'): ?>
                                                <!-- Verify Payment Proof -->
                                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                                    <span style="font-size: 0.75rem; font-weight: 700; color: #a16207;">Proof Submitted</span>
                                                    <span style="font-size: 0.72rem; color: var(--text-color); margin-bottom: 2px;">Ref: <strong><?php echo sanitize($j['transaction_id']); ?></strong></span>
                                                    <?php if ($j['verifier_cut'] !== null): ?>
                                                        <span style="font-size: 0.68rem; color: var(--text-muted); margin-bottom: 2px; display: block; font-weight: 500;">
                                                            Split: V: ₹<?php echo number_format($j['verifier_cut'], 0); ?> | A: ₹<?php echo number_format($j['admin_cut'], 0); ?> | P: ₹<?php echo number_format($j['portal_cut'], 0); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <a href="view_proof.php?id=<?php echo $j['id']; ?>" target="_blank" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem; text-align: center; border: 1px solid var(--border-color); color: var(--primary-color);">
                                                        View Receipt Proof
                                                    </a>
                                                    <button onclick="openPublishModal(<?php echo $j['id']; ?>, '<?php echo addslashes(sanitize($j['journal_number'])); ?>')" class="btn btn-dark" style="padding: 4px 8px; font-size: 0.75rem; background-color: var(--success-color); color: white;">
                                                        Verify & Publish
                                                    </button>
                                                    <button onclick="openFeeModal(<?php echo $j['id']; ?>, '<?php echo addslashes(sanitize($j['journal_number'])); ?>', <?php echo floatval($j['verifier_cut']); ?>, <?php echo floatval($j['admin_cut']); ?>, <?php echo floatval($j['portal_cut']); ?>, '<?php echo $j['gst_type']; ?>')" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem; border: 1px solid var(--border-color); color: var(--primary-color); display: inline-flex; align-items: center; justify-content: center; gap: 4px;">
                                                        ✏️ Update Fee
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                                    <span style="font-size: 0.8rem; color: var(--text-muted);">Awaiting payment (₹<?php echo number_format($j['payment_amount'], 2); ?>)</span>
                                                    <?php if ($j['verifier_cut'] !== null): ?>
                                                        <div style="font-size: 0.68rem; color: var(--text-muted); font-weight: 500; line-height: 1.3;">
                                                            Cuts split:<br>
                                                            V: ₹<?php echo number_format($j['verifier_cut'], 0); ?> | A: ₹<?php echo number_format($j['admin_cut'], 0); ?> | P: ₹<?php echo number_format($j['portal_cut'], 0); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <button onclick="openFeeModal(<?php echo $j['id']; ?>, '<?php echo addslashes(sanitize($j['journal_number'])); ?>', <?php echo floatval($j['verifier_cut']); ?>, <?php echo floatval($j['admin_cut']); ?>, <?php echo floatval($j['portal_cut']); ?>, '<?php echo $j['gst_type']; ?>')" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem; border: 1px solid var(--border-color); color: var(--primary-color); display: inline-flex; align-items: center; justify-content: center; gap: 4px; width: fit-content;">
                                                        ✏️ Update Fee
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($j['status'] === 'published'): ?>
                                            <div style="display: flex; flex-direction: column; gap: 6px;">
                                                <span style="font-size: 0.8rem; color: var(--success-color); font-weight: 700;">
                                                    ✅ Vol. <?php echo sanitize($j['volume']); ?>, Issue <?php echo sanitize($j['issue']); ?>
                                                </span>
                                                <?php if (!empty($j['published_at'])): ?>
                                                <span style="font-size: 0.72rem; color: var(--text-muted);">
                                                    📅 <?php echo date('F Y', strtotime($j['published_at'])); ?>
                                                </span>
                                                <?php endif; ?>
                                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                                    <button onclick="openEditPublicationModal(<?php echo $j['id']; ?>, '<?php echo addslashes(sanitize($j['journal_number'])); ?>', '<?php echo addslashes(sanitize($j['volume'])); ?>', '<?php echo addslashes(sanitize($j['issue'])); ?>', '<?php echo !empty($j['published_at']) ? date('Y-m-d', strtotime($j['published_at'])) : date('Y-m-d'); ?>')" style="background: none; border: 1px solid #94a3b8; color: #475569; font-size: 0.72rem; cursor: pointer; padding: 3px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; width: fit-content;">
                                                        ✏️ Edit Info
                                                    </button>
                                                    <?php if (!empty($j['bill_number'])): ?>
                                                        <a href="../download.php?id=<?php echo $j['id']; ?>&type=invoice" target="_blank" style="background: #f0fdf4; border: 1px solid #16a34a; color: #166534; font-size: 0.72rem; cursor: pointer; padding: 3px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; font-weight: 600; text-decoration: none; width: fit-content;">
                                                            🧾 GST Invoice
                                                        </a>
                                                    <?php endif; ?>
                                                    <button onclick="confirmRevert(<?php echo $j['id']; ?>, '<?php echo addslashes(sanitize($j['journal_number'])); ?>')" style="background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; font-size: 0.72rem; cursor: pointer; padding: 3px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; font-weight: 600; width: fit-content;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                                                        ↩️ Revert to Pay Status
                                                    </button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);">Awaiting review</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal: Fix Publication Fee -->
<div id="feeModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 550px; width: 95%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.3rem;">Fix Publication Fee</h3>
            <button onclick="closeFeeModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="dashboard.php" method="POST">
            <input type="hidden" name="fix_payment" value="1">
            <input type="hidden" name="journal_id" id="feeJournalId">
            
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Set the processing and publication fee for manuscript <strong id="feeJournalNo"></strong>. Once saved, the status transitions to 'Payment Pending' and the author will see this amount.
            </p>
            
            <div class="form-group" style="margin-bottom: 1.5rem; background-color: #f8fafc; padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border-color);">
                <label for="amount" style="font-weight: 700; color: var(--primary-color); display: block; margin-bottom: 6px;">Total Fee Amount (INR / ₹)</label>
                <input type="number" name="amount" id="amount" class="form-control" placeholder="e.g., 5000" min="0" step="0.01" oninput="calculateCuts()" required style="font-size: 1.1rem; font-weight: 700; background-color: white;">
                <small style="color: var(--text-muted); font-size: 0.72rem; display: block; margin-top: 4px;">
                    Enter the total cost. The splits below will calculate automatically based on global cutting rules (Verifier: <?php echo $default_v_pct; ?>%, Admin: <?php echo $default_a_pct; ?>%, Portal: <?php echo $default_p_pct; ?>%).
                </small>
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="font-weight: 600; display: block; margin-bottom: 6px; color: var(--primary-color);">GST Settings (<?php echo $default_gst_pct; ?>%)</label>
                <div style="display: flex; gap: 20px;">
                    <label style="display: inline-flex; align-items: center; font-size: 0.88rem; cursor: pointer; font-weight: 500;">
                        <input type="radio" name="gst_type" value="exclude" id="gstExclude" checked onchange="calculateCuts()" style="margin-right: 6px;">
                        Exclude GST (Add <?php echo $default_gst_pct; ?>% extra GST)
                    </label>
                    <label style="display: inline-flex; align-items: center; font-size: 0.88rem; cursor: pointer; font-weight: 500;">
                        <input type="radio" name="gst_type" value="include" id="gstInclude" onchange="calculateCuts()" style="margin-right: 6px;">
                        Include GST (<?php echo $default_gst_pct; ?>% GST inclusive)
                    </label>
                </div>
            </div>

            <div id="gstPreview" style="background-color: #f0fdf4; border: 1px solid #bbf7d0; padding: 12px; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.82rem; color: #166534; line-height: 1.5; display: none;">
                <!-- Filled dynamically via Javascript -->
            </div>
            
            <div style="background-color: white; border: 1px solid var(--border-color); padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem;">
                <h4 style="font-size: 0.85rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 6px; letter-spacing: 0.5px;">Financial Splits (Cuts)</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="verifier_cut" style="font-size: 0.8rem; font-weight: 600;">Verifier Cut (₹)</label>
                        <input type="number" name="verifier_cut" id="verifier_cut" class="form-control" min="0" step="0.01" oninput="calculateTotal()" required style="padding: 6px 10px; font-size: 0.9rem;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="admin_cut" style="font-size: 0.8rem; font-weight: 600;">Admin Cut (₹)</label>
                        <input type="number" name="admin_cut" id="admin_cut" class="form-control" min="0" step="0.01" oninput="calculateTotal()" required style="padding: 6px 10px; font-size: 0.9rem;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="portal_cut" style="font-size: 0.8rem; font-weight: 600;">Portal Cut (₹)</label>
                        <input type="number" name="portal_cut" id="portal_cut" class="form-control" min="0" step="0.01" oninput="calculateTotal()" required style="padding: 6px 10px; font-size: 0.9rem;">
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeFeeModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 16px;">Request Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Publish Journal -->
<div id="publishModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.3rem;">Publish Manuscript</h3>
            <button onclick="closePublishModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="dashboard.php" method="POST">
            <input type="hidden" name="publish_journal" value="1">
            <input type="hidden" name="journal_id" id="publishJournalId">
            
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Set publication Volume and Issue number for manuscript <strong id="publishJournalNo"></strong>. Confirming this action marks payment verified and displays the journal in public listings.
            </p>
            
            <div class="form-group">
                <label for="volume">Volume (e.g., 20)</label>
                <input type="text" name="volume" id="volume" class="form-control" value="<?php echo htmlspecialchars($current_vol); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="issue">Issue (e.g., 1)</label>
                <input type="text" name="issue" id="issue" class="form-control" value="<?php echo htmlspecialchars($current_issue); ?>" required>
            </div>

            <div class="form-group">
                <label for="publication_date">Publication Date</label>
                <input type="date" name="publication_date" id="publication_date" class="form-control" value="<?php echo htmlspecialchars($current_edition_date); ?>" required>
            </div>
            
            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closePublishModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 16px; background-color: var(--success-color); border: none; color: white;">Confirm & Publish</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Publication Info (for already-published journals) -->
<div id="editPublicationModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 520px; width: 95%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <div>
                <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.3rem; margin-bottom: 4px;">Edit Publication Info</h3>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0;">Update Volume, Issue &amp; Date for <strong id="editPubJournalNo"></strong></p>
            </div>
            <button onclick="closeEditPublicationModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="dashboard.php" method="POST">
            <input type="hidden" name="edit_publication" value="1">
            <input type="hidden" name="journal_id" id="editPubJournalId">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="edit_volume" style="font-weight: 600;">Volume Number</label>
                    <input type="text" name="volume" id="edit_volume" class="form-control" placeholder="e.g., 20" required>
                    <small style="color: var(--text-muted); font-size: 0.7rem;">e.g., VOLUME 20</small>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="edit_issue" style="font-weight: 600;">Issue Number</label>
                    <input type="text" name="issue" id="edit_issue" class="form-control" placeholder="e.g., 1" required>
                    <small style="color: var(--text-muted); font-size: 0.7rem;">e.g., ISSUE 1</small>
                </div>
            </div>

            <div class="form-group">
                <label for="edit_publication_date" style="font-weight: 600;">Publication Date</label>
                <input type="date" name="publication_date" id="edit_publication_date" class="form-control" required>
                <small style="color: var(--text-muted); font-size: 0.72rem;">The month &amp; year shown on the journal archive (e.g., March 2026).</small>
            </div>

            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 14px; margin-bottom: 1.25rem;">
                <p style="margin: 0; font-size: 0.8rem; color: #166534; font-weight: 500;">Preview: <span id="editPubPreview" style="font-style: italic;"></span></p>
            </div>

            <div style="margin-top: 1rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeEditPublicationModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 20px; background-color: var(--primary-color); color: white;">💾 Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Submission Date -->
<div id="editDateModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 450px; width: 95%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <div>
                <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.3rem; margin-bottom: 4px;">Edit Submission Date</h3>
                <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0;">Update original submission date for <strong id="editDateJournalNo"></strong></p>
            </div>
            <button onclick="closeEditDateModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form action="dashboard.php" method="POST">
            <input type="hidden" name="edit_submission_date" value="1">
            <input type="hidden" name="journal_id" id="editDateJournalId">

            <div class="form-group">
                <label for="submission_date" style="font-weight: 600;">Original Submission Date & Time</label>
                <input type="datetime-local" name="submission_date" id="submission_date" class="form-control" required>
                <small style="color: var(--text-muted); font-size: 0.72rem;">Set the original date and time this journal was submitted.</small>
            </div>

            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeEditDateModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 20px; background-color: var(--primary-color); color: white;">💾 Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function calculateTotal() {
    var v = parseFloat(document.getElementById('verifier_cut').value) || 0;
    var a = parseFloat(document.getElementById('admin_cut').value) || 0;
    var p = parseFloat(document.getElementById('portal_cut').value) || 0;
    
    var baseVal = v + a + p;
    var isInclude = document.getElementById('gstInclude').checked;
    var gstPct = <?php echo $default_gst_pct; ?>;
    
    if (isInclude) {
        document.getElementById('amount').value = (baseVal * (1 + gstPct/100)).toFixed(2);
    } else {
        document.getElementById('amount').value = baseVal.toFixed(2);
    }
    
    updateInvoicePreview();
}
function calculateCuts() {
    var totalVal = parseFloat(document.getElementById('amount').value) || 0;
    var isInclude = document.getElementById('gstInclude').checked;
    var gstPct = <?php echo $default_gst_pct; ?>;
    
    var baseVal = 0;
    if (isInclude) {
        baseVal = totalVal / (1 + gstPct/100);
    } else {
        baseVal = totalVal;
    }
    
    var vPct = <?php echo $default_v_pct; ?>;
    var aPct = <?php echo $default_a_pct; ?>;
    var pPct = <?php echo $default_p_pct; ?>;
    
    document.getElementById('verifier_cut').value = (baseVal * vPct / 100).toFixed(2);
    document.getElementById('admin_cut').value = (baseVal * aPct / 100).toFixed(2);
    document.getElementById('portal_cut').value = (baseVal * pPct / 100).toFixed(2);
    
    updateInvoicePreview();
}
function updateInvoicePreview() {
    var amountVal = parseFloat(document.getElementById('amount').value) || 0;
    var isInclude = document.getElementById('gstInclude').checked;
    var gstPct = <?php echo $default_gst_pct; ?>;
    
    var baseVal = 0;
    var gstVal = 0;
    var totalVal = 0;
    
    if (isInclude) {
        totalVal = amountVal;
        baseVal = totalVal / (1 + gstPct/100);
        gstVal = totalVal - baseVal;
    } else {
        baseVal = amountVal;
        gstVal = baseVal * (gstPct / 100);
        totalVal = baseVal + gstVal;
    }
    
    var previewEl = document.getElementById('gstPreview');
    if (amountVal > 0) {
        previewEl.style.display = 'block';
        previewEl.innerHTML = '<strong>Estimated Invoice Breakdown:</strong><br>' +
            '• Base Amount: ₹' + baseVal.toFixed(2) + '<br>' +
            '• GST (' + gstPct + '%): ₹' + gstVal.toFixed(2) + ' (CGST ' + (gstPct/2) + '%: ₹' + (gstVal/2).toFixed(2) + ', SGST ' + (gstPct/2) + '%: ₹' + (gstVal/2).toFixed(2) + ')<br>' +
            '<strong>• Total Paid by Author: ₹' + totalVal.toFixed(2) + '</strong>';
    } else {
        previewEl.style.display = 'none';
    }
}
function openFeeModal(journalId, journalNo, vCut, aCut, pCut, gstType) {
    document.getElementById('feeJournalId').value = journalId;
    document.getElementById('feeJournalNo').textContent = journalNo;
    
    // Use stored gst_type if available, otherwise fall back to the global admin setting
    var defaultGstMode = '<?php echo $default_gst_mode; ?>';
    gstType = (gstType && gstType !== '') ? gstType : defaultGstMode;
    if (gstType === 'include') {
        document.getElementById('gstInclude').checked = true;
    } else {
        document.getElementById('gstExclude').checked = true;
    }
    
    if (vCut !== undefined && vCut !== null && vCut !== 0 && aCut !== undefined && aCut !== null && aCut !== 0) {
        document.getElementById('verifier_cut').value = parseFloat(vCut).toFixed(2);
        document.getElementById('admin_cut').value = parseFloat(aCut).toFixed(2);
        document.getElementById('portal_cut').value = parseFloat(pCut).toFixed(2);
        
        var baseTotal = parseFloat(vCut) + parseFloat(aCut) + parseFloat(pCut);
        var gstPct = <?php echo $default_gst_pct; ?>;
        if (gstType === 'include') {
            document.getElementById('amount').value = (baseTotal * (1 + gstPct/100)).toFixed(2);
        } else {
            document.getElementById('amount').value = baseTotal.toFixed(2);
        }
    } else {
        // Set defaults dynamically
        var defaultTotal = <?php echo $default_min_fee; ?>;
        document.getElementById('amount').value = defaultTotal.toFixed(2);
        calculateCuts();
    }
    
    updateInvoicePreview();
    document.getElementById('feeModal').style.display = 'flex';
}
function closeFeeModal() {
    document.getElementById('feeModal').style.display = 'none';
}
function openPublishModal(journalId, journalNo) {
    document.getElementById('publishJournalId').value = journalId;
    document.getElementById('publishJournalNo').textContent = journalNo;
    
    // Set default volume, issue, and date from the active settings in DB
    document.getElementById('volume').value = "<?php echo addslashes($current_vol); ?>";
    document.getElementById('issue').value = "<?php echo addslashes($current_issue); ?>";
    document.getElementById('publication_date').value = "<?php echo addslashes($current_edition_date); ?>";
    
    document.getElementById('publishModal').style.display = 'flex';
}
function closePublishModal() {
    document.getElementById('publishModal').style.display = 'none';
    document.getElementById('publication_date').value = '';
}
function openEditDateModal(journalId, journalNo, currentDateTime) {
    document.getElementById('editDateJournalId').value = journalId;
    document.getElementById('editDateJournalNo').textContent = journalNo;
    document.getElementById('submission_date').value = currentDateTime;
    document.getElementById('editDateModal').style.display = 'flex';
}
function closeEditDateModal() {
    document.getElementById('editDateModal').style.display = 'none';
}
function openEditPublicationModal(journalId, journalNo, volume, issue, pubDate) {
    document.getElementById('editPubJournalId').value = journalId;
    document.getElementById('editPubJournalNo').textContent = journalNo;
    document.getElementById('edit_volume').value = volume;
    document.getElementById('edit_issue').value = issue;
    document.getElementById('edit_publication_date').value = pubDate;
    updateEditPubPreview();
    document.getElementById('editPublicationModal').style.display = 'flex';
}
function closeEditPublicationModal() {
    document.getElementById('editPublicationModal').style.display = 'none';
}
function updateEditPubPreview() {
    var vol = document.getElementById('edit_volume').value || '?';
    var iss = document.getElementById('edit_issue').value || '?';
    var dateVal = document.getElementById('edit_publication_date').value;
    var monthYear = '';
    if (dateVal) {
        var d = new Date(dateVal + 'T00:00:00');
        monthYear = d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    }
    document.getElementById('editPubPreview').textContent =
        'VOLUME ' + vol + ' \u2022 ISSUE ' + iss + (monthYear ? ' \u2022 ' + monthYear.toUpperCase() : '');
}
document.addEventListener('DOMContentLoaded', function() {
    var volEl = document.getElementById('edit_volume');
    var issEl = document.getElementById('edit_issue');
    var dateEl = document.getElementById('edit_publication_date');
    if (volEl) { volEl.addEventListener('input', updateEditPubPreview); }
    if (issEl) { issEl.addEventListener('input', updateEditPubPreview); }
    if (dateEl) { dateEl.addEventListener('change', updateEditPubPreview); }
    document.getElementById('editPublicationModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditPublicationModal();
    });
    document.getElementById('editDateModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditDateModal();
    });
});
function openTimeline(journalId, journalNo) {
    document.getElementById('tlJournalNo').textContent = journalNo;
    var dataEl = document.getElementById('tl-data-' + journalId);
    document.getElementById('timelineContent').innerHTML = dataEl ? dataEl.innerHTML : '<p>No history found.</p>';
    document.getElementById('timelineModal').style.display = 'flex';
}
function closeTimeline() {
    document.getElementById('timelineModal').style.display = 'none';
}
document.getElementById('timelineModal').addEventListener('click', function(e) {
    if (e.target === this) closeTimeline();
});
</script>

<!-- SweetAlert2 library -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
function confirmRevert(journalId, journalNo) {
    Swal.fire({
        title: 'Revert to Pay Status?',
        html: 'Are you sure you want to revert manuscript <strong>' + journalNo + '</strong> back to "Author to Pay" status?<br><br>' +
              'This will:<br>' +
              '• Revert journal status to Payment Pending.<br>' +
              '• Delete the payment verification & proof receipt.<br>' +
              '• Reverse all wallet commissions credited for this publication.<br>' +
              '• Cancel the GST Invoice.<br>' +
              '• Regenerate the PDF manuscript cover page.<br><br>' +
              '<span style="color: #dc2626; font-weight: 600;">This action cannot be undone!</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#475569',
        confirmButtonText: 'Yes, revert it!',
        cancelButtonText: 'Cancel',
        focusCancel: true
    }).then((result) => {
        if (result.isConfirmed) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'dashboard.php';
            
            var inputRevert = document.createElement('input');
            inputRevert.type = 'hidden';
            inputRevert.name = 'revert_publication';
            inputRevert.value = '1';
            form.appendChild(inputRevert);
            
            var inputId = document.createElement('input');
            inputId.type = 'hidden';
            inputId.name = 'journal_id';
            inputId.value = journalId;
            form.appendChild(inputId);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}
</script>

<!-- Document History Modal -->
<div id="timelineModal" class="modal-overlay" style="display: none; align-items: flex-start; padding-top: 40px;">
    <div class="modal-content" style="max-width: 620px; width: 95%; max-height: 80vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; position: sticky; top: 0; background: white; z-index: 1;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.1rem;">📄 Document History &mdash; <span id="tlJournalNo"></span></h3>
            <button onclick="closeTimeline()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <div id="timelineContent" style="padding: 4px 0;"></div>
    </div>
</div>

<!-- Prerendered timeline data containers (hidden) -->
<?php foreach ($journals as $j): ?>
<div id="tl-data-<?php echo $j['id']; ?>" style="display: none;">
    <?php
    $timeline_journal_id = $j['id'];
    $timeline_role = 'admin';
    include __DIR__ . '/../includes/document_timeline.php';
    ?>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
