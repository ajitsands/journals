<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('author');

$user = get_logged_in_user();
$author_id = $user['id'];

// Handle submission deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_journal') {
    $journal_id = intval($_POST['journal_id'] ?? 0);
    
    try {
        // Retrieve the journal and make sure it belongs to the logged-in author
        $stmt = $pdo->prepare("SELECT * FROM journals WHERE id = ? AND author_id = ?");
        $stmt->execute([$journal_id, $author_id]);
        $journal = $stmt->fetch();
        
        if ($journal) {
            $allowed_statuses = ['submitted_waiting_review', 'under_review', 'revisions_required'];
            if (in_array($journal['status'], $allowed_statuses)) {
                // Gather all files to delete from disk to prevent orphaned files
                $files_to_delete = [];
                
                // 1. Primary manuscript file (merged PDF)
                if (!empty($journal['manuscript_file'])) {
                    $files_to_delete[] = __DIR__ . '/../' . $journal['manuscript_file'];
                }
                
                // 2. Primary author photo
                if (!empty($journal['author_photo'])) {
                    $files_to_delete[] = __DIR__ . '/../' . $journal['author_photo'];
                }
                
                // 3. Co-author photos
                $ca_stmt = $pdo->prepare("SELECT photo_path FROM journal_authors WHERE journal_id = ?");
                $ca_stmt->execute([$journal_id]);
                while ($photo_path = $ca_stmt->fetchColumn()) {
                    if (!empty($photo_path)) {
                        $files_to_delete[] = __DIR__ . '/../' . $photo_path;
                    }
                }
                
                // 4. Past version manuscript files
                $v_stmt = $pdo->prepare("SELECT manuscript_file FROM journal_versions WHERE journal_id = ?");
                $v_stmt->execute([$journal_id]);
                while ($v_file = $v_stmt->fetchColumn()) {
                    if (!empty($v_file)) {
                        $files_to_delete[] = __DIR__ . '/../' . $v_file;
                    }
                }
                
                // 5. Payment proofs (safety fallback)
                $p_stmt = $pdo->prepare("SELECT payment_proof FROM payments WHERE journal_id = ?");
                $p_stmt->execute([$journal_id]);
                while ($p_file = $p_stmt->fetchColumn()) {
                    if (!empty($p_file)) {
                        $files_to_delete[] = __DIR__ . '/../' . $p_file;
                    }
                }
                
                // Perform DB deletion in a transaction
                $pdo->beginTransaction();
                
                $del_stmt = $pdo->prepare("DELETE FROM journals WHERE id = ?");
                $del_stmt->execute([$journal_id]);
                
                $pdo->commit();
                
                // Unlink all files from disk
                foreach ($files_to_delete as $filepath) {
                    $real_path = realpath($filepath);
                    if ($real_path && file_exists($real_path) && is_file($real_path)) {
                        @unlink($real_path);
                    }
                }
                
                header("Location: dashboard.php?success=deleted");
                exit;
            } else {
                $error_message = "This journal cannot be deleted because it has already been accepted, published, or rejected.";
            }
        } else {
            $error_message = "Journal not found or you do not have permission to delete it.";
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = "Database error during deletion: " . $e->getMessage();
    }
}

// Get all submissions of this author with current version number
try {
    $stmt = $pdo->prepare("SELECT j.*, 
                            (SELECT COUNT(*) FROM reviewer_assignments ra WHERE ra.journal_id = j.id) as assigned_reviewers,
                            (SELECT MAX(jv.version_number) FROM journal_versions jv WHERE jv.journal_id = j.id) as current_version,
                            p.status as payment_status, p.transaction_id
                            FROM journals j 
                            LEFT JOIN payments p ON j.id = p.journal_id
                            WHERE j.author_id = ? 
                            ORDER BY j.created_at DESC");
    $stmt->execute([$author_id]);
    $submissions = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error fetching submissions: " . $e->getMessage());
}

$page_title = "Author Dashboard";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <!-- Main Content -->
    <main class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="font-family: var(--font-heading); color: var(--primary-color);">Author Dashboard</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Manage and track your research submissions</p>
            </div>
            <a href="<?php echo $path_prefix; ?>author/submit.php" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Submit New Paper
            </a>
        </div>

        <?php if (isset($_GET['success']) && $_GET['success'] == 'submitted'): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <div>Your manuscript was successfully submitted and is now waiting for administrative review!</div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success']) && $_GET['success'] == 'payment_submitted'): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <div>Payment receipt successfully uploaded! The admin will verify and publish your journal shortly.</div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success']) && $_GET['success'] == 'revision_submitted'): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <div>Your revised manuscript has been submitted successfully.</div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success']) && $_GET['success'] == 'deleted'): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <div>Your manuscript has been successfully deleted along with all its co-authors and history.</div>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <div><?php echo htmlspecialchars($error_message); ?></div>
            </div>
        <?php endif; ?>

        <div class="card" style="padding: 1.5rem;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1rem;">My Research Articles</h3>
            
            <?php if (empty($submissions)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.7;">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                    </svg>
                    <p style="font-weight: 500;">You haven't submitted any research papers yet.</p>
                    <p style="font-size: 0.85rem; margin-top: 5px;"><a href="<?php echo $path_prefix; ?>author/submit.php" style="font-weight: 600;">Click here to start your first submission.</a></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Journal No</th>
                                <th>Title</th>
                                <th>Subject Domain</th>
                                <th>Status</th>
                                <th>Action / Reviews</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $paper): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--primary-color);">
                                        <?php echo sanitize($paper['journal_number']); ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo sanitize($paper['title']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">
                                            📅 <?php echo date('d M Y', strtotime($paper['created_at'])); ?>
                                            &nbsp;🕐 <?php echo date('h:i A', strtotime($paper['created_at'])); ?>
                                        </div>
                                        <!-- Download button restricted by payment approval -->
                                        <?php
                                        $ver_num = intval($paper['current_version'] ?? 1);
                                        $is_revision = $ver_num > 1;
                                        $dl_label = $is_revision ? '⬇ Download v' . $ver_num . ' (Revised)' : '⬇ Download Submitted';
                                        $dl_color = $is_revision ? '#d97706' : '#1e3a5f';
                                        $dl_bg    = $is_revision ? '#fef3c7' : '#eff6ff';
                                        $payment_approved = ($paper['payment_status'] === 'approved');
                                        ?>
                                        <div style="margin-top: 6px;">
                                            <?php if ($payment_approved): ?>
                                                <a href="<?php echo $path_prefix; ?>download.php?id=<?php echo $paper['id']; ?>&type=article" target="_blank"
                                                   style="display: inline-flex; align-items: center; gap: 5px; background: <?php echo $dl_bg; ?>; color: <?php echo $dl_color; ?>; border: 1px solid <?php echo $dl_color; ?>40; padding: 4px 10px; border-radius: 5px; font-size: 0.75rem; font-weight: 600; text-decoration: none;">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                                    <?php echo $dl_label; ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="display: inline-flex; align-items: center; gap: 5px; background: #f1f5f9; color: #94a3b8; border: 1px solid #cbd5e1; padding: 4px 10px; border-radius: 5px; font-size: 0.75rem; font-weight: 600; cursor: not-allowed;" title="Download is locked until payment is approved by admin">
                                                    🔒 Download Locked (Awaiting Payment Approval)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.85rem; background-color: #f1f5f9; padding: 4px 8px; border-radius: 4px;">
                                            <?php echo sanitize($paper['subject_domain']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $paper['status']; ?>">
                                            <?php 
                                            if ($paper['status'] == 'submitted_waiting_review') {
                                                echo 'Submitted (Waiting Review)';
                                            } else {
                                                echo str_replace('_', ' ', $paper['status']);
                                            }
                                            ?>
                                        </span>
                                        <?php if ($paper['status'] == 'payment_pending' && $paper['payment_status'] == 'pending'): ?>
                                            <span style="display:block; font-size:0.75rem; color: var(--warning-color); margin-top:4px; font-weight:600;">Payment Verification Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($paper['status'] == 'payment_pending' && $paper['payment_status'] != 'pending'): ?>
                                            <!-- Show Fixed Payment Amount and Pay Link -->
                                            <div style="margin-bottom: 8px;">
                                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--primary-color);">
                                                    Fee: ₹<?php echo number_format($paper['payment_amount'], 2); ?>
                                                </span>
                                            </div>
                                            <a href="<?php echo $path_prefix; ?>author/pay.php?id=<?php echo $paper['id']; ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.8rem;">
                                                Pay Now
                                            </a>
                                        <?php elseif ($paper['status'] == 'revisions_required'): ?>
                                            <a href="<?php echo $path_prefix; ?>author/submit.php?edit_id=<?php echo $paper['id']; ?>" class="btn btn-dark" style="padding: 6px 12px; font-size: 0.8rem; background-color: #d97706;">
                                                Upload Revision
                                            </a>
                                        <?php elseif ($paper['status'] == 'published'): ?>
                                            <a href="<?php echo $path_prefix; ?>journal-detail.php?id=<?php echo $paper['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.8rem; color: var(--primary-color); border: 1px solid var(--border-color);">
                                                View Published
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size: 0.85rem; color: var(--text-muted);">Under Review</span>
                                        <?php endif; ?>

                                        <!-- Document History Button & Acceptance Letter -->
                                        <div style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">
                                            <?php if (in_array($paper['status'], ['submitted_waiting_review', 'under_review', 'revisions_required'])): ?>
                                                <form id="delete-form-<?php echo $paper['id']; ?>" method="POST" action="" style="display: inline; margin: 0; padding: 0;">
                                                    <input type="hidden" name="action" value="delete_journal">
                                                    <input type="hidden" name="journal_id" value="<?php echo $paper['id']; ?>">
                                                    <button type="button" onclick="confirmDelete(<?php echo $paper['id']; ?>, '<?php echo addslashes(sanitize($paper['journal_number'])); ?>')" style="background: #fee2e2; border: 1px solid #fca5a5; color: #dc2626; font-size: 0.75rem; cursor: pointer; padding: 4px 10px; border-radius: 5px; display: inline-flex; align-items: center; gap: 4px; font-weight: 600; width: fit-content; transition: all 0.2s;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">
                                                        🗑️ Delete Submission
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <button onclick="openTimeline(<?php echo $paper['id']; ?>, '<?php echo addslashes(sanitize($paper['journal_number'])); ?>')" style="background: none; border: 1px solid #94a3b8; color: #475569; font-size: 0.75rem; cursor: pointer; padding: 4px 10px; border-radius: 5px; display: inline-flex; align-items: center; gap: 4px; font-weight: 500; width: fit-content;">
                                                📄 Document History
                                            </button>
                                            
                                            <?php if ($paper['assigned_reviewers'] > 0): ?>
                                                <a href="<?php echo $path_prefix; ?>download.php?id=<?php echo $paper['id']; ?>&type=acceptance" target="_blank"
                                                   style="background: #ecfdf5; border: 1px solid #059669; color: #047857; font-size: 0.75rem; padding: 4px 10px; border-radius: 5px; display: inline-flex; align-items: center; gap: 4px; font-weight: 600; text-decoration: none; width: fit-content;">
                                                    📩 Acceptance Letter
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!empty($paper['bill_number'])): ?>
                                                <a href="<?php echo $path_prefix; ?>download.php?id=<?php echo $paper['id']; ?>&type=invoice" target="_blank"
                                                   style="background: #f0fdf4; border: 1px solid #16a34a; color: #166534; font-size: 0.75rem; padding: 4px 10px; border-radius: 5px; display: inline-flex; align-items: center; gap: 4px; font-weight: 600; text-decoration: none; width: fit-content;">
                                                    🧾 GST Invoice
                                                </a>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Review Details Accordion/Button -->
                                        <?php
                                        // Get reviews for this paper with timestamps
                                        try {
                                            $rev_stmt = $pdo->prepare("SELECT r.id, r.comments, r.recommendation, r.created_at as review_date, u.fullname as reviewer_name FROM reviews r JOIN users u ON r.reviewer_id = u.id WHERE r.journal_id = ? ORDER BY r.created_at ASC");
                                            $rev_stmt->execute([$paper['id']]);
                                            $reviews = $rev_stmt->fetchAll();
                                        } catch (PDOException $e) {
                                            $reviews = [];
                                        }
                                        ?>
                                        <?php if (!empty($reviews)): ?>
                                            <div style="margin-top: 10px;">
                                                <?php
                                                $has_approval = false;
                                                foreach ($reviews as $rev) {
                                                    if ($rev['recommendation'] === 'approve') {
                                                        $has_approval = true;
                                                        break;
                                                    }
                                                }
                                                $display_count = $has_approval ? 3 : count($reviews);
                                                ?>
                                                <button onclick="toggleReviews(<?php echo $paper['id']; ?>)" style="background: none; border: none; color: var(--info-color); font-size: 0.8rem; cursor: pointer; text-decoration: underline; font-weight: 500;">
                                                    📋 View Feedback (<?php echo $display_count; ?>)
                                                </button>
                                                <div id="reviews-<?php echo $paper['id']; ?>" style="display: none; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px; padding: 14px; margin-top: 8px; font-size: 0.82rem; min-width: 320px; max-width: 480px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
                                                    
                                                    <!-- Original Submission Date -->
                                                    <div style="background: #e0f2fe; border-left: 3px solid #0284c7; border-radius: 4px; padding: 6px 10px; margin-bottom: 10px; font-size: 0.78rem;">
                                                        <span style="font-weight: 600; color: #0369a1;">📅 Originally Submitted:</span>
                                                        <span style="color: #0369a1;"> <?php echo date('d M Y', strtotime($paper['created_at'])); ?> at <?php echo date('h:i A', strtotime($paper['created_at'])); ?></span>
                                                    </div>

                                                    <?php if ($has_approval): ?>
                                                        <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-bottom: 10px; background: #ffffff;">
                                                            <div style="font-weight: 600; color: #16a34a; font-size: 0.85rem;">
                                                                Review Round 1 Approved
                                                            </div>
                                                        </div>
                                                        <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-bottom: 10px; background: #ffffff;">
                                                            <div style="font-weight: 600; color: #16a34a; font-size: 0.85rem;">
                                                                Review Round 2 Approved
                                                            </div>
                                                        </div>
                                                        <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-bottom: 10px; background: #ffffff;">
                                                            <div style="font-weight: 600; color: #16a34a; font-size: 0.85rem;">
                                                                Review Round 3 Approved
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php $review_count = 0; foreach ($reviews as $rev): $review_count++; ?>
                                                            <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-bottom: 10px; background: #ffffff;">
                                                                <!-- Review Round Label -->
                                                                <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                                                                    Review Round <?php echo $review_count; ?>
                                                                </div>

                                                                <!-- Action Date -->
                                                                <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 6px;">
                                                                    🕐 <strong>Action Date:</strong> <?php echo date('d M Y', strtotime($rev['review_date'])); ?> at <?php echo date('h:i A', strtotime($rev['review_date'])); ?>
                                                                </div>

                                                                <!-- Recommendation Badge -->
                                                                <div style="margin-bottom: 6px;">
                                                                    <?php
                                                                    $rec = $rev['recommendation'];
                                                                    $rec_color = ($rec == 'approve') ? '#16a34a' : (($rec == 'reject') ? '#dc2626' : '#d97706');
                                                                    $rec_bg = ($rec == 'approve') ? '#dcfce7' : (($rec == 'reject') ? '#fee2e2' : '#fef3c7');
                                                                    $rec_label = ($rec == 'approve') ? '✅ Approved' : (($rec == 'reject') ? '❌ Rejected' : '🔁 Revision Requested');
                                                                    ?>
                                                                    <span style="display: inline-block; background: <?php echo $rec_bg; ?>; color: <?php echo $rec_color; ?>; font-weight: 700; font-size: 0.75rem; padding: 3px 8px; border-radius: 12px; border: 1px solid <?php echo $rec_color; ?>20;">
                                                                        <?php echo $rec_label; ?>
                                                                    </span>
                                                                </div>

                                                                <!-- Comments -->
                                                                <div style="color: #475569; font-style: italic; font-size: 0.8rem; line-height: 1.5; border-top: 1px solid #f1f5f9; padding-top: 6px; margin-top: 4px;">
                                                                    "<?php echo sanitize($rev['comments']); ?>"
                                                                </div>
                                                            </div>

                                                            <!-- Revision Submitted Date (shown only if paper was updated after this review) -->
                                                            <?php if ($paper['updated_at'] && $paper['updated_at'] != $paper['created_at'] && strtotime($paper['updated_at']) > strtotime($rev['review_date'])): ?>
                                                                <div style="background: #fef9c3; border-left: 3px solid #ca8a04; border-radius: 4px; padding: 6px 10px; margin-bottom: 10px; font-size: 0.78rem;">
                                                                    <span style="font-weight: 600; color: #92400e;">📤 Revision Submitted:</span>
                                                                    <span style="color: #92400e;"> <?php echo date('d M Y', strtotime($paper['updated_at'])); ?> at <?php echo date('h:i A', strtotime($paper['updated_at'])); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
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

<!-- Document History Modal -->
<div id="timelineModal" class="modal-overlay" style="display: none; align-items: flex-start; padding-top: 40px;">
    <div class="modal-content" style="max-width: 600px; width: 95%; max-height: 80vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; position: sticky; top: 0; background: white; z-index: 1;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.1rem;">📄 Document History &mdash; <span id="tlJournalNo" style="font-weight: 400; font-size: 0.95rem;"></span></h3>
            <button onclick="closeTimeline()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <div id="timelineContent" style="padding: 4px 0;">
            <p style="text-align: center; color: var(--text-muted); padding: 2rem;">Loading...</p>
        </div>
    </div>
</div>

<!-- Hidden timeline data containers (one per paper) -->
<?php foreach ($submissions as $paper): ?>
<div id="tl-data-<?php echo $paper['id']; ?>" style="display: none;">
    <?php
    $timeline_journal_id = $paper['id'];
    $timeline_role = 'author';
    include __DIR__ . '/../includes/document_timeline.php';
    ?>
</div>
<?php endforeach; ?>

<!-- SweetAlert2 library -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
function confirmDelete(id, journalNo) {
    Swal.fire({
        title: 'Delete Submission?',
        html: 'Are you sure you want to delete manuscript <strong>' + journalNo + '</strong>?<br><br>This will permanently delete the manuscript file, co-author details, and all review history.<br><span style="color: #dc2626; font-weight: 600;">This action cannot be undone!</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#475569',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel',
        focusCancel: true
    }).then((result) => {
        if (result.isConfirmed) {
            // Find and submit the specific delete form
            var form = document.getElementById('delete-form-' + id);
            if (form) {
                form.submit();
            }
        }
    });
}

function toggleReviews(id) {
    var element = document.getElementById('reviews-' + id);
    if (element.style.display === 'none') {
        element.style.display = 'block';
    } else {
        element.style.display = 'none';
    }
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

// Close on backdrop click
document.getElementById('timelineModal').addEventListener('click', function(e) {
    if (e.target === this) closeTimeline();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
