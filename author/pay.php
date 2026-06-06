<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('author');

$user = get_logged_in_user();
$author_id = $user['id'];

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header("Location: /author/dashboard.php");
    exit;
}

// Fetch journal details and ensure it belongs to the author and is in 'payment_pending'
try {
    $stmt = $pdo->prepare("SELECT * FROM journals WHERE id = ? AND author_id = ? AND status = 'payment_pending'");
    $stmt->execute([$id, $author_id]);
    $journal = $stmt->fetch();
    
    if (!$journal) {
        header("Location: /author/dashboard.php");
        exit;
    }
    
    // Check if payment was already submitted
    $pay_stmt = $pdo->prepare("SELECT * FROM payments WHERE journal_id = ?");
    $pay_stmt->execute([$id]);
    $existing_payment = $pay_stmt->fetch();
    
    if ($existing_payment && $existing_payment['status'] === 'pending') {
        header("Location: /author/dashboard.php?error=payment_already_submitted");
        exit;
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_id = sanitize($_POST['transaction_id']);
    $payment_method = sanitize($_POST['payment_method'] ?? 'upi');
    if (!in_array($payment_method, ['upi', 'bank_transfer', 'cash', 'other'])) {
        $payment_method = 'upi';
    }
    
    $upload_ok = true;
    $proof_path = "";
    
    if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
        $file_name = $_FILES['payment_proof']['name'];
        $file_tmp = $_FILES['payment_proof']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($file_ext, $allowed_exts)) {
            $message = "Invalid file type. Only PDF, JPG, JPEG, and PNG receipt proof files are allowed.";
            $message_type = "danger";
            $upload_ok = false;
        } else {
            $upload_dir = __DIR__ . '/../uploads/payments/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_file_name = 'receipt_' . $id . '_' . time() . '.' . $file_ext;
            $dest_path = $upload_dir . $new_file_name;
            
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $proof_path = 'uploads/payments/' . $new_file_name;
            } else {
                $message = "Failed to save receipt proof. Please try again.";
                $message_type = "danger";
                $upload_ok = false;
            }
        }
    } else {
        $message = "Please upload transaction proof (image or PDF receipt).";
        $message_type = "danger";
        $upload_ok = false;
    }
    
    if ($upload_ok && !empty($transaction_id) && !empty($proof_path)) {
        try {
            // Check if we need to update or insert
            if ($existing_payment) {
                // If rejected previously, we can update it
                $stmt = $pdo->prepare("UPDATE payments SET transaction_id = ?, payment_proof = ?, payment_method = ?, status = 'pending', created_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$transaction_id, $proof_path, $payment_method, $existing_payment['id']]);
            } else {
                // First time inserting payment
                $stmt = $pdo->prepare("INSERT INTO payments (journal_id, transaction_id, payment_proof, payment_method, status) VALUES (?, ?, ?, ?, 'pending')");
                $stmt->execute([$id, $transaction_id, $proof_path, $payment_method]);
            }
            
            // Send payment submitted email to Admin
            try {
                require_once __DIR__ . '/../includes/mail_helper.php';
                $j_stmt = $pdo->prepare("SELECT * FROM journals WHERE id = ?");
                $j_stmt->execute([$id]);
                $j_info = $j_stmt->fetch();
                
                // Get Admin Email
                $adm_stmt = $pdo->prepare("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
                $adm_stmt->execute();
                $adm_email = $adm_stmt->fetchColumn() ?: 'admin@portal.com';
                
                if ($j_info) {
                    $payment_obj = [
                        'transaction_id' => $transaction_id,
                        'payment_method' => $payment_method
                    ];
                    rjpes_mail_payment_submitted($j_info, $user, $payment_obj, $adm_email);
                }
            } catch (Exception $ex) {
                // ignore email error
            }
            
            header("Location: /author/dashboard.php?success=payment_submitted");
            exit;
        } catch (PDOException $e) {
            $message = "Database entry failed: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

$page_title = "Submit Publication Payment";
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Publication Fee Payment - 2 Column Layout -->
<style>
.pay-page-wrap {
    width: 100%;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 2.5rem 1.5rem 4rem;
    background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 50%, #f5f3ff 100%);
    box-sizing: border-box;
}
.pay-inner {
    width: 100%;
    max-width: 1100px;
}
.pay-header {
    margin-bottom: 1.5rem;
}
.pay-header h2 {
    font-family: var(--font-heading);
    color: var(--primary-color);
    font-size: 1.6rem;
    margin: 0 0 4px 0;
}
.pay-header p {
    color: var(--text-muted);
    font-size: 0.9rem;
    margin: 0;
}
.pay-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 768px) {
    .pay-grid { grid-template-columns: 1fr; }
    .pay-page-wrap { padding: 1.5rem 1rem 3rem; }
}

/* Left: Payment Info Panel */
.pay-info-panel {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.pay-amount-card {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
    border-radius: 16px;
    padding: 1.75rem 2rem;
    text-align: center;
    color: white;
    box-shadow: 0 8px 24px rgba(37,99,235,0.25);
}
.pay-amount-card .label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    opacity: 0.75;
    font-weight: 600;
    margin-bottom: 8px;
    display: block;
}
.pay-amount-card .amount {
    font-size: 2.8rem;
    font-weight: 900;
    line-height: 1;
    letter-spacing: -1px;
}
.pay-amount-card .journal-ref {
    margin-top: 10px;
    font-size: 0.8rem;
    opacity: 0.7;
    word-break: break-all;
}
.pay-breakdown {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
}
.pay-breakdown .section-title {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.8px;
    margin-bottom: 10px;
    display: block;
}
.pay-breakdown-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
    padding: 6px 0;
    border-bottom: 1px dashed #f1f5f9;
    color: #334155;
}
.pay-breakdown-row:last-child { border-bottom: none; }
.pay-breakdown-row.total {
    font-weight: 700;
    font-size: 0.92rem;
    color: var(--primary-color);
    margin-top: 4px;
    padding-top: 10px;
    border-top: 2px solid #e2e8f0;
    border-bottom: none;
}
.pay-breakdown-row .row-label { color: #64748b; }
.pay-breakdown-row .row-val { font-weight: 600; font-family: monospace; }

/* Company Card */
.pay-company-card {
    background: linear-gradient(135deg, #0f2044 0%, #1e3a5f 100%);
    border-radius: 14px;
    padding: 1.25rem 1.5rem;
    color: white;
}
.pay-company-card .co-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.6;
    margin-bottom: 5px;
    display: block;
}
.pay-company-card .co-name {
    font-size: 1rem;
    font-weight: 800;
    margin-bottom: 2px;
}
.pay-company-card .co-full {
    font-size: 0.67rem;
    opacity: 0.7;
    line-height: 1.5;
    margin-bottom: 6px;
}
.pay-company-card .co-addr {
    font-size: 0.72rem;
    opacity: 0.7;
    line-height: 1.5;
}
.pay-company-card .co-gst {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid rgba(255,255,255,0.15);
    font-size: 0.75rem;
}
.pay-company-card .co-gst span {
    font-family: monospace;
    font-weight: 700;
    letter-spacing: 0.5px;
}

/* Bank Card */
.pay-bank-card {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 14px;
    padding: 1.2rem 1.5rem;
}
.pay-bank-card .bank-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #15803d;
    font-weight: 700;
    margin-bottom: 10px;
    display: block;
}
.pay-bank-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 5px 14px;
    font-size: 0.8rem;
}
.pay-bank-grid .bk { color: #64748b; font-weight: 600; white-space: nowrap; }
.pay-bank-grid .bv { font-weight: 700; color: #1e3a5f; font-family: monospace; }
.pay-bank-grid .bv.plain { font-family: inherit; font-weight: 600; }

/* UPI Card */
.pay-upi-card {
    background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%);
    border-radius: 12px;
    padding: 1rem 1.5rem;
    display: flex;
    align-items: center;
    gap: 14px;
    color: white;
}
.pay-upi-card .upi-icon { font-size: 1.6rem; flex-shrink: 0; }
.pay-upi-card .upi-label {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    opacity: 0.7;
    font-weight: 600;
    display: block;
    margin-bottom: 2px;
}
.pay-upi-card .upi-id {
    font-size: 1rem;
    font-weight: 800;
    font-family: monospace;
    letter-spacing: 0.5px;
}

/* Right: Form Panel */
.pay-form-panel {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.07);
    padding: 2rem;
    border-top: 4px solid #2563eb;
}
.pay-form-panel h3 {
    font-family: var(--font-heading);
    color: var(--primary-color);
    font-size: 1.15rem;
    margin: 0 0 1.5rem 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.pay-upload-zone {
    border: 2px dashed #c7d2fe;
    border-radius: 10px;
    background: #f5f7ff;
    padding: 1.5rem;
    text-align: center;
    transition: border-color 0.2s, background 0.2s;
    cursor: pointer;
    margin-bottom: 0.25rem;
}
.pay-upload-zone:hover { border-color: #6366f1; background: #eef2ff; }
.pay-upload-zone .upload-icon { font-size: 2rem; display: block; margin-bottom: 8px; }
.pay-upload-zone .upload-text { font-size: 0.82rem; color: #6366f1; font-weight: 600; display: block; }
.pay-upload-zone .upload-sub { font-size: 0.72rem; color: #94a3b8; margin-top: 4px; display: block; }
.pay-upload-zone input[type=file] { display: none; }
.pay-submit-btn {
    width: 100%;
    padding: 13px;
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
    color: white;
    border: none;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    margin-top: 1.5rem;
    letter-spacing: 0.3px;
    transition: opacity 0.2s, transform 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.pay-submit-btn:hover { opacity: 0.92; transform: translateY(-1px); }
.pay-back-link {
    display: block;
    text-align: center;
    margin-top: 12px;
    font-size: 0.82rem;
    color: var(--text-muted);
    text-decoration: none;
}
.pay-back-link:hover { color: var(--primary-color); text-decoration: underline; }

#fileNameDisplay {
    margin-top: 8px;
    font-size: 0.78rem;
    color: #6366f1;
    font-weight: 600;
    min-height: 18px;
    text-align: center;
}
</style>

<div class="pay-page-wrap">
    <div class="pay-inner">

        <!-- Page Header -->
        <div class="pay-header">
            <h2>📄 Publication Fee Payment</h2>
            <p>Finalize publishing of manuscript: <strong><?php echo sanitize($journal['journal_number']); ?></strong></p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom: 1.25rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <div class="pay-grid">

            <!-- ===== LEFT COLUMN: Payment Info ===== -->
            <div class="pay-info-panel">

                <!-- Amount Card -->
                <div class="pay-amount-card">
                    <span class="label">Total Amount Payable</span>
                    <div class="amount">₹<?php echo number_format($journal['payment_amount'], 2); ?></div>
                    <div class="journal-ref">Ref: <?php echo sanitize($journal['journal_number']); ?></div>
                </div>

                <!-- GST Breakdown (if applicable) -->
                <?php if (!empty($journal['gst_amount']) && floatval($journal['gst_amount']) > 0): ?>
                    <?php $gst_pct_setting = floatval(rjpes_get_setting('gst_percentage', '18')); ?>
                    <div class="pay-breakdown">
                        <span class="section-title">🧾 Tax &amp; Fee Breakdown</span>
                        <div class="pay-breakdown-row">
                            <span class="row-label">Base Processing Fee</span>
                            <span class="row-val">₹<?php echo number_format($journal['base_amount'], 2); ?></span>
                        </div>
                        <div class="pay-breakdown-row">
                            <span class="row-label">CGST (<?php echo ($gst_pct_setting/2); ?>%)</span>
                            <span class="row-val">₹<?php echo number_format($journal['gst_amount']/2, 2); ?></span>
                        </div>
                        <div class="pay-breakdown-row">
                            <span class="row-label">SGST (<?php echo ($gst_pct_setting/2); ?>%)</span>
                            <span class="row-val">₹<?php echo number_format($journal['gst_amount']/2, 2); ?></span>
                        </div>
                        <div class="pay-breakdown-row total">
                            <span>Total Payable</span>
                            <span>₹<?php echo number_format($journal['payment_amount'], 2); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Company Details -->
                <div class="pay-company-card">
                    <span class="co-label">Payee Company</span>
                    <div class="co-name">SaNDS Lab</div>
                    <div class="co-full">(Software and Network Development Solutions Lab)</div>
                    <div class="co-addr">XI/866, Chandanam Block, Infopark, Koratty,<br>Thrissur, Kerala &ndash; 680308</div>
                    <div class="co-gst">
                        GST No: &nbsp;<span>32ABQFS7745B1Z1</span>
                    </div>
                </div>

                <!-- Bank Account Details -->
                <div class="pay-bank-card">
                    <span class="bank-label">🏦 Bank Account Details</span>
                    <div class="pay-bank-grid">
                        <span class="bk">A/No:</span>   <span class="bv">17540200000152</span>
                        <span class="bk">A/Name:</span> <span class="bv plain">SaNDSLab</span>
                        <span class="bk">Bank:</span>    <span class="bv plain">Federal Bank</span>
                        <span class="bk">Branch:</span>  <span class="bv plain" style="font-family:inherit; font-size:0.78rem;">Koratty Infopark Extension</span>
                        <span class="bk">IFSC:</span>    <span class="bv">FDRL0001754</span>
                    </div>
                </div>

                <!-- UPI + QR -->
                <div class="pay-upi-card" style="flex-direction: column; align-items: stretch; padding: 0; overflow: hidden; gap: 0;">
                    <!-- Top: UPI info row -->
                    <div style="display: flex; align-items: center; gap: 14px; padding: 1rem 1.5rem;">
                        <div class="upi-icon">📱</div>
                        <div style="flex: 1;">
                            <span class="upi-label">UPI ID &mdash; Scan to Pay</span>
                            <div class="upi-id">sandslab2023@fbl</div>
                        </div>
                    </div>
                    <!-- Divider -->
                    <div style="border-top: 1px solid rgba(255,255,255,0.12);"></div>
                    <!-- Bottom: QR thumbnail -->
                    <div style="display: flex; align-items: center; justify-content: center; padding: 1.1rem 1.5rem; gap: 16px;">
                        <div id="qrThumbWrap" onclick="openQRModal()" style="cursor: pointer; position: relative; flex-shrink: 0;">
                            <img id="upiQrThumb" src="../assets/QR/upi_qr.jpg" alt="UPI QR Code - Tap to enlarge"
                                style="width: 88px; height: 88px; object-fit: cover; border-radius: 10px; border: 3px solid rgba(255,255,255,0.85); display: block; box-shadow: 0 4px 14px rgba(0,0,0,0.3); transition: transform 0.2s;"
                                onmouseover="this.style.transform='scale(1.06)'" onmouseout="this.style.transform='scale(1)'">
                            <div style="position: absolute; bottom: -6px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.65); color: white; font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 2px 8px; border-radius: 20px; white-space: nowrap;">
                                🔍 Tap to Enlarge
                            </div>
                        </div>
                        <div style="flex: 1; font-size: 0.75rem; color: rgba(255,255,255,0.8); line-height: 1.55;">
                            <div style="font-weight: 700; color: white; font-size: 0.82rem; margin-bottom: 4px;">📲 How to Pay</div>
                            <span class="desktop-instructions">Open any UPI app (GPay, PhonePe, Paytm, BHIM) &rarr; Scan QR &rarr; Amount and note will be prefilled &rarr; Pay &amp; save receipt.</span>
                            <!-- Mobile-only payment shortcut -->
                            <div class="mobile-only-pay-btn" style="margin-top: 8px; display: none;">
                                <a class="upiPayDeepLinkClass" href="#" style="
                                    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
                                    background: linear-gradient(135deg, #10b981, #059669);
                                    color: white; border: none; border-radius: 8px;
                                    padding: 8px 14px; font-size: 0.75rem; font-weight: 700;
                                    text-decoration: none; box-shadow: 0 4px 10px rgba(16,185,129,0.25);
                                    transition: transform 0.2s, opacity 0.2s;
                                " onmouseover="this.style.opacity='0.92'; this.style.transform='translateY(-1px)'" onmouseout="this.style.opacity='1'; this.style.transform='translateY(0)'">
                                    📱 Pay directly via UPI App
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ===== RIGHT COLUMN: Upload Form ===== -->
            <div class="pay-form-panel">
                <h3>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Upload Payment Receipt
                </h3>

                <form action="pay.php?id=<?php echo $id; ?>" method="POST" enctype="multipart/form-data">

                    <div class="form-group">
                        <label for="payment_method" style="font-weight: 600; font-size: 0.85rem; color: var(--primary-color);">Payment Method Used</label>
                        <select name="payment_method" id="payment_method" class="form-control" required style="font-size: 0.88rem;">
                            <option value="upi">📱 UPI Transfer</option>
                            <option value="bank_transfer">🏦 Bank Transfer (NEFT / IMPS)</option>
                            <option value="cash">💵 Cash / Direct Deposit</option>
                            <option value="other">🔄 Other Payment Method</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="transaction_id" style="font-weight: 600; font-size: 0.85rem; color: var(--primary-color);">Transaction ID / Reference Number</label>
                        <input type="text" name="transaction_id" id="transaction_id" class="form-control" placeholder="Enter bank transaction or UPI Ref No." required style="font-size: 0.88rem;">
                    </div>

                    <!-- Stylish Upload Zone -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-weight: 600; font-size: 0.85rem; color: var(--primary-color); display: block; margin-bottom: 8px;">Payment Proof Receipt</label>
                        <div class="pay-upload-zone" onclick="document.getElementById('payment_proof').click()">
                            <span class="upload-icon">📎</span>
                            <span class="upload-text">Click to browse &amp; upload your receipt</span>
                            <span class="upload-sub">Accepted: PDF, JPG, JPEG, PNG &nbsp;·&nbsp; Max 5 MB</span>
                            <input type="file" name="payment_proof" id="payment_proof" accept=".pdf,.jpg,.jpeg,.png" required
                                onchange="document.getElementById('fileNameDisplay').textContent = this.files[0] ? '✅ ' + this.files[0].name : ''">
                        </div>
                        <div id="fileNameDisplay"></div>
                        <small style="color: var(--text-muted); font-size: 0.72rem; display: block; margin-top: 4px;">Upload screenshot of UPI payment confirmation or bank deposit slip.</small>
                    </div>

                    <!-- Important Note -->
                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; margin-top: 1.25rem; font-size: 0.8rem; color: #92400e; line-height: 1.5;">
                        <strong>⚠️ Important:</strong> After submitting, an admin will verify your payment and approve your manuscript for publication. You will be notified via email.
                    </div>

                    <button type="submit" class="pay-submit-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Submit Payment Proof
                    </button>

                    <a href="<?php echo $path_prefix; ?>author/dashboard.php" class="pay-back-link">← Back to Dashboard</a>
                </form>
            </div>

        </div><!-- end .pay-grid -->
    </div><!-- end .pay-inner -->
</div><!-- end .pay-page-wrap -->

<!-- ===== QR Code Lightbox Modal ===== -->
<div id="qrLightbox" onclick="if(event.target===this)closeQRModal()" style="
    display: none;
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,0.82);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    align-items: center; justify-content: center;
    animation: qrFadeIn 0.25s ease;
">
    <div style="
        background: white;
        border-radius: 20px;
        padding: 24px;
        max-width: 440px;
        width: 92%;
        text-align: center;
        position: relative;
        box-shadow: 0 24px 60px rgba(0,0,0,0.5);
        animation: qrSlideUp 0.28s cubic-bezier(.4,1.4,.6,1);
    ">
        <!-- Close Button -->
        <button onclick="closeQRModal()" style="
            position: absolute; top: 14px; right: 14px;
            background: #f1f5f9; border: none; border-radius: 50%;
            width: 34px; height: 34px; font-size: 1.1rem;
            cursor: pointer; color: #64748b; display: flex;
            align-items: center; justify-content: center;
            transition: background 0.15s;
        " onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">&times;</button>

        <!-- Header -->
        <div style="margin-bottom: 16px;">
            <div style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; margin-bottom: 4px;">Federal Bank &mdash; UPI QR Code</div>
            <div style="font-size: 1.05rem; font-weight: 800; color: #1e3a5f;">Scan &amp; Pay Instantly</div>
        </div>

        <!-- QR Image with pulse ring -->
        <div style="position: relative; display: inline-block; margin-bottom: 16px;">
            <div class="qr-pulse-ring"></div>
            <img id="upiQrLarge" src="../assets/QR/upi_qr.jpg" alt="UPI QR Code"
                style="width: 280px; max-width: 100%; border-radius: 12px; display: block;
                       border: 4px solid #f1f5f9; box-shadow: 0 4px 20px rgba(0,0,0,0.12);">
        </div>

        <!-- UPI ID chip -->
        <div style="display: inline-flex; align-items: center; gap: 8px; background: linear-gradient(135deg,#4c1d95,#6d28d9); color: white; border-radius: 30px; padding: 7px 20px; font-size: 0.9rem; font-weight: 800; font-family: monospace; letter-spacing: 0.5px; margin-bottom: 14px;">
            📱 sandslab2023@fbl
        </div>

        <!-- Mobile Deep Link Payment Button inside Modal -->
        <div class="mobile-only-pay-btn" style="margin-bottom: 14px; display: none;">
            <a class="upiPayDeepLinkClass" href="#" style="
                display: inline-flex; align-items: center; justify-content: center; gap: 8px;
                background: linear-gradient(135deg, #16a34a, #10b981);
                color: white; border: none; border-radius: 10px;
                padding: 10px 20px; font-size: 0.88rem; font-weight: 700;
                text-decoration: none; box-shadow: 0 4px 12px rgba(22,163,74,0.3);
                transition: transform 0.2s, opacity 0.2s;
                width: 100%; box-sizing: border-box;
            " onmouseover="this.style.opacity='0.92'; this.style.transform='translateY(-1px)'" onmouseout="this.style.opacity='1'; this.style.transform='translateY(0)'">
                📱 Pay via UPI App (GPay/PhonePe)
            </a>
        </div>

        <!-- App logos hint -->
        <div style="font-size: 0.72rem; color: #94a3b8; line-height: 1.5;">
            Use GPay &bull; PhonePe &bull; Paytm &bull; BHIM &bull; FedMobile &bull; Any UPI App
        </div>

        <!-- Close CTA -->
        <button onclick="closeQRModal()" style="
            margin-top: 18px; width: 100%; padding: 11px;
            background: linear-gradient(135deg, #1e3a5f, #2563eb);
            color: white; border: none; border-radius: 10px;
            font-size: 0.88rem; font-weight: 700; cursor: pointer;
            transition: opacity 0.2s;
        " onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">✖ Close</button>
    </div>
</div>

<style>
@keyframes qrFadeIn {
    from { opacity: 0; } to { opacity: 1; }
}
@keyframes qrSlideUp {
    from { opacity: 0; transform: translateY(24px) scale(0.96); }
    to   { opacity: 1; transform: translateY(0)    scale(1);    }
}
.qr-pulse-ring {
    position: absolute;
    inset: -8px;
    border-radius: 18px;
    border: 3px solid #6d28d9;
    animation: qrPulse 1.8s ease-in-out infinite;
    pointer-events: none;
}
@keyframes qrPulse {
    0%,100% { opacity: 0.25; transform: scale(1);    }
    50%      { opacity: 0.7;  transform: scale(1.035); }
}
</style>

<!-- Load client-side QR Code Generator library from CDN -->
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>

<script>
// Dynamic UPI Link parameters
(function() {
    var payeeVpa = "sandslab2023@fbl";
    var payeeName = "SaNDS Lab";
    var amount = "<?php echo number_format($journal['payment_amount'], 2, '.', ''); ?>";
    var currency = "INR";
    var transactionNote = "<?php echo rawurlencode('Payment for ' . $journal['journal_number']); ?>";

    // Construct UPI URI
    var upiLink = "upi://pay?pa=" + encodeURIComponent(payeeVpa) + 
                  "&pn=" + encodeURIComponent(payeeName) + 
                  "&am=" + encodeURIComponent(amount) + 
                  "&cu=" + encodeURIComponent(currency) + 
                  "&tn=" + decodeURIComponent(transactionNote);

    // Generate dynamic QR Code using QRCode library
    if (typeof QRCode !== 'undefined') {
        QRCode.toDataURL(upiLink, { width: 300, margin: 2 }, function (err, url) {
            if (err) {
                console.error('Error generating dynamic QR code:', err);
                return;
            }
            var thumbImg = document.getElementById('upiQrThumb');
            var largeImg = document.getElementById('upiQrLarge');
            if (thumbImg) thumbImg.src = url;
            if (largeImg) largeImg.src = url;
        });
    }

    // Mobile check & display payment links
    if (/Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
        // Show all mobile-only pay buttons
        var btns = document.querySelectorAll('.mobile-only-pay-btn');
        btns.forEach(function(btn) {
            btn.style.display = 'block';
        });
        
        // Populate deep links
        var deepLinks = document.querySelectorAll('.upiPayDeepLinkClass');
        deepLinks.forEach(function(link) {
            link.href = upiLink;
        });

        // Hide desktop-only text
        var desktopText = document.querySelector('.desktop-instructions');
        if (desktopText) {
            desktopText.style.display = 'none';
        }
    }
})();

function openQRModal() {
    var m = document.getElementById('qrLightbox');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeQRModal() {
    var m = document.getElementById('qrLightbox');
    m.style.display = 'none';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeQRModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
