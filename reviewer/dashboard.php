<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('reviewer');

$user = get_logged_in_user();
$reviewer_id = $user['id'];

$message = "";
$message_type = "";

// Handle Review Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $assignment_id = intval($_POST['assignment_id']);
    $journal_id = intval($_POST['journal_id']);
    $recommendation = sanitize($_POST['recommendation']);
    $comments = sanitize($_POST['comments']);
    
    if (empty($comments)) {
        $message = "Please provide detailed review comments.";
        $message_type = "danger";
    } elseif (!in_array($recommendation, ['approve', 'revision', 'reject'])) {
        $message = "Invalid recommendation selected.";
        $message_type = "danger";
    } else {
        try {
            // Verify assignment belongs to this reviewer
            $verify_stmt = $pdo->prepare("SELECT id FROM reviewer_assignments WHERE id = ? AND reviewer_id = ? AND status = 'assigned'");
            $verify_stmt->execute([$assignment_id, $reviewer_id]);
            
            if ($verify_stmt->fetch()) {
                $pdo->beginTransaction();
                
                // 1. Insert into reviews table
                $ins_review = $pdo->prepare("INSERT INTO reviews (journal_id, reviewer_id, comments, recommendation) VALUES (?, ?, ?, ?)");
                $ins_review->execute([$journal_id, $reviewer_id, $comments, $recommendation]);
                
                // 2. Update reviewer_assignments status
                $upd_assign = $pdo->prepare("UPDATE reviewer_assignments SET status = 'reviewed' WHERE id = ?");
                $upd_assign->execute([$assignment_id]);
                
                // 3. Update journal status
                // Map recommendation to journal status
                $journal_status = 'under_review';
                if ($recommendation === 'approve') {
                    $journal_status = 'ready_for_publish';
                } elseif ($recommendation === 'revision') {
                    $journal_status = 'revisions_required';
                } elseif ($recommendation === 'reject') {
                    $journal_status = 'rejected';
                }
                
                $upd_journal = $pdo->prepare("UPDATE journals SET status = ? WHERE id = ?");
                $upd_journal->execute([$journal_status, $journal_id]);
                
                $pdo->commit();
                
                // Send review outcome email
                try {
                    require_once __DIR__ . '/../includes/mail_helper.php';
                    $j_stmt = $pdo->prepare("SELECT j.*, u.fullname AS author_name, u.email AS author_email FROM journals j JOIN users u ON j.author_id = u.id WHERE j.id = ?");
                    $j_stmt->execute([$journal_id]);
                    $j_info = $j_stmt->fetch();
                    if ($j_info) {
                        $author_obj = ['fullname' => $j_info['author_name'], 'email' => $j_info['author_email']];
                        rjpes_mail_review_outcome($j_info, $author_obj, $recommendation, $comments);
                    }
                } catch (Exception $ex) {
                    // ignore email error
                }
                
                $message = "Review submitted successfully! The manuscript status has been updated.";
                $message_type = "success";
            } else {
                $message = "Invalid or already reviewed assignment.";
                $message_type = "danger";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "Database failed: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Get assigned journals
try {
    $stmt = $pdo->prepare("SELECT ra.id as assignment_id, ra.assigned_at, ra.status as assignment_status,
                            j.id as journal_id, j.title, j.abstract, j.subject_domain, j.manuscript_file, j.journal_number, j.status as journal_status,
                            j.created_at as submitted_at, j.updated_at as last_updated,
                            (SELECT MAX(jv.version_number) FROM journal_versions jv WHERE jv.journal_id = j.id) as current_version,
                            u.fullname as author_name
                            FROM reviewer_assignments ra
                            JOIN journals j ON ra.journal_id = j.id
                            JOIN users u ON j.author_id = u.id
                            WHERE ra.reviewer_id = ?
                            ORDER BY ra.assigned_at DESC");
    $stmt->execute([$reviewer_id]);
    $assignments = $stmt->fetchAll();
    
    // Fetch wallet balance
    $bal_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0.00) as balance FROM wallet_transactions WHERE user_id = ?");
    $bal_stmt->execute([$reviewer_id]);
    $wallet_balance = floatval($bal_stmt->fetch()['balance']);
    
    // Fetch chronologically to compute running balance correctly for the ledger
    $stmt_tx = $pdo->prepare("SELECT id, amount, transaction_type, description, created_at, payment_type, transaction_date FROM wallet_transactions WHERE user_id = ? ORDER BY id ASC");
    $stmt_tx->execute([$reviewer_id]);
    $txs_asc = $stmt_tx->fetchAll();
    
    $running_bal = 0.00;
    $txs_with_bal = [];
    foreach ($txs_asc as $tx) {
        $running_bal += floatval($tx['amount']);
        $tx['running_balance'] = $running_bal;
        $txs_with_bal[] = $tx;
    }
    $txs_desc = array_reverse($txs_with_bal);
} catch (PDOException $e) {
    die("Database query failed: " . $e->getMessage());
}

$page_title = "Reviewer Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <!-- Main Content -->
    <main class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="font-family: var(--font-heading); color: var(--primary-color);">Verifier Dashboard</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Review and verify assigned journal documentation submissions</p>
            </div>
            <!-- Wallet Card Widget -->
            <div onclick="openLedgerModal()" style="cursor: pointer; background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%); border: 1px solid var(--border-color); border-radius: 12px; padding: 0.75rem 1.5rem; display: flex; align-items: center; gap: 12px; box-shadow: var(--shadow-sm); transition: all 0.2s;" onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='none';">
                <div>
                    <span style="font-size: 0.7rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block;">Wallet Balance</span>
                    <span style="font-size: 1.3rem; font-weight: 800; color: #16a34a; display: block; margin-top: 2px;">₹<?php echo number_format($wallet_balance, 2); ?></span>
                </div>
                <div style="background: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--border-color); color: var(--primary-color);">
                    💳
                </div>
            </div>
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

        <div class="card" style="padding: 1.5rem;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1.5rem;">Assigned Manuscripts</h3>
            
            <?php if (empty($assignments)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.7;">
                        <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
                        <path d="M9 12l2 2 4-4"/>
                    </svg>
                    <p style="font-weight: 500;">No articles are currently assigned to you for verification.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Journal ID</th>
                                <th>Article Title</th>
                                <th>Author / Domain</th>
                                <th>Submitted On</th>
                                <th>Review Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $row): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--primary-color);">
                                        <?php echo sanitize($row['journal_number']); ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo sanitize($row['title']); ?></div>
                                        <div style="margin-top: 6px;">
                                            <?php
                                            $ver_num = intval($row['current_version'] ?? 1);
                                            $is_revised = $ver_num > 1;
                                            $dl_label  = $is_revised ? '⬇ Download v' . $ver_num . ' — Revised' : '⬇ Download Manuscript';
                                            $dl_bg     = $is_revised ? '#fef3c7' : '#f8fafc';
                                            $dl_color  = $is_revised ? '#92400e' : 'var(--primary-color)';
                                            $dl_border = $is_revised ? '#fde68a' : 'var(--border-color)';
                                            ?>
                                            <a href="<?php echo $path_prefix . $row['manuscript_file']; ?>" target="_blank"
                                               style="padding: 5px 11px; font-size: 0.75rem; background: <?php echo $dl_bg; ?>; border: 1px solid <?php echo $dl_border; ?>; color: <?php echo $dl_color; ?>; display: inline-flex; align-items: center; gap: 5px; border-radius: 5px; font-weight: 600; text-decoration: none;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                <?php echo $dl_label; ?>
                                            </a>
                                            <?php if ($is_revised): ?>
                                            <div style="font-size: 0.68rem; color: #d97706; margin-top: 3px; font-weight: 600;">
                                                ⚠️ This is the author’s revised submission
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Abstract Preview Container -->
                                        <div id="abstract-preview-<?php echo $row['journal_id']; ?>" style="display: none; background-color: #f8fafc; padding: 1rem; border-radius: 8px; margin-top: 10px; border: 1px solid var(--border-color);">
                                            <h4 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 8px; font-size: 0.85rem;">Abstract Preview</h4>
                                            <div style="font-size: 0.82rem; font-style: italic; color: #334155; line-height: 1.5; margin: 0; font-weight: normal;">
                                                <?php 
                                                $decoded_abstract = html_entity_decode($row['abstract'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                                if (strpos($decoded_abstract, '<p>') === false && strpos($decoded_abstract, '<div>') === false) {
                                                    echo nl2br(strip_tags($decoded_abstract, '<a><strong><em><u><br>'));
                                                } else {
                                                    echo strip_tags($decoded_abstract, '<a><p><strong><em><u><ul><ol><li><blockquote><br>');
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.9rem;"><?php echo sanitize($row['author_name']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;"><?php echo sanitize($row['subject_domain']); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.82rem; font-weight: 600; color: var(--primary-color);">
                                            <?php echo date('d M Y', strtotime($row['submitted_at'])); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                                            🕐 <?php echo date('h:i A', strtotime($row['submitted_at'])); ?>
                                        </div>
                                        <div style="font-size: 0.7rem; color: var(--info-color); margin-top: 3px;">
                                            Assigned: <?php echo date('d M Y', strtotime($row['assigned_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo ($row['assignment_status'] == 'reviewed') ? 'published' : 'under_review'; ?>">
                                            <?php echo ($row['assignment_status'] == 'reviewed') ? 'Completed' : 'Pending Action'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($row['assignment_status'] === 'assigned'): ?>
                                            <button onclick="openReviewModal(<?php echo $row['assignment_id']; ?>, <?php echo $row['journal_id']; ?>, '<?php echo addslashes(sanitize($row['journal_number'])); ?>')" class="btn btn-dark" style="padding: 6px 12px; font-size: 0.8rem;">
                                                Submit Review
                                            </button>
                                        <?php else: ?>
                                            <span style="font-size: 0.85rem; color: var(--text-muted);">Reviewed</span>
                                        <?php endif; ?>
                                        
                                        <!-- Abstract Preview Trigger -->
                                        <div style="margin-top: 5px;">
                                            <button onclick="toggleAbstract(<?php echo $row['journal_id']; ?>)" style="background: none; border: none; color: var(--info-color); font-size: 0.8rem; cursor: pointer; text-decoration: underline;">
                                                Toggle Abstract
                                            </button>
                                        </div>
                                        <!-- Document History Button -->
                                        <div style="margin-top: 5px;">
                                            <button onclick="openTimeline(<?php echo $row['journal_id']; ?>, '<?php echo addslashes(sanitize($row['journal_number'])); ?>')" style="background: none; border: 1px solid #94a3b8; color: #475569; font-size: 0.75rem; cursor: pointer; padding: 4px 10px; border-radius: 5px; display: inline-flex; align-items: center; gap: 4px; font-weight: 500;">
                                                📄 Document History
                                            </button>
                                        </div>
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

<!-- Document History Modal -->
<div id="timelineModal" class="modal-overlay" style="display: none; align-items: flex-start; padding-top: 40px;">
    <div class="modal-content" style="max-width: 600px; width: 95%; max-height: 80vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; position: sticky; top: 0; background: white; z-index: 1;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.1rem;">📄 Document History &mdash; <span id="tlJournalNo"></span></h3>
            <button onclick="closeTimeline()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <div id="timelineContent" style="padding: 4px 0;"></div>
    </div>
</div>

<!-- Prerendered timeline data -->
<?php foreach ($assignments as $row): ?>
<div id="tl-data-<?php echo $row['journal_id']; ?>" style="display: none;">
    <?php
    $timeline_journal_id = $row['journal_id'];
    $timeline_role = 'reviewer';
    include __DIR__ . '/../includes/document_timeline.php';
    ?>
</div>
<?php endforeach; ?>

<!-- Modal for Submitting Review -->
<div id="reviewModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.3rem;">Verify Manuscript <span id="modalJournalNo"></span></h3>
            <button onclick="closeReviewModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="dashboard.php" method="POST">
            <input type="hidden" name="submit_review" value="1">
            <input type="hidden" name="assignment_id" id="modalAssignmentId">
            <input type="hidden" name="journal_id" id="modalJournalId">
            
            <div class="form-group">
                <label for="recommendation">Evaluation Recommendation</label>
                <select name="recommendation" id="recommendation" class="form-control" required>
                    <option value="">Select Action...</option>
                    <option value="approve">Approve (Ready for Payment & Publish)</option>
                    <option value="revision">Request Revision & Changes from Author</option>
                    <option value="reject">Reject Submission</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="comments">Reviewer Comments & Feedback</label>
                <textarea name="comments" id="comments" class="form-control" rows="6" placeholder="Provide detailed suggestions, corrections, and general comments for the author or editorial board." required></textarea>
            </div>
            
            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeReviewModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 16px;">Submit Evaluation</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Ledger History -->
<div id="ledgerModal" class="modal-overlay" style="display: none; align-items: flex-start; padding-top: 40px;">
    <div class="modal-content" style="max-width: 1350px; width: 96%; max-height: 85vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; position: sticky; top: 0; background: white; z-index: 1;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.1rem;">Wallet Transaction Ledger</h3>
            <button onclick="closeLedgerModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <div style="background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem; margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center; box-shadow: var(--shadow-sm);">
            <div>
                <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block; letter-spacing: 0.5px;">Current Wallet Balance</span>
                <span style="font-size: 1.6rem; font-weight: 800; color: #16a34a; display: block; margin-top: 4px;">₹<?php echo number_format($wallet_balance, 2); ?></span>
            </div>
            <button id="customExcelBtn" class="excel-export-btn" style="margin-bottom: 0 !important;">📥 Export to Excel</button>
        </div>

        <div class="table-responsive">
            <table class="table" id="ledgerTable" style="width: 100%; font-size: 0.8rem; margin: 0;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th style="text-align: right;">Credit (₹)</th>
                        <th style="text-align: right;">Debit (₹)</th>
                        <th style="text-align: right;">Balance (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($txs_desc as $tx): ?>
                        <tr>
                            <td>
                                <?php 
                                if ($tx['transaction_type'] == 'debit' && !empty($tx['transaction_date'])) {
                                    echo date('d M Y', strtotime($tx['transaction_date']));
                                } else {
                                    echo date('d M Y, h:i A', strtotime($tx['created_at']));
                                }
                                ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span><?php echo sanitize($tx['description']); ?></span>
                                    <?php if (!empty($tx['payment_type'])): ?>
                                        <span style="font-size: 0.7rem; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px; display: inline-block; font-weight: 500;">
                                            💳 <?php echo sanitize($tx['payment_type']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align: right; font-weight: 600; color: #16a34a;">
                                <?php echo ($tx['transaction_type'] == 'credit') ? '₹' . number_format(abs($tx['amount']), 2) : '—'; ?>
                            </td>
                            <td style="text-align: right; font-weight: 600; color: #dc2626;">
                                <?php echo ($tx['transaction_type'] == 'debit') ? '₹' . number_format(abs($tx['amount']), 2) : '—'; ?>
                            </td>
                            <td style="text-align: right; font-weight: 600; color: var(--text-color);">
                                ₹<?php echo number_format($tx['running_balance'], 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function toggleAbstract(id) {
    var element = document.getElementById('abstract-preview-' + id);
    if (element.style.display === 'none') {
        element.style.display = 'block';
    } else {
        element.style.display = 'none';
    }
}

function openReviewModal(assignmentId, journalId, journalNo) {
    document.getElementById('modalAssignmentId').value = assignmentId;
    document.getElementById('modalJournalId').value = journalId;
    document.getElementById('modalJournalNo').textContent = '(' + journalNo + ')';
    document.getElementById('reviewModal').style.display = 'flex';
}

function closeReviewModal() {
    document.getElementById('reviewModal').style.display = 'none';
}

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

function openLedgerModal() {
    document.getElementById('ledgerModal').style.display = 'flex';
    if (typeof jQuery !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#ledgerTable')) {
        $('#ledgerTable').DataTable().columns.adjust().draw();
    }
}

function closeLedgerModal() {
    document.getElementById('ledgerModal').style.display = 'none';
}

document.getElementById('ledgerModal').addEventListener('click', function(e) {
    if (e.target === this) closeLedgerModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<!-- DataTables Buttons and JSZip for Excel Export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<style>
.excel-export-btn {
    background-color: #16a34a !important;
    color: white !important;
    border: none !important;
    padding: 8px 16px !important;
    border-radius: 6px !important;
    font-size: 0.82rem !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    box-shadow: 0 2px 4px rgba(22, 163, 74, 0.2) !important;
    margin-bottom: 15px !important;
}
.excel-export-btn:hover {
    background-color: #15803d !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 8px rgba(22, 163, 74, 0.3) !important;
}
#ledgerModal .dataTables_wrapper {
    padding: 0 !important;
}
#ledgerModal .dataTables_wrapper .dataTables_length,
#ledgerModal .dataTables_wrapper .dataTables_filter {
    margin-bottom: 0.5rem !important;
}
#ledgerModal .dataTables_wrapper .dataTables_paginate {
    padding-top: 0.5rem !important;
}
#ledgerModal .dataTables_info {
    padding-top: 0.5rem !important;
}
#ledgerModal .dt-buttons {
    display: none !important; /* Hide default DataTables buttons wrapper */
}
</style>

<script>
$(document).ready(function() {
    if ($.fn.DataTable) {
        var table = $('#ledgerTable').DataTable({
            dom: 'Bfrtip',
            buttons: [
                {
                    extend: 'excelHtml5',
                    title: 'Wallet_Transaction_Ledger',
                    exportOptions: {
                        columns: ':visible'
                    }
                }
            ],
            pageLength: 10,
            order: [],
            language: {
                searchPlaceholder: "Search ledger...",
                search: ""
            }
        });

        // Trigger DataTables Excel export when the custom button is clicked
        $('#customExcelBtn').on('click', function(e) {
            e.preventDefault();
            table.button('.buttons-excel').trigger();
        });
    }
});
</script>
