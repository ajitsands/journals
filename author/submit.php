<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('author');

$user = get_logged_in_user();
$author_id = $user['id'];

$message = "";
$message_type = "";

$is_edit = false;
$edit_journal = null;

// Check if we are uploading a revision
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM journals WHERE id = ? AND author_id = ? AND status = 'revisions_required'");
        $stmt->execute([$edit_id, $author_id]);
        $edit_journal = $stmt->fetch();
        if ($edit_journal) {
            $is_edit = true;
        }
    } catch (PDOException $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = "danger";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = sanitize($_POST['title']);
    $abstract = sanitize($_POST['abstract']);
    $content = $_POST['content'] ?? '';
    $subject_domain = sanitize($_POST['subject_domain']);
    
    // File Upload handling
    $upload_ok = true;
    $file_path = "";
    
    if (isset($_FILES['manuscript']) && $_FILES['manuscript']['error'] === UPLOAD_ERR_OK) {
        $file_name = $_FILES['manuscript']['name'];
        $file_tmp = $_FILES['manuscript']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_exts = ['pdf', 'doc', 'docx', 'txt'];
        if (!in_array($file_ext, $allowed_exts)) {
            $message = "Invalid file type. Only PDF, DOC, DOCX, and TXT files are allowed.";
            $message_type = "danger";
            $upload_ok = false;
        } else {
            // Create uploads directory if not exists
            $upload_dir = __DIR__ . '/../uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Unique file name
            $new_file_name = 'manuscript_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            $dest_path = $upload_dir . $new_file_name;
            
            if (move_uploaded_file($file_tmp, $dest_path)) {
                $file_path = 'uploads/' . $new_file_name;
            } else {
                $message = "Failed to upload manuscript file. Check server permissions.";
                $message_type = "danger";
                $upload_ok = false;
            }
        }
    } else {
        // If editing and no new file was uploaded, keep existing file
        if ($is_edit) {
            $file_path = $edit_journal['manuscript_file'];
        } else {
            $message = "Please upload your manuscript file.";
            $message_type = "danger";
            $upload_ok = false;
        }
    }

    if ($upload_ok) {
        if ($is_edit) {
            // Update submission and set status to under_review (sending it back to the same verifier)
            try {
                $author_notes = sanitize($_POST['author_notes'] ?? '');

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE journals SET title = ?, abstract = ?, content = ?, subject_domain = ?, manuscript_file = ?, status = 'under_review', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$title, $abstract, $content, $subject_domain, $file_path, $edit_journal['id']]);
                
                // Keep the assignment and set status back to 'assigned' so the verifier can review again
                $reset_assignment = $pdo->prepare("UPDATE reviewer_assignments SET status = 'assigned' WHERE journal_id = ?");
                $reset_assignment->execute([$edit_journal['id']]);

                // Record new document version
                $ver_stmt = $pdo->prepare("SELECT MAX(version_number) as max_ver FROM journal_versions WHERE journal_id = ?");
                $ver_stmt->execute([$edit_journal['id']]);
                $ver_row = $ver_stmt->fetch();
                $next_version = ($ver_row['max_ver'] ?? 0) + 1;

                $ins_ver = $pdo->prepare("INSERT INTO journal_versions (journal_id, version_number, manuscript_file, author_notes) VALUES (?, ?, ?, ?)");
                $ins_ver->execute([$edit_journal['id'], $next_version, $file_path, $author_notes ?: null]);

                $pdo->commit();
                
                // Send email notifications
                try {
                    require_once __DIR__ . '/../includes/mail_helper.php';
                    $journal_data = [
                        'journal_number' => $edit_journal['journal_number'],
                        'title' => $title
                    ];
                    rjpes_mail_revision($journal_data, $user, $next_version, $author_notes);
                    
                    // Fetch verifier details
                    $ver_qry = $pdo->prepare("SELECT u.fullname, u.email FROM reviewer_assignments ra JOIN users u ON ra.reviewer_id = u.id WHERE ra.journal_id = ?");
                    $ver_qry->execute([$edit_journal['id']]);
                    $verifier = $ver_qry->fetch();
                    if ($verifier) {
                        rjpes_mail_revision_to_verifier($journal_data, $verifier, $next_version, $author_notes);
                    }
                } catch (Exception $e) {
                    // Ignore email failures to not block user workflow
                }
                
                header("Location: /author/dashboard.php?success=revision_submitted");
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Database update failed: " . $e->getMessage();
                $message_type = "danger";
            }
        } else {
            // Create new submission
            try {
                // Generate unique Journal Number
                $is_unique = false;
                $journal_number = "";
                while (!$is_unique) {
                    $random_num = rand(1000, 9999);
                    $journal_number = "RJPES-2026-" . $random_num;
                    
                    $check_stmt = $pdo->prepare("SELECT id FROM journals WHERE journal_number = ?");
                    $check_stmt->execute([$journal_number]);
                    if (!$check_stmt->fetch()) {
                        $is_unique = true;
                    }
                }

                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT INTO journals (author_id, title, abstract, content, subject_domain, manuscript_file, journal_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted_waiting_review')");
                $stmt->execute([$author_id, $title, $abstract, $content, $subject_domain, $file_path, $journal_number]);
                $new_journal_id = $pdo->lastInsertId();

                // Record as Version 1
                $ins_ver = $pdo->prepare("INSERT INTO journal_versions (journal_id, version_number, manuscript_file, author_notes) VALUES (?, 1, ?, ?)");
                $ins_ver->execute([$new_journal_id, $file_path, 'Initial submission']);

                $pdo->commit();
                
                // Send email notification
                try {
                    require_once __DIR__ . '/../includes/mail_helper.php';
                    $journal_data = [
                        'journal_number' => $journal_number,
                        'title' => $title,
                        'subject_domain' => $subject_domain,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    rjpes_mail_submission($journal_data, $user);
                } catch (Exception $e) {
                    // Ignore email failures to not block user workflow
                }
                
                header("Location: /author/dashboard.php?success=submitted");
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = "Database insertion failed: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <!-- Main Content -->
    <main class="main-content" style="max-width: 1200px; width: 100%; margin: 0 auto;">
        <h2 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 2rem;">
            <?php echo $is_edit ? "Upload Revised Manuscript (" . sanitize($edit_journal['journal_number']) . ")" : "Submit New Research Paper"; ?>
        </h2>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <?php
        try {
            $fee_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'min_processing_fee'");
            $fee_row = $fee_stmt->fetch();
            $min_processing_fee = floatval($fee_row['setting_value'] ?? 1000);
        } catch (PDOException $e) {
            $min_processing_fee = 1000;
        }
        ?>
        <div style="background-color: #fffbeb; border-left: 4px solid var(--accent-color); padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85rem; color: #78350f;">
            <strong>ℹ️ Publication Processing Fee Notice:</strong> The minimum processing and publication fee for accepted manuscripts is <strong>₹<?php echo number_format($min_processing_fee, 2); ?></strong>. The final fee is determined by the Admin upon acceptance and may vary based on the journal process workload.
        </div>

        <?php if ($is_edit): ?>
            <div class="grid-2">
                <!-- Left Column: Revision Form -->
                <div>
        <?php endif; ?>

        <div class="card">
            <form action="submit.php<?php echo $is_edit ? '?edit_id=' . $edit_journal['id'] : ''; ?>" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">Research Paper Title</label>
                    <input type="text" name="title" id="title" class="form-control" placeholder="Enter complete title of your research article" value="<?php echo $is_edit ? sanitize($edit_journal['title']) : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="subject_domain">Subject Domain</label>
                    <select name="subject_domain" id="subject_domain" class="form-control" required>
                        <option value="">Select Domain...</option>
                        <option value="Physical Education" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Physical Education') ? 'selected' : ''; ?>>Physical Education</option>
                        <option value="Sports Science" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Sports Science') ? 'selected' : ''; ?>>Sports Science</option>
                        <option value="Yoga" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Yoga') ? 'selected' : ''; ?>>Yoga</option>
                        <option value="Group Dynamics" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Group Dynamics') ? 'selected' : ''; ?>>Group Dynamics</option>
                        <option value="Health Education" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Health Education') ? 'selected' : ''; ?>>Health Education</option>
                        <option value="Nutrition" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Nutrition') ? 'selected' : ''; ?>>Nutrition</option>
                        <option value="Physical Fitness" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Physical Fitness') ? 'selected' : ''; ?>>Physical Fitness</option>
                        <option value="Sports and Allied Subjects" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Sports and Allied Subjects') ? 'selected' : ''; ?>>Sports and Allied Subjects</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="abstract">Abstract</label>
                    <textarea name="abstract" id="abstract" class="form-control" rows="8" placeholder="Provide a concise abstract of your paper (around 150-250 words) outlining background, methods, results, and conclusion." required><?php echo $is_edit ? sanitize($edit_journal['abstract']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="content">Full Text Content (Optional - for direct HTML/PDF rendering)</label>
                    <textarea name="content" id="content" class="form-control" rows="12" placeholder="You can paste the text content of your full paper here for direct display in the web portal."><?php echo $is_edit ? sanitize($edit_journal['content']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="manuscript">Manuscript File (PDF, DOCX, DOC, or TXT)</label>
                    <input type="file" name="manuscript" id="manuscript" class="form-control" <?php echo $is_edit ? '' : 'required'; ?>>
                    <small style="display: block; margin-top: 5px; color: #7f8c8d; font-size: 0.82rem;">
                        <strong>⚠️ Format Requirement:</strong> The manuscript must comply with the <strong>IMRAD structure</strong> and <strong>APA 7th edition format</strong>.
                    </small>
                    <?php if ($is_edit): ?>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 5px;">
                            Current file: <a href="<?php echo $path_prefix . $edit_journal['manuscript_file']; ?>" target="_blank"><?php echo basename($edit_journal['manuscript_file']); ?></a>. Leave blank to keep the same file.
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($is_edit): ?>
                <div class="form-group">
                    <label for="author_notes">Summary of Changes Made <span style="color: var(--text-muted); font-weight: 400;">(Optional — helps the verifier understand what was revised)</span></label>
                    <textarea name="author_notes" id="author_notes" class="form-control" rows="4" placeholder="e.g., Revised the methodology section, updated references, corrected statistical analysis in Table 3..."></textarea>
                </div>
                <?php endif; ?>

                <div style="margin-top: 2rem; display: flex; gap: 15px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1; padding: 12px;">
                        <?php echo $is_edit ? "Submit Revision" : "Submit Manuscript"; ?>
                    </button>
                    <a href="<?php echo $path_prefix; ?>author/dashboard.php" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 12px;">Cancel</a>
                </div>
            </form>
        </div>

        <?php if ($is_edit): ?>
                </div>
                <!-- Right Column: Timeline of the Communication -->
                <div>
                    <div class="card" style="padding: 1.5rem; position: sticky; top: 100px;">
                        <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                            💬 Review &amp; Communication History
                        </h3>
                        <?php 
                        $timeline_journal_id = $edit_journal['id'];
                        $timeline_role = 'author';
                        require_once __DIR__ . '/../includes/document_timeline.php';
                        ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/40.0.0/classic/ckeditor.js"></script>
<script>
    let editorInstance;
    ClassicEditor
        .create(document.querySelector('#content'), {
            toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'undo', 'redo']
        })
        .then(editor => {
            editorInstance = editor;
        })
        .catch(error => {
            console.error(error);
        });

    const textarea = document.querySelector('#content');
    if (textarea && textarea.form) {
        textarea.form.addEventListener('submit', function() {
            if (editorInstance) {
                textarea.value = editorInstance.getData();
            }
        });
    }
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
