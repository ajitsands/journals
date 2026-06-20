<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$message = '';
$message_type = '';

// Load active edition settings
$current_vol = rjpes_get_setting('current_volume', '20');
$current_issue = rjpes_get_setting('current_issue', '1');

// Current filtered selection (default to active settings)
$sel_vol = sanitize($_GET['volume'] ?? $current_vol);
$sel_iss = sanitize($_GET['issue'] ?? $current_issue);

// Handle compilation action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compile_book'])) {
    $vol = sanitize($_POST['volume']);
    $iss = sanitize($_POST['issue']);
    $journal_ids = $_POST['journal_ids'] ?? [];
    $sequence = $_POST['sequence'] ?? [];
    
    if (empty($journal_ids)) {
        $message = "Please select at least one journal to compile.";
        $message_type = "warning";
    } else {
        require_once __DIR__ . '/../includes/word_helper.php';
        
        // 1. Order selected journal IDs by sequence value
        $ordered_journals = [];
        foreach ($sequence as $jid => $seq_val) {
            if (in_array($jid, $journal_ids)) {
                $ordered_journals[] = [
                    'id' => intval($jid),
                    'seq' => intval($seq_val)
                ];
            }
        }
        
        // Sort ascending by sequence order
        usort($ordered_journals, function($a, $b) {
            return $a['seq'] <=> $b['seq'];
        });
        
        $ordered_ids = array_column($ordered_journals, 'id');
        
        $pdo->beginTransaction();
        try {
            $current_page = 1;
            $pdf_paths = [];
            
            foreach ($ordered_ids as $jid) {
                // Fetch manuscript file path
                $stmt = $pdo->prepare("SELECT manuscript_file, journal_number FROM journals WHERE id = ?");
                $stmt->execute([$jid]);
                $j_info = $stmt->fetch();
                if (!$j_info || empty($j_info['manuscript_file'])) {
                    throw new Exception("Manuscript file path not found for ID $jid.");
                }
                
                $pdf_path = __DIR__ . '/../' . $j_info['manuscript_file'];
                if (!file_exists($pdf_path)) {
                    throw new Exception("PDF file not found on disk at " . $j_info['manuscript_file']);
                }
                
                // Count pages in PDF
                $pages = rjpes_pdf_get_page_count($pdf_path);
                if ($pages <= 0) {
                    throw new Exception("Could not count pages for Journal " . $j_info['journal_number'] . " (might be corrupted).");
                }
                
                $start_page = $current_page;
                $end_page = $current_page + $pages - 1;
                $current_page = $end_page + 1;
                
                // Update start_page and end_page in database
                $upd_stmt = $pdo->prepare("UPDATE journals SET start_page = ?, end_page = ? WHERE id = ?");
                $upd_stmt->execute([$start_page, $end_page, $jid]);
                
                // Regenerate the individual PDF to print the new page numbers
                $regen_success = rjpes_regenerate_journal_pdf($jid);
                if (!$regen_success) {
                    throw new Exception("Failed to regenerate page numbers on individual PDF for " . $j_info['journal_number']);
                }
                
                $pdf_paths[] = $pdf_path;
            }
            
            // Compile into single publication PDF
            $compilation_filename = "book_vol_" . preg_replace('/[^a-zA-Z0-9_-]/', '', $vol) . "_issue_" . preg_replace('/[^a-zA-Z0-9_-]/', '', $iss) . ".pdf";
            
            // Ensure compilation target directory exists
            $comp_dir = __DIR__ . "/../uploads/compilations";
            if (!file_exists($comp_dir)) {
                mkdir($comp_dir, 0777, true);
            }
            
            $compilation_dest = $comp_dir . "/" . $compilation_filename;
            
            $compiled = rjpes_compile_book($pdf_paths, $compilation_dest);
            if (!$compiled) {
                throw new Exception("Failed to merge PDF files into compiled issue book.");
            }
            
            $pdo->commit();
            $message = "Successfully compiled " . count($ordered_ids) . " journals into a single publication book! Page numbers have been updated sequentially from page 1 to " . ($current_page - 1) . ".";
            $message_type = "success";
            
            // Redirect to reload lists
            header("Location: compile.php?volume=" . urlencode($vol) . "&issue=" . urlencode($iss) . "&success=" . urlencode($message));
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Compilation failed: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Check if redirect message is passed
if (isset($_GET['success'])) {
    $message = $_GET['success'];
    $message_type = 'success';
}

// Fetch published journals in the selected Volume & Issue
$published_journals = [];
try {
    $stmt = $pdo->prepare("SELECT j.*, u.fullname as author_name FROM journals j 
                            JOIN users u ON j.author_id = u.id 
                            WHERE j.volume = ? AND j.issue = ? AND j.status = 'published' 
                            ORDER BY j.start_page ASC, j.created_at ASC");
    $stmt->execute([$sel_vol, $sel_iss]);
    $published_journals = $stmt->fetchAll();
} catch (PDOException $e) {
    $message = "Error fetching journals: " . $e->getMessage();
    $message_type = "danger";
}

// Check if a compiled book exists on disk
$comp_file_name = "book_vol_" . preg_replace('/[^a-zA-Z0-9_-]/', '', $sel_vol) . "_issue_" . preg_replace('/[^a-zA-Z0-9_-]/', '', $sel_iss) . ".pdf";
$comp_file_path = "uploads/compilations/" . $comp_file_name;
$book_exists = file_exists(__DIR__ . "/../" . $comp_file_path);
$book_size = $book_exists ? filesize(__DIR__ . "/../" . $comp_file_path) : 0;
$book_date = $book_exists ? filemtime(__DIR__ . "/../" . $comp_file_path) : 0;

$page_title = "Book Compilations";
// Calculate path prefix to support root, subdirectories, and port mapping robustly
$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$depth = substr_count($request_path, '/') - 1;
if ($depth < 0) $depth = 0;
$path_prefix = str_repeat('../', $depth);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <div>
            <h2 style="font-family: var(--font-heading); color: var(--primary-color); margin: 0;">📖 Book Compilations</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 4px; margin-bottom: 0;">Compile selected published articles into a single publication book with sequential page numbers.</p>
        </div>
        <a href="dashboard.php" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; padding: 8px 16px;">
            ← Back to Dashboard
        </a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?>" style="margin-bottom: 1.5rem;">
            <?php if ($message_type == 'success'): ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            <?php else: ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php endif; ?>
            <div><?php echo $message; ?></div>
        </div>
    <?php endif; ?>

    <div class="grid-3" style="display: grid; grid-template-columns: 280px 1fr; gap: 24px; align-items: start;">
        <!-- Filters Sidebar -->
        <div class="card" style="padding: 20px; border-top: 4px solid var(--primary-color); margin-bottom: 0;">
            <h3 style="font-family: var(--font-heading); font-size: 1.15rem; color: var(--primary-color); margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Filter Edition</h3>
            
            <form action="compile.php" method="GET" style="display: flex; flex-direction: column; gap: 15px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="volume" style="font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; display: block;">Volume Number</label>
                    <input type="text" name="volume" id="volume" class="form-control" value="<?php echo htmlspecialchars($sel_vol); ?>" placeholder="e.g. 20" required style="padding: 8px 12px;">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="issue" style="font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; display: block;">Issue Number</label>
                    <input type="text" name="issue" id="issue" class="form-control" value="<?php echo htmlspecialchars($sel_iss); ?>" placeholder="e.g. 1" required style="padding: 8px 12px;">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; font-weight: 700;">Filter Articles</button>
            </form>
        </div>

        <!-- Main Articles Listing & Compilation form -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            
            <!-- Book Download Banner -->
            <?php if ($book_exists): ?>
                <div class="card" style="background: linear-gradient(135deg, #1e3a5f 0%, #1e293b 100%); color: white; border: none; padding: 24px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                    <div>
                        <span style="font-size: 0.72rem; text-transform: uppercase; font-weight: 800; background-color: var(--accent-color); color: #0b2240; padding: 3px 10px; border-radius: 20px; letter-spacing: 0.5px; display: inline-block; margin-bottom: 8px;">Compilation Book Available</span>
                        <h3 style="font-family: var(--font-heading); color: white; margin: 0; font-size: 1.4rem;">Volume <?php echo htmlspecialchars($sel_vol); ?>, Issue <?php echo htmlspecialchars($sel_iss); ?> Book</h3>
                        <p style="color: #cbd5e1; font-size: 0.8rem; margin-top: 6px; margin-bottom: 0;">
                            Compiled on: <strong><?php echo date('d M Y, h:i A', $book_date); ?></strong> | Size: <strong><?php echo number_format($book_size / (1024 * 1024), 2); ?> MB</strong>
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <a href="<?php echo $path_prefix . $comp_file_path; ?>" target="_blank" class="btn btn-primary" style="background-color: var(--accent-color); border: none; color: #0b2240; font-weight: 700; padding: 12px 24px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(212,175,55,0.3); font-size: 0.95rem;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Download Book PDF
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card" style="background: #f8fafc; border: 1px dashed var(--border-color); padding: 24px; text-align: center; border-radius: 12px; margin-bottom: 0;">
                    <div style="font-size: 2.2rem; margin-bottom: 10px;">📚</div>
                    <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin: 0; font-size: 1.15rem;">No Compiled Book Yet</h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 4px; margin-bottom: 0;">Select the published articles below and click compile to generate a single publication PDF for this edition.</p>
                </div>
            <?php endif; ?>

            <!-- Articles Selection Form -->
            <div class="card" style="padding: 24px;">
                <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem; margin-top: 0; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                    <span>Articles in Vol. <?php echo htmlspecialchars($sel_vol); ?>, Issue <?php echo htmlspecialchars($sel_iss); ?></span>
                    <span style="font-size: 0.78rem; font-weight: normal; color: var(--text-muted);">
                        Total Published: <strong><?php echo count($published_journals); ?></strong>
                    </span>
                </h3>

                <?php if (empty($published_journals)): ?>
                    <div style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 12px; opacity: 0.6;">
                            <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M16 16l-3.5-3.5"/><circle cx="11" cy="11" r="4"/>
                        </svg>
                        <p style="font-weight: 600; font-size: 0.95rem; margin-bottom: 4px;">No Published Articles Found</p>
                        <p style="font-size: 0.8rem; margin: 0;">There are no articles with status 'published' under Volume <?php echo htmlspecialchars($sel_vol); ?>, Issue <?php echo htmlspecialchars($sel_iss); ?>.</p>
                    </div>
                <?php else: ?>
                    <form action="compile.php" method="POST" id="compileForm">
                        <input type="hidden" name="volume" value="<?php echo htmlspecialchars($sel_vol); ?>">
                        <input type="hidden" name="issue" value="<?php echo htmlspecialchars($sel_iss); ?>">
                        
                        <div class="table-responsive" style="margin-bottom: 1.5rem;">
                            <table class="table" style="font-size: 0.9rem;">
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align: center;">
                                            <input type="checkbox" id="selectAllCheckboxes" checked style="cursor: pointer; width: 16px; height: 16px;">
                                        </th>
                                        <th style="width: 70px; text-align: center;">Order</th>
                                        <th>Journal ID</th>
                                        <th>Title &amp; Author</th>
                                        <th style="text-align: center;">Page Range</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $default_seq = 1;
                                    foreach ($published_journals as $j): 
                                    ?>
                                        <tr class="article-row">
                                            <td style="text-align: center; vertical-align: middle;">
                                                <input type="checkbox" name="journal_ids[]" value="<?php echo $j['id']; ?>" class="journal-checkbox" checked style="cursor: pointer; width: 16px; height: 16px;">
                                            </td>
                                            <td style="text-align: center; vertical-align: middle;">
                                                <input type="number" name="sequence[<?php echo $j['id']; ?>]" value="<?php echo $default_seq++; ?>" min="1" required style="width: 55px; padding: 4px 6px; border: 1px solid var(--border-color); border-radius: 4px; text-align: center; font-weight: bold;">
                                            </td>
                                            <td style="font-weight: 600; color: var(--primary-color); vertical-align: middle;">
                                                <?php echo sanitize($j['journal_number']); ?>
                                            </td>
                                            <td style="vertical-align: middle;">
                                                <div style="font-weight: 600; color: var(--primary-dark); line-height: 1.35;"><?php echo sanitize($j['title']); ?></div>
                                                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 3px;">Author: <strong><?php echo sanitize($j['author_name']); ?></strong> &bull; Domain: <?php echo sanitize($j['subject_domain']); ?></div>
                                            </td>
                                            <td style="text-align: center; vertical-align: middle; font-weight: bold; color: #ca8a04;">
                                                <?php 
                                                if ($j['start_page'] !== null && $j['end_page'] !== null) {
                                                    echo "pp. " . $j['start_page'] . " - " . $j['end_page'];
                                                } else {
                                                    echo "<span style='font-weight: normal; color: #94a3b8; font-style: italic;'>Not assigned</span>";
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="background-color: #eff6ff; border-left: 4px solid #2563eb; padding: 15px; border-radius: 6px; font-size: 0.85rem; color: #1e3a5f; line-height: 1.45; margin-bottom: 20px;">
                            <strong>ℹ️ Compilation Instructions:</strong><br>
                            - Check the articles you wish to compile in this book. Underscored items will be omitted.<br>
                            - Assign sequential **Order numbers** to determine which article starts first (e.g. 1, 2, 3...).<br>
                            - Clicking compile will open each article PDF, count the pages, write page ranges in the database, redraw footers on the individual PDFs, and output the compiled issue book.
                        </div>

                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" name="compile_book" class="btn btn-dark" style="background-color: #065f46; border: none; color: white; padding: 12px 28px; font-weight: 700; font-size: 0.95rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(6,95,70,0.25);">
                                ⚙️ Compile Selected into Issue Book
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var selectAll = document.getElementById('selectAllCheckboxes');
    var checkboxes = document.querySelectorAll('.journal-checkbox');
    
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
        });
    }
    
    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
            var allChecked = true;
            checkboxes.forEach(function(c) {
                if (!c.checked) allChecked = false;
            });
            if (selectAll) selectAll.checked = allChecked;
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
