<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$selected_month = isset($_GET['month']) ? sanitize($_GET['month']) : date('Y-m');

// Fetch available distinct months from both payments and credit_notes
try {
    $months_stmt = $pdo->query("
        SELECT DISTINCT DATE_FORMAT(p.created_at, '%Y-%m') AS m_val
        FROM payments p
        WHERE p.status = 'approved'
        UNION
        SELECT DISTINCT DATE_FORMAT(cn.created_at, '%Y-%m') AS m_val
        FROM credit_notes cn
        ORDER BY m_val DESC
    ");
    $available_months = $months_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($available_months) || !in_array(date('Y-m'), $available_months)) {
        $available_months[] = date('Y-m');
        rsort($available_months);
    }
} catch (PDOException $e) {
    $available_months = [date('Y-m')];
}

// Handle ZIP Download Action
if (isset($_GET['action']) && $_GET['action'] === 'download_zip') {
    require_once __DIR__ . '/../includes/pdf_helper.php';
    
    // Fetch invoices for that month
    $inv_stmt = $pdo->prepare("
        SELECT j.*, 
               (CASE WHEN j.base_amount IS NULL OR j.base_amount = 0 THEN j.payment_amount - COALESCE(j.gst_amount, 0) ELSE j.base_amount END) AS base_amount,
               COALESCE(j.gst_amount, 0) AS gst_amount,
               u.fullname AS author_name, u.email AS author_email, p.transaction_id, p.created_at AS payment_date
        FROM journals j
        JOIN payments p ON j.id = p.journal_id
        JOIN users u ON j.author_id = u.id
        WHERE p.status = 'approved' AND DATE_FORMAT(p.created_at, '%Y-%m') = ?
    ");
    $inv_stmt->execute([$selected_month]);
    $invoices = $inv_stmt->fetchAll();
    
    // Fetch credit notes for that month
    $cn_stmt = $pdo->prepare("
        SELECT cn.*, j.title, j.journal_number, u.fullname AS author_name, u.email AS author_email, j.subject_domain
        FROM credit_notes cn
        JOIN journals j ON cn.journal_id = j.id
        JOIN users u ON j.author_id = u.id
        WHERE DATE_FORMAT(cn.created_at, '%Y-%m') = ?
    ");
    $cn_stmt->execute([$selected_month]);
    $credit_notes = $cn_stmt->fetchAll();
    
    if (empty($invoices) && empty($credit_notes)) {
        echo "<script>alert('No invoices or credit notes found for the selected month.'); window.history.back();</script>";
        exit;
    }
    
    $zip = new ZipArchive();
    $zip_filename = "RJPES_Tax_Docs_" . $selected_month . ".zip";
    $temp_file = tempnam(sys_get_temp_dir(), 'zip');
    
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        // Loop through invoices
        foreach ($invoices as $inv) {
            if (empty($inv['bill_number'])) continue;
            $pdf = new RJPES_PDF();
            $pdf_data = $pdf->generateGSTInvoice($inv);
            $clean_bill = str_replace(['/', '\\'], '_', $inv['bill_number']);
            $zip->addFromString("Invoice_" . $clean_bill . ".pdf", $pdf_data);
        }
        
        // Loop through credit notes
        foreach ($credit_notes as $cn) {
            $pdf = new RJPES_PDF();
            $pdf_data = $pdf->generateCreditNote($cn);
            $clean_cn = str_replace(['/', '\\'], '_', $cn['credit_note_number']);
            $zip->addFromString("CreditNote_" . $clean_cn . ".pdf", $pdf_data);
        }
        
        $zip->close();
        
        // Stream ZIP file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($temp_file));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($temp_file);
        @unlink($temp_file);
        exit;
    } else {
        die("Failed to create ZIP archive.");
    }
}

// Query Monthly Statistics
try {
    // 1. Invoices stats
    $inv_stats_stmt = $pdo->prepare("
        SELECT COUNT(*) AS invoice_count,
               COALESCE(SUM(CASE WHEN j.base_amount IS NULL OR j.base_amount = 0 THEN j.payment_amount - COALESCE(j.gst_amount, 0) ELSE j.base_amount END), 0) AS total_base,
               COALESCE(SUM(j.gst_amount), 0) AS total_gst,
               COALESCE(SUM(j.payment_amount), 0) AS total_amount
        FROM journals j
        JOIN payments p ON j.id = p.journal_id
        WHERE p.status = 'approved' AND DATE_FORMAT(p.created_at, '%Y-%m') = ?
    ");
    $inv_stats_stmt->execute([$selected_month]);
    $inv_stats = $inv_stats_stmt->fetch();
    
    // 2. Credit note stats
    $cn_stats_stmt = $pdo->prepare("
        SELECT COUNT(*) AS cn_count,
               COALESCE(SUM(base_amount), 0) AS total_cn_base,
               COALESCE(SUM(gst_amount), 0) AS total_cn_gst,
               COALESCE(SUM(amount), 0) AS total_cn_amount
        FROM credit_notes
        WHERE DATE_FORMAT(created_at, '%Y-%m') = ?
    ");
    $cn_stats_stmt->execute([$selected_month]);
    $cn_stats = $cn_stats_stmt->fetch();
    
    // Calculations
    $total_invoices_count = intval($inv_stats['invoice_count']);
    $total_cn_count = intval($cn_stats['cn_count']);
    
    $gross_collected = floatval($inv_stats['total_amount']);
    $gross_base = floatval($inv_stats['total_base']);
    $gross_gst = floatval($inv_stats['total_gst']);
    
    $reversed_amount = floatval($cn_stats['total_cn_amount']);
    $reversed_base = floatval($cn_stats['total_cn_base']);
    $reversed_gst = floatval($cn_stats['total_cn_gst']);
    
    $net_revenue = $gross_base - $reversed_base;
    $net_gst = $gross_gst - $reversed_gst;
    $net_collected = $gross_collected - $reversed_amount;
} catch (PDOException $e) {
    die("Error loading page stats: " . $e->getMessage());
}

// Fetch lists for DataTables
try {
    $invoices_list_stmt = $pdo->prepare("
        SELECT j.id, j.title, j.journal_number, j.bill_number, 
               (CASE WHEN j.base_amount IS NULL OR j.base_amount = 0 THEN j.payment_amount - COALESCE(j.gst_amount, 0) ELSE j.base_amount END) AS base_amount, 
               COALESCE(j.gst_amount, 0) AS gst_amount, j.payment_amount,
               u.fullname AS author_name, u.email AS author_email, p.transaction_id, p.created_at AS payment_date
        FROM journals j
        JOIN payments p ON j.id = p.journal_id
        JOIN users u ON j.author_id = u.id
        WHERE p.status = 'approved' AND DATE_FORMAT(p.created_at, '%Y-%m') = ?
        ORDER BY p.created_at DESC
    ");
    $invoices_list_stmt->execute([$selected_month]);
    $invoices_list = $invoices_list_stmt->fetchAll();
    
    $cn_list_stmt = $pdo->prepare("
        SELECT cn.*, j.title, j.journal_number, u.fullname AS author_name, u.email AS author_email
        FROM credit_notes cn
        JOIN journals j ON cn.journal_id = j.id
        JOIN users u ON j.author_id = u.id
        WHERE DATE_FORMAT(cn.created_at, '%Y-%m') = ?
        ORDER BY cn.created_at DESC
    ");
    $cn_list_stmt->execute([$selected_month]);
    $cn_list = $cn_list_stmt->fetchAll();
} catch (PDOException $e) {
    $invoices_list = [];
    $cn_list = [];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <main class="main-content" style="width: 100%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h2 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 4px;">Month-Wise Invoices & GST Report</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">Calculate GST payable and download billing documents for tax filings</p>
            </div>
            
            <!-- Filters Form -->
            <form action="invoices.php" method="GET" style="display: flex; gap: 10px; align-items: center; background: white; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <label for="month" style="font-size: 0.85rem; font-weight: 600; color: var(--primary-color);">Filter Month:</label>
                <select name="month" id="month" class="form-control" style="width: 150px; font-size: 0.85rem; padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px;" onchange="this.form.submit()">
                    <?php foreach ($available_months as $m): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($selected_month === $m) ? 'selected' : ''; ?>>
                            <?php echo date('F Y', strtotime($m . '-01')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-dark" style="padding: 6px 12px; font-size: 0.85rem; border-radius: 6px;">Filter</button>
            </form>
        </div>

        <!-- Monthly Stats Overview Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
            <!-- Gross Revenue -->
            <div class="card" style="padding: 1.5rem; border-left: 4px solid var(--primary-color); margin-bottom: 0;">
                <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Gross Collected (GST Inc)</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: var(--primary-color); margin-top: 5px;">₹<?php echo number_format($gross_collected, 2); ?></div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 6px;">From <?php echo $total_invoices_count; ?> approved invoices</div>
            </div>

            <!-- GST Obligation -->
            <div class="card" style="padding: 1.5rem; border-left: 4px solid var(--warning-color); margin-bottom: 0;">
                <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Gross GST Collected</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: var(--warning-color); margin-top: 5px;">₹<?php echo number_format($gross_gst, 2); ?></div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 6px;">CGST + SGST liabilities</div>
            </div>

            <!-- GST Reversed -->
            <div class="card" style="padding: 1.5rem; border-left: 4px solid #ef4444; margin-bottom: 0;">
                <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">GST Reversed (CN)</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: #ef4444; margin-top: 5px;">- ₹<?php echo number_format($reversed_gst, 2); ?></div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 6px;">From <?php echo $total_cn_count; ?> credit notes issued</div>
            </div>

            <!-- Net GST Payable -->
            <div class="card" style="padding: 1.5rem; border-left: 4px solid var(--success-color); margin-bottom: 0;">
                <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Net GST to Pay</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: var(--success-color); margin-top: 5px;">₹<?php echo number_format($net_gst, 2); ?></div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 6px;">Net tax obligation due</div>
            </div>

            <!-- Net Revenue -->
            <div class="card" style="padding: 1.5rem; border-left: 4px solid var(--accent-color); margin-bottom: 0;">
                <div style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Net Revenue (Base Fee)</div>
                <div style="font-size: 1.6rem; font-weight: 700; color: var(--primary-color); margin-top: 5px;">₹<?php echo number_format($net_revenue, 2); ?></div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 6px;">Excludes GST & Credits</div>
            </div>
        </div>

        <!-- Download Action Panel -->
        <div class="card" style="padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; border-left: 4px solid var(--accent-color); background: #f8fafc; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h4 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 4px;">Download Document Bundle</h4>
                <p style="color: var(--text-muted); font-size: 0.82rem; margin: 0;">Get a single ZIP archive containing all Tax Invoices and Credit Notes issued in <strong><?php echo date('F Y', strtotime($selected_month . '-01')); ?></strong></p>
            </div>
            <div>
                <a href="invoices.php?action=download_zip&month=<?php echo $selected_month; ?>" class="btn btn-primary" style="padding: 10px 20px; font-weight: 600; font-size: 0.9rem; background-color: var(--primary-color); color: white; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download all together (ZIP)
                </a>
            </div>
        </div>

        <!-- Invoices List Table Card -->
        <div class="card" style="padding: 1.5rem; margin-bottom: 2.5rem;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px;">
                <span>📄</span> Invoices Generated
            </h3>
            
            <?php if (empty($invoices_list)): ?>
                <div style="text-align: center; padding: 2rem 1rem; color: var(--text-muted);">
                    <p style="margin: 0; font-weight: 500;">No invoices generated in this month.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Invoice No</th>
                                <th>Date</th>
                                <th>Ref No</th>
                                <th>Manuscript Title</th>
                                <th>Corresponding Author</th>
                                <th style="text-align: right;">Base Fee</th>
                                <th style="text-align: right;">GST</th>
                                <th style="text-align: right;">Total Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices_list as $inv): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--primary-color);">
                                        <?php echo !empty($inv['bill_number']) ? htmlspecialchars($inv['bill_number']) : 'N/A (Legacy)'; ?>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($inv['payment_date'])); ?></td>
                                    <td style="font-size: 0.85rem; font-weight: 500; color: #475569;"><?php echo htmlspecialchars($inv['journal_number']); ?></td>
                                    <td>
                                        <div style="max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($inv['title']); ?>">
                                            <?php echo htmlspecialchars($inv['title']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.88rem; font-weight: 500;"><?php echo htmlspecialchars($inv['author_name']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($inv['author_email']); ?></div>
                                    </td>
                                    <td style="text-align: right; font-weight: 500;">₹<?php echo number_format($inv['base_amount'], 2); ?></td>
                                    <td style="text-align: right; font-weight: 500; color: var(--warning-color);">₹<?php echo number_format($inv['gst_amount'], 2); ?></td>
                                    <td style="text-align: right; font-weight: 700; color: var(--primary-color);">₹<?php echo number_format($inv['payment_amount'], 2); ?></td>
                                    <td>
                                        <?php if (!empty($inv['bill_number'])): ?>
                                            <a href="<?php echo $path_prefix; ?>download.php?id=<?php echo $inv['id']; ?>&type=invoice" target="_blank" class="btn btn-primary" style="padding: 4px 8px; font-size: 0.75rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;">
                                                ⬇ PDF
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">No Invoice</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Credit Notes List Table Card -->
        <div class="card" style="padding: 1.5rem;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px;">
                <span>🔄</span> Credit Notes Issued
            </h3>
            
            <?php if (empty($cn_list)): ?>
                <div style="text-align: center; padding: 2rem 1rem; color: var(--text-muted);">
                    <p style="margin: 0; font-weight: 500;">No Credit Notes issued in this month.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Credit Note No</th>
                                <th>Date</th>
                                <th>Original Invoice</th>
                                <th>Ref No</th>
                                <th>Manuscript Title</th>
                                <th>Author Info</th>
                                <th style="text-align: right;">Base Credited</th>
                                <th style="text-align: right;">GST Reversed</th>
                                <th style="text-align: right;">Total Credit</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cn_list as $cn): ?>
                                <tr>
                                    <td style="font-weight: 600; color: #ef4444;"><?php echo htmlspecialchars($cn['credit_note_number']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($cn['created_at'])); ?></td>
                                    <td style="font-size: 0.85rem; font-weight: 500; color: #475569;"><?php echo htmlspecialchars($cn['bill_number']); ?></td>
                                    <td style="font-size: 0.85rem; font-weight: 500; color: #475569;"><?php echo htmlspecialchars($cn['journal_number']); ?></td>
                                    <td>
                                        <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($cn['title']); ?>">
                                            <?php echo htmlspecialchars($cn['title']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.88rem; font-weight: 500;"><?php echo htmlspecialchars($cn['author_name']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($cn['author_email']); ?></div>
                                    </td>
                                    <td style="text-align: right; font-weight: 500;">₹<?php echo number_format($cn['base_amount'], 2); ?></td>
                                    <td style="text-align: right; font-weight: 500; color: #ef4444;">₹<?php echo number_format($cn['gst_amount'], 2); ?></td>
                                    <td style="text-align: right; font-weight: 700; color: #b91c1c;">₹<?php echo number_format($cn['amount'], 2); ?></td>
                                    <td>
                                        <a href="<?php echo $path_prefix; ?>download.php?id=<?php echo $cn['id']; ?>&type=credit_note" target="_blank" class="btn btn-primary" style="padding: 4px 8px; font-size: 0.75rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; background-color: #ef4444; border-color: #ef4444; color: white;">
                                            ⬇ PDF
                                        </a>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
