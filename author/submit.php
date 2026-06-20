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
    
    // Pre-generate unique Journal Number for new submissions
    $journal_number = "";
    if (!$is_edit) {
        $is_unique = false;
        while (!$is_unique) {
            $random_num = rand(1000, 9999);
            $journal_number = "RJPES-2026-" . $random_num;
            
            $check_stmt = $pdo->prepare("SELECT id FROM journals WHERE journal_number = ?");
            $check_stmt->execute([$journal_number]);
            if (!$check_stmt->fetch()) {
                $is_unique = true;
            }
        }
    } else {
        $journal_number = $edit_journal['journal_number'];
    }

    // File Upload handling
    $upload_ok = true;
    $file_path = "";
    $new_file_uploaded = false;
    
    if (isset($_FILES['manuscript']) && $_FILES['manuscript']['error'] === UPLOAD_ERR_OK) {
        $new_file_uploaded = true;
        $file_name = $_FILES['manuscript']['name'];
        $file_tmp = $_FILES['manuscript']['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $allowed_exts = ['doc', 'docx'];
        if (!in_array($file_ext, $allowed_exts)) {
            $message = "Invalid file type. Only DOC and DOCX files are allowed.";
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

    // Photo Upload handling
    $author_photo_path = null;
    $new_photo_uploaded = false;
    
    if ($upload_ok) {
        if (isset($_FILES['author_photo']) && $_FILES['author_photo']['error'] === UPLOAD_ERR_OK) {
            $photo_name = $_FILES['author_photo']['name'];
            $photo_tmp = $_FILES['author_photo']['tmp_name'];
            $photo_ext = strtolower(pathinfo($photo_name, PATHINFO_EXTENSION));
            
            $allowed_photo_exts = ['jpg', 'jpeg'];
            if (!in_array($photo_ext, $allowed_photo_exts)) {
                $message = "Invalid photo type. Only JPG and JPEG files are allowed.";
                $message_type = "danger";
                $upload_ok = false;
            } else {
                $upload_dir = __DIR__ . '/../uploads/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_photo_name = 'author_photo_' . time() . '_' . rand(1000, 9999) . '.' . $photo_ext;
                $photo_dest_path = $upload_dir . $new_photo_name;
                
                if (move_uploaded_file($photo_tmp, $photo_dest_path)) {
                    $author_photo_path = 'uploads/' . $new_photo_name;
                    $new_photo_uploaded = true;
                } else {
                    $message = "Failed to upload author photo. Check server permissions.";
                    $message_type = "danger";
                    $upload_ok = false;
                }
            }
        } else {
            if ($is_edit) {
                $author_photo_path = $edit_journal['author_photo'] ?? null;
            } else {
                $author_photo_path = null;
            }
        }
    }

    if ($upload_ok) {
        $regenerate_pdf = false;
        
        // Ensure word_helper.php functions are available
        require_once __DIR__ . '/../includes/word_helper.php';
        
        if ($new_file_uploaded) {
            $regenerate_pdf = true;
            // Convert uploaded doc/docx to PDF
            $extracted_content = '';
            if ($file_ext === 'docx') {
                $extracted_content = rjpes_read_docx($dest_path);
            } elseif ($file_ext === 'doc') {
                $extracted_content = rjpes_read_doc($dest_path);
            }
    
            if ($extracted_content === false || empty($extracted_content)) {
                $message = "Failed to extract text from the uploaded document. Please check the file format and try again.";
                $message_type = "danger";
                $upload_ok = false;
                @unlink($dest_path);
                if ($new_photo_uploaded && !empty($author_photo_path)) {
                    @unlink(__DIR__ . '/../' . $author_photo_path);
                }
            } else {
                $content = $extracted_content; // Update content to be the extracted text for portal preview
            }
        } elseif ($is_edit) {
            // Always regenerate PDF on edit to reflect any metadata/co-author/photo updates
            $regenerate_pdf = true;
        }
        
        if ($upload_ok && ($regenerate_pdf || !$is_edit)) {
            require_once __DIR__ . '/../includes/pdf_helper.php';
            
            // Determine volume and issue for header/footer
            $latest_vol = '20';
            $latest_iss = '1';
            try {
                $set_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('current_volume', 'current_issue')");
                while ($row = $set_stmt->fetch()) {
                    if ($row['setting_key'] === 'current_volume') $latest_vol = $row['setting_value'];
                    if ($row['setting_key'] === 'current_issue') $latest_iss = $row['setting_value'];
                }
            } catch (PDOException $e) {}
            
            // Build temporary authors list to pass to cover PDF generator
            $temp_authors = [];
            // Add primary author
            $temp_authors[] = [
                'name' => $user['fullname'],
                'photo_path' => $author_photo_path,
                'order_num' => 1
            ];
            // Add submitted co-authors
            if (isset($_POST['co_author_name']) && is_array($_POST['co_author_name'])) {
                foreach ($_POST['co_author_name'] as $idx => $name) {
                    $name = sanitize($name);
                    if (empty($name)) continue;
                    
                    // We must find their photo_path (either new upload or existing preserved)
                    $co_id = isset($_POST['co_author_id'][$idx]) ? intval($_POST['co_author_id'][$idx]) : 0;
                    $co_photo_path = null;
                    
                    if ($is_edit && $co_id > 0) {
                        // Get existing photo path
                        $ec_stmt = $pdo->prepare("SELECT photo_path FROM journal_authors WHERE id = ?");
                        $ec_stmt->execute([$co_id]);
                        $co_photo_path = $ec_stmt->fetchColumn();
                    }
                    
                    $file_input_name = "co_author_photo_" . $idx;
                    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
                        $photo_name = $_FILES[$file_input_name]['name'];
                        $photo_tmp = $_FILES[$file_input_name]['tmp_name'];
                        $photo_ext = strtolower(pathinfo($photo_name, PATHINFO_EXTENSION));
                        
                        $allowed_photo_exts = ['jpg', 'jpeg'];
                        if (in_array($photo_ext, $allowed_photo_exts)) {
                            $new_photo_name = 'co_author_photo_' . time() . '_' . rand(1000, 9999) . '.' . $photo_ext;
                            $photo_dest_path = __DIR__ . '/../uploads/' . $new_photo_name;
                            if (move_uploaded_file($photo_tmp, $photo_dest_path)) {
                                if ($is_edit && !empty($co_photo_path)) {
                                    @unlink(__DIR__ . '/../' . $co_photo_path);
                                }
                                $co_photo_path = 'uploads/' . $new_photo_name;
                            }
                        }
                    }
                    
                    $temp_authors[] = [
                        'name' => $name,
                        'photo_path' => $co_photo_path,
                        'order_num' => count($temp_authors) + 1
                    ];
                }
            }
    
            // Create the data array for PDF generator
            $journal_pdf_data = [
                'title' => $title,
                'author_name' => $user['fullname'],
                'subject_domain' => $subject_domain,
                'journal_number' => $journal_number,
                'abstract' => $abstract,
                'content' => '', // MUST BE EMPTY for cover page only
                'volume' => $latest_vol,
                'issue' => $latest_iss,
                'published_at' => date('Y-m-d H:i:s'),
                'author_photo' => $author_photo_path,
                'authors' => $temp_authors
            ];
    
            // 1. Generate Cover Page PDF
            $pdf_generator = new RJPES_PDF();
            $cover_bytes = $pdf_generator->generate($journal_pdf_data);
            
            $cover_pdf_filename = 'cover_temp_' . time() . '_' . rand(1000, 9999) . '.pdf';
            $cover_pdf_path = $upload_dir . $cover_pdf_filename;
            file_put_contents($cover_pdf_path, $cover_bytes);
            
            $body_pdf_path = '';
            
            // 2. Generate Body PDF (convert DOCX to PDF or extract from old merged PDF)
            if ($new_file_uploaded) {
                // Convert uploaded word file to PDF
                $body_pdf_filename = 'body_temp_' . time() . '_' . rand(1000, 9999) . '.pdf';
                $body_pdf_path = $upload_dir . $body_pdf_filename;
                $conv_success = rjpes_convert_docx_to_pdf($dest_path, $body_pdf_path);
                
                // Delete the uploaded Word file
                @unlink($dest_path);
                
                if (!$conv_success) {
                    $message = "Failed to convert Word document to PDF using MS Word on server.";
                    $message_type = "danger";
                    $upload_ok = false;
                    @unlink($cover_pdf_path);
                    if ($new_photo_uploaded && !empty($author_photo_path)) {
                        @unlink(__DIR__ . '/../' . $author_photo_path);
                    }
                }
            } elseif ($is_edit && !empty($edit_journal['manuscript_file'])) {
                // Extract body PDF pages from the existing merged PDF
                $existing_pdf_path = __DIR__ . '/../' . $edit_journal['manuscript_file'];
                if (file_exists($existing_pdf_path)) {
                    $body_pdf_filename = 'body_temp_' . time() . '_' . rand(1000, 9999) . '.pdf';
                    $body_pdf_path = $upload_dir . $body_pdf_filename;
                    $ext_success = rjpes_pdf_extract_body($existing_pdf_path, $body_pdf_path);
                    
                    if (!$ext_success) {
                        // Fallback: copy the existing PDF as body if extraction fails
                        copy($existing_pdf_path, $body_pdf_path);
                    }
                }
            }
            
            // 3. Merge Cover PDF and Body PDF
            if ($upload_ok) {
                $final_pdf_filename = 'manuscript_' . time() . '_' . rand(1000, 9999) . '.pdf';
                $final_pdf_dest_path = $upload_dir . $final_pdf_filename;
                
                $merge_success = false;
                if (!empty($body_pdf_path) && file_exists($body_pdf_path)) {
                    $merge_success = rjpes_pdf_merge($cover_pdf_path, $body_pdf_path, $final_pdf_dest_path, $journal_number, $latest_vol, $latest_iss, date('F Y'));
                } else {
                    $merge_success = copy($cover_pdf_path, $final_pdf_dest_path);
                }
                
                // Clean up temporary PDFs
                @unlink($cover_pdf_path);
                if (!empty($body_pdf_path)) {
                    @unlink($body_pdf_path);
                }
                
                if ($merge_success) {
                    // Delete old PDF file if it exists and we are replacing it
                    if ($is_edit && !empty($edit_journal['manuscript_file'])) {
                        @unlink(__DIR__ . '/../' . $edit_journal['manuscript_file']);
                    }
                    $file_path = 'uploads/' . $final_pdf_filename;
                } else {
                    $message = "Failed to compile Cover Page and Manuscript PDF.";
                    $message_type = "danger";
                    $upload_ok = false;
                    if ($new_photo_uploaded && !empty($author_photo_path)) {
                        @unlink(__DIR__ . '/../' . $author_photo_path);
                    }
                }
            }
        }
    }

    if ($upload_ok) {
        if ($is_edit) {
            // Update submission and set status to under_review (sending it back to the same verifier)
            try {
                $author_notes = sanitize($_POST['author_notes'] ?? '');

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE journals SET title = ?, abstract = ?, content = ?, subject_domain = ?, manuscript_file = ?, author_photo = ?, status = 'under_review', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$title, $abstract, $content, $subject_domain, $file_path, $author_photo_path, $edit_journal['id']]);
                
                // Save/update authors list in journal_authors
                $existing_cos = [];
                $cos_stmt = $pdo->prepare("SELECT * FROM journal_authors WHERE journal_id = ? ORDER BY order_num ASC");
                $cos_stmt->execute([$edit_journal['id']]);
                $existing_cos = $cos_stmt->fetchAll();
                
                // Primary author update
                $primary_author_id = null;
                foreach ($existing_cos as $ec) {
                    if ($ec['order_num'] == 1) {
                        $primary_author_id = $ec['id'];
                        break;
                    }
                }
                if ($primary_author_id) {
                    $upd_pri = $pdo->prepare("UPDATE journal_authors SET name = ?, photo_path = ? WHERE id = ?");
                    $upd_pri->execute([$user['fullname'], $author_photo_path, $primary_author_id]);
                } else {
                    $ins_pri = $pdo->prepare("INSERT INTO journal_authors (journal_id, name, photo_path, order_num) VALUES (?, ?, ?, 1)");
                    $ins_pri->execute([$edit_journal['id'], $user['fullname'], $author_photo_path]);
                }
                
                // Co-authors saving
                $submitted_co_ids = [];
                $order_num = 2;
                if (isset($_POST['co_author_name']) && is_array($_POST['co_author_name'])) {
                    foreach ($_POST['co_author_name'] as $idx => $name) {
                        $name = sanitize($name);
                        if (empty($name)) continue;
                        
                        $co_id = isset($_POST['co_author_id'][$idx]) ? intval($_POST['co_author_id'][$idx]) : 0;
                        
                        // Find matching photo path from temp_authors list
                        $co_photo_path = null;
                        foreach ($temp_authors as $ta) {
                            if ($ta['order_num'] == $order_num) {
                                $co_photo_path = $ta['photo_path'];
                                break;
                            }
                        }
                        
                        if ($co_id > 0) {
                            $upd_co = $pdo->prepare("UPDATE journal_authors SET name = ?, photo_path = ?, order_num = ? WHERE id = ?");
                            $upd_co->execute([$name, $co_photo_path, $order_num, $co_id]);
                            $submitted_co_ids[] = $co_id;
                        } else {
                            $ins_co = $pdo->prepare("INSERT INTO journal_authors (journal_id, name, photo_path, order_num) VALUES (?, ?, ?, ?)");
                            $ins_co->execute([$edit_journal['id'], $name, $co_photo_path, $order_num]);
                            $submitted_co_ids[] = $pdo->lastInsertId();
                        }
                        $order_num++;
                    }
                }
                
                // Delete removed co-authors
                foreach ($existing_cos as $ec) {
                    if ($ec['order_num'] == 1) continue;
                    if (!in_array($ec['id'], $submitted_co_ids)) {
                        $del_stmt = $pdo->prepare("DELETE FROM journal_authors WHERE id = ?");
                        $del_stmt->execute([$ec['id']]);
                        if (!empty($ec['photo_path'])) {
                            @unlink(__DIR__ . '/../' . $ec['photo_path']);
                        }
                    }
                }
                
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
                
                // Delete old photo if a new one is uploaded and DB updates successfully
                if ($new_photo_uploaded && !empty($edit_journal['author_photo'])) {
                    @unlink(__DIR__ . '/../' . $edit_journal['author_photo']);
                }
                
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
                if ($new_photo_uploaded && !empty($author_photo_path)) {
                    @unlink(__DIR__ . '/../' . $author_photo_path);
                }
            }
        } else {
            // Create new submission
            try {
                // Journal number is already pre-generated at the top
                $is_unique = true;

                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT INTO journals (author_id, title, abstract, content, subject_domain, manuscript_file, author_photo, journal_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted_waiting_review')");
                $stmt->execute([$author_id, $title, $abstract, $content, $subject_domain, $file_path, $author_photo_path, $journal_number]);
                $new_journal_id = $pdo->lastInsertId();
                
                // Save primary author in journal_authors
                $ins_pri = $pdo->prepare("INSERT INTO journal_authors (journal_id, name, photo_path, order_num) VALUES (?, ?, ?, 1)");
                $ins_pri->execute([$new_journal_id, $user['fullname'], $author_photo_path]);
                
                // Save co-authors in journal_authors
                $order_num = 2;
                if (isset($_POST['co_author_name']) && is_array($_POST['co_author_name'])) {
                    foreach ($_POST['co_author_name'] as $idx => $name) {
                        $name = sanitize($name);
                        if (empty($name)) continue;
                        
                        $co_photo_path = null;
                        foreach ($temp_authors as $ta) {
                            if ($ta['order_num'] == $order_num) {
                                $co_photo_path = $ta['photo_path'];
                                break;
                            }
                        }
                        
                        $ins_co = $pdo->prepare("INSERT INTO journal_authors (journal_id, name, photo_path, order_num) VALUES (?, ?, ?, ?)");
                        $ins_co->execute([$new_journal_id, $name, $co_photo_path, $order_num]);
                        $order_num++;
                    }
                }

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
                if ($new_photo_uploaded && !empty($author_photo_path)) {
                    @unlink(__DIR__ . '/../' . $author_photo_path);
                }
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
                        <option value="Sports and Society" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Sports and Society') ? 'selected' : ''; ?>>Sports and Society</option>
                        <option value="Kinesiology and Biomechanics" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Kinesiology and Biomechanics') ? 'selected' : ''; ?>>Kinesiology and Biomechanics</option>
                        <option value="Exercise Physiology" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Exercise Physiology') ? 'selected' : ''; ?>>Exercise Physiology</option>
                        <option value="Diet, Nutrition and Drugs" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Diet, Nutrition and Drugs') ? 'selected' : ''; ?>>Diet, Nutrition and Drugs</option>
                        <option value="Health, Fitness, Yoga and Wellness" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Health, Fitness, Yoga and Wellness') ? 'selected' : ''; ?>>Health, Fitness, Yoga and Wellness</option>
                        <option value="Sports Equipment and Facilities" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Sports Equipment and Facilities') ? 'selected' : ''; ?>>Sports Equipment and Facilities</option>
                        <option value="Sports Training and Competitions" <?php echo ($is_edit && $edit_journal['subject_domain'] == 'Sports Training and Competitions') ? 'selected' : ''; ?>>Sports Training and Competitions</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="abstract">Abstract</label>
                    <textarea name="abstract" id="abstract" class="form-control" rows="8" placeholder="Provide a concise abstract of your paper (around 150-250 words) outlining background, methods, results, and conclusion."><?php echo $is_edit ? sanitize($edit_journal['abstract']) : ''; ?></textarea>
                </div>

                <div class="form-group" style="display: none;">
                    <label for="content">Full Text Content (Optional - for direct HTML/PDF rendering)</label>
                    <textarea name="content" id="content" class="form-control" rows="12" placeholder="You can paste the text content of your full paper here for direct display in the web portal."><?php echo $is_edit ? sanitize($edit_journal['content']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="manuscript">Manuscript File (DOCX or DOC)</label>
                    <input type="file" name="manuscript" id="manuscript" accept=".doc,.docx" class="form-control" <?php echo $is_edit ? '' : 'required'; ?>>
                    <small style="display: block; margin-top: 5px; color: #7f8c8d; font-size: 0.82rem;">
                        <strong>⚠️ Format Requirement:</strong> The manuscript must be uploaded as a DOCX or DOC file. It will be converted into a PDF automatically, containing standard header, footer, and page numbers.
                    </small>
                    <?php if ($is_edit): ?>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 5px;">
                            Current file: <a href="<?php echo $path_prefix . $edit_journal['manuscript_file']; ?>" target="_blank"><?php echo basename($edit_journal['manuscript_file']); ?></a>. Leave blank to keep the same file.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 1.5rem;">
                    <label for="author_photo">Author's Photo (JPG or JPEG only)</label>
                    <input type="file" name="author_photo" id="author_photo" accept=".jpg,.jpeg" class="form-control">
                    <small style="display: block; margin-top: 5px; color: #7f8c8d; font-size: 0.82rem;">
                        <strong>⚠️ Format Requirement:</strong> The photo must be a JPG or JPEG file. This image will be printed on the cover page of the generated PDF.
                    </small>
                    <?php if ($is_edit && !empty($edit_journal['author_photo'])): ?>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 8px; display: flex; align-items: center; gap: 10px;">
                            <span>Current photo:</span>
                            <img src="<?php echo $path_prefix . $edit_journal['author_photo']; ?>" alt="Author Photo" style="max-width: 60px; max-height: 60px; border-radius: 4px; border: 1px solid var(--border-color); object-fit: cover;">
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Co-Authors Dynamic Field Section -->
                <div style="margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                    <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                        <span>👥 Co-Authors (Optional)</span>
                        <button type="button" class="btn btn-secondary" id="add-co-author-btn" style="padding: 6px 12px; font-size: 0.82rem; border: 1px solid var(--border-color); color: var(--primary-color); cursor: pointer; display: flex; align-items: center; gap: 5px; font-weight: 600; background: #f1f5f9; border-radius: 6px;">
                            ➕ Add Co-Author
                        </button>
                    </h3>
                    <div id="co-authors-container" style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 1.5rem;">
                        <!-- JS will inject co-author rows here -->
                    </div>
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
    let abstractEditorInstance;
    ClassicEditor
        .create(document.querySelector('#abstract'), {
            toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'undo', 'redo']
        })
        .then(editor => {
            abstractEditorInstance = editor;
        })
        .catch(error => {
            console.error(error);
        });

    const abstractTextarea = document.querySelector('#abstract');
    if (abstractTextarea && abstractTextarea.form) {
        abstractTextarea.form.addEventListener('submit', function(e) {
            if (abstractEditorInstance) {
                const data = abstractEditorInstance.getData();
                abstractTextarea.value = data;
                
                // Strip HTML tags to check if there is actual text content entered
                const plainText = data.replace(/<[^>]*>/g, '').trim();
                if (plainText === '') {
                    alert("Please fill in the Abstract field.");
                    e.preventDefault();
                }
            }
        });
    }
</script>

<script>
    let coAuthorIndex = 0;
    function addCoAuthorRow(id = '', name = '', photoUrl = '') {
        const container = document.getElementById('co-authors-container');
        const row = document.createElement('div');
        row.className = 'co-author-row';
        row.style = 'display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; align-items: end; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid var(--border-color);';
        
        let photoPreviewHtml = '';
        if (photoUrl) {
            photoPreviewHtml = `<div style="margin-top: 8px;"><img src="${photoUrl}" style="max-width: 50px; max-height: 50px; border-radius: 4px; object-fit: cover; border: 1px solid var(--border-color);"></div>`;
        }
        
        row.innerHTML = `
            <input type="hidden" name="co_author_id[${coAuthorIndex}]" value="${id}">
            <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; display: block;">Co-Author Name</label>
                <input type="text" name="co_author_name[${coAuthorIndex}]" class="form-control" value="${name}" required placeholder="Enter co-author name" style="padding: 8px 12px; background: white;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label style="font-size: 0.85rem; font-weight: 600; margin-bottom: 6px; display: block;">Photo (JPG/JPEG only)</label>
                <input type="file" name="co_author_photo_${coAuthorIndex}" accept=".jpg,.jpeg" class="form-control" style="padding: 5px 10px; background: white;">
                ${photoPreviewHtml}
            </div>
            <div style="margin-bottom: 3px;">
                <button type="button" class="btn btn-danger remove-co-author" style="padding: 9px 14px; background-color: #ef4444; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.85rem;">Remove</button>
            </div>
        `;
        
        container.appendChild(row);
        
        row.querySelector('.remove-co-author').addEventListener('click', () => {
            row.remove();
        });
        
        coAuthorIndex++;
    }

    document.getElementById('add-co-author-btn').addEventListener('click', () => {
        addCoAuthorRow();
    });
</script>

<?php if ($is_edit): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php
        $stmt = $pdo->prepare("SELECT * FROM journal_authors WHERE journal_id = ? AND order_num > 1 ORDER BY order_num ASC");
        $stmt->execute([$edit_journal['id']]);
        $co_authors = $stmt->fetchAll();
        foreach ($co_authors as $ca) {
            $photo_url = $ca['photo_path'] ? $path_prefix . $ca['photo_path'] : '';
            echo "addCoAuthorRow(" . intval($ca['id']) . ", '" . addslashes($ca['name']) . "', '" . addslashes($photo_url) . "');\n";
        }
        ?>
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
