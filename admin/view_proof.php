<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    die("Invalid journal ID.");
}

try {
    $stmt = $pdo->prepare("SELECT j.id, j.journal_number, j.title, j.payment_amount, j.verifier_cut, j.admin_cut, j.portal_cut,
                                 p.transaction_id, p.payment_proof, p.payment_method, p.status as payment_status 
                            FROM journals j 
                            JOIN payments p ON j.id = p.journal_id 
                            WHERE j.id = ?");
    $stmt->execute([$id]);
    $payment = $stmt->fetch();
    
    if (!$payment) {
        die("Payment record not found for this manuscript.");
    }
    
    // Fetch active settings for the publish modal
    $current_vol = rjpes_get_setting('current_volume', '20');
    $current_issue = rjpes_get_setting('current_issue', '1');
    $current_edition_date = rjpes_get_setting('current_edition_date', date('Y-m-d'));
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$page_title = "Verify Payment Proof";
// Calculate path prefix to support root, subdirectories, and port mapping robustly
$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$depth = substr_count($request_path, '/') - 1;
if ($depth < 0) $depth = 0;
$path_prefix = str_repeat('../', $depth);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div style="margin-bottom: 2rem;">
        <a href="dashboard.php" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; padding: 8px 16px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Dashboard
        </a>
    </div>

    <div class="card" style="border-top: 4px solid var(--accent-color); padding: 2.5rem;">
        <h2 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1.5rem; font-size: 1.8rem;">Payment Receipt Verification</h2>
        
        <div style="background-color: #f8fafc; border: 1px solid var(--border-color); padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
            <div>
                <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 4px;">Manuscript</span>
                <span style="font-weight: bold; color: var(--primary-color); font-size: 1.05rem;"><?php echo sanitize($payment['journal_number']); ?></span>
                <span style="display: block; font-size: 0.88rem; color: var(--text-color); margin-top: 4px; line-height: 1.4;"><?php echo sanitize($payment['title']); ?></span>
            </div>
            <div>
                <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 4px;">Payment Method & Ref</span>
                <span style="font-size: 0.82rem; font-weight: 600; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 3px 8px; border-radius: 4px; display: inline-block; margin-bottom: 6px; text-transform: uppercase;">
                    <?php echo str_replace('_', ' ', sanitize($payment['payment_method'])); ?>
                </span>
                <span style="font-size: 1.15rem; font-weight: bold; color: #15803d; background-color: #f0fdf4; border: 1px solid #bbf7d0; padding: 6px 12px; border-radius: 6px; display: block; word-break: break-all; font-family: monospace;">
                    <?php echo sanitize($payment['transaction_id']); ?>
                </span>
            </div>
            <div>
                <span style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 4px;">Publication Fee & Split Cuts</span>
                <span style="font-size: 1.3rem; font-weight: bold; color: var(--primary-color); display: block; margin-bottom: 4px;">₹<?php echo number_format($payment['payment_amount'], 2); ?></span>
                <?php if ($payment['verifier_cut'] !== null): ?>
                    <div style="font-size: 0.82rem; color: var(--text-muted); line-height: 1.45;">
                        • Verifier: ₹<?php echo number_format($payment['verifier_cut'], 2); ?><br>
                        • Admin: ₹<?php echo number_format($payment['admin_cut'], 2); ?><br>
                        • Portal: ₹<?php echo number_format($payment['portal_cut'], 2); ?>
                    </div>
                <?php else: ?>
                    <span style="font-size: 0.78rem; color: var(--text-muted);">No cuts fixed (algorithm applies)</span>
                <?php endif; ?>
            </div>
        </div>

        <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1.2rem; font-size: 1.4rem;">Receipt Document</h3>
        
        <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 15px; background: #f1f5f9; text-align: center; min-height: 400px; display: flex; justify-content: center; align-items: center; overflow: hidden; margin-bottom: 2rem;">
            <?php
            $file_path = $path_prefix . $payment['payment_proof'];
            $file_ext = strtolower(pathinfo($payment['payment_proof'], PATHINFO_EXTENSION));
            
            if ($file_ext === 'pdf'):
            ?>
                <embed src="<?php echo $file_path; ?>" type="application/pdf" width="100%" height="600px" style="border: none; border-radius: 6px;" />
            <?php else: ?>
                <img src="<?php echo $file_path; ?>" alt="Receipt Proof" style="max-width: 100%; max-height: 800px; object-fit: contain; border-radius: 6px; box-shadow: var(--shadow-sm);" />
            <?php endif; ?>
        </div>

        <div style="margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem; display: flex; gap: 15px; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div style="font-size: 0.95rem; font-weight: 600; color: #a16207; display: flex; align-items: center; gap: 6px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background-color: #ca8a04;"></span>
                Current Payment Status: <?php echo ucfirst($payment['payment_status']); ?>
            </div>
            
            <button onclick="openPublishModal(<?php echo $payment['id']; ?>, '<?php echo addslashes(sanitize($payment['journal_number'])); ?>')" type="button" class="btn btn-primary" style="background-color: var(--success-color); color: white; padding: 12px 24px; font-weight: 600; border: none;">
                Verify Payment & Publish Manuscript
            </button>
        </div>
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
                Set publication Volume, Issue number and Publication Date for manuscript <strong id="publishJournalNo"></strong>. Confirming this action marks payment verified and displays the journal in public listings.
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

<script>
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
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
