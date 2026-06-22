<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header("Location: /journals.php");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT j.*, u.fullname as author_name, u.email as author_email FROM journals j 
                            JOIN users u ON j.author_id = u.id 
                            WHERE j.id = ?");
    $stmt->execute([$id]);
    $journal = $stmt->fetch();
    
    // Only allow viewing if it is published, OR if the logged-in user is the author, reviewer assigned, or admin
    if (!$journal) {
        header("Location: /journals.php");
        exit;
    }
    
    // Fetch all authors from journal_authors table
    $authors_stmt = $pdo->prepare("SELECT * FROM journal_authors WHERE journal_id = ? ORDER BY order_num ASC");
    $authors_stmt->execute([$id]);
    $all_authors = $authors_stmt->fetchAll();
    
    // Fallback if empty (for backwards compatibility)
    if (empty($all_authors)) {
        $all_authors = [[
            'name' => $journal['author_name'],
            'photo_path' => $journal['author_photo'] ?? null,
            'order_num' => 1
        ]];
    }
    
    $is_authorized_viewer = false;
    if ($journal['status'] === 'published') {
        $is_authorized_viewer = true;
    } elseif (is_logged_in()) {
        $user = get_logged_in_user();
        if ($user['role'] === 'admin' || $user['id'] == $journal['author_id']) {
            $is_authorized_viewer = true;
        } else {
            // Check if reviewer is assigned to this journal
            $rev_stmt = $pdo->prepare("SELECT id FROM reviewer_assignments WHERE journal_id = ? AND reviewer_id = ?");
            $rev_stmt->execute([$id, $user['id']]);
            if ($rev_stmt->fetch()) {
                $is_authorized_viewer = true;
            }
        }
    }
    
    if (!$is_authorized_viewer) {
        header("Location: /login.php?error=unauthorized");
        exit;
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$page_title = $journal['title'];
require_once __DIR__ . '/includes/header.php';
?>

<main class="container">
    <div style="margin-bottom: 1.5rem;">
        <a href="/journals.php" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 500; font-size: 0.9rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Archives
        </a>
    </div>

    <!-- Article Header Card -->
    <div class="detail-header">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap; margin-bottom: 1.5rem;">
            <div>
                <span class="badge badge-<?php echo $journal['status']; ?>" style="margin-bottom: 10px;">
                    Status: <?php echo str_replace('_', ' ', $journal['status']); ?>
                </span>
                <div style="font-size: 0.85rem; font-weight: 600; color: var(--accent-color); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                    Journal ID: <?php echo sanitize($journal['journal_number']); ?> | ISSN: 0975-4687<?php if ($journal['start_page'] !== null && $journal['end_page'] !== null): ?> | Page: pp. <?php echo $journal['start_page']; ?>-<?php echo $journal['end_page']; ?><?php endif; ?>
                </div>
            </div>
            
            <?php if ($journal['status'] === 'published'): ?>
                <?php 
                $show_full_dropdown = false;
                if (is_logged_in()) {
                    $user = get_logged_in_user();
                    if ($user['role'] === 'admin' || $user['id'] == $journal['author_id']) {
                        $show_full_dropdown = true;
                    }
                }
                ?>
                <?php if ($show_full_dropdown): ?>
                    <!-- Download Split Dropdown -->
                    <div style="position: relative; display: inline-flex; vertical-align: middle;" id="dlDropWrap">
                        <button onclick="toggleDlDrop()" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: var(--primary-color); color: white; border: none; border-radius: 8px 0 0 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; font-family: var(--font-body);">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Download
                        </button>
                        <button onclick="toggleDlDrop()" style="display: inline-flex; align-items: center; padding: 10px 12px; background: #16365f; color: white; border: none; border-left: 1px solid rgba(255,255,255,0.2); border-radius: 0 8px 8px 0; cursor: pointer;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <!-- Dropdown Menu -->
                        <div id="dlDropMenu" style="display:none; position: absolute; right: 0; top: calc(100% + 6px); background: white; border: 1px solid var(--border-color); border-radius: 10px; box-shadow: var(--shadow-lg); min-width: 240px; z-index: 999; overflow: hidden;">
                            <a href="/download.php?id=<?php echo $journal['id']; ?>&type=acceptance"
                               style="display: flex; align-items: center; gap: 12px; padding: 14px 18px; text-decoration: none; color: var(--text-color); font-size: 0.88rem; font-weight: 500; border-bottom: 1px solid var(--border-color); transition: background 0.15s;"
                               onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                <span style="width:36px; height:36px; background: #fffbeb; border-radius: 8px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">📨</span>
                                <div>
                                    <div style="font-weight: 600; color: var(--primary-color);">Acceptance Letter</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">Official RJPES acceptance letter</div>
                                </div>
                            </a>
                            <a href="/download.php?id=<?php echo $journal['id']; ?>&type=article"
                               style="display: flex; align-items: center; gap: 12px; padding: 14px 18px; text-decoration: none; color: var(--text-color); font-size: 0.88rem; font-weight: 500; border-bottom: 1px solid var(--border-color); transition: background 0.15s;"
                               onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                <span style="width:36px; height:36px; background: #eff6ff; border-radius: 8px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">📄</span>
                                <div>
                                    <div style="font-weight: 600; color: var(--primary-color);">Article / Manuscript</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">Latest submitted manuscript file</div>
                                </div>
                            </a>
                            <?php 
                            $show_invoice = false;
                            if (!empty($journal['bill_number']) && is_logged_in()) {
                                $user = get_logged_in_user();
                                if ($user['role'] === 'admin' || $user['id'] == $journal['author_id']) {
                                    $show_invoice = true;
                                }
                            }
                            if ($show_invoice): 
                            ?>
                            <a href="/download.php?id=<?php echo $journal['id']; ?>&type=invoice"
                               style="display: flex; align-items: center; gap: 12px; padding: 14px 18px; text-decoration: none; color: var(--text-color); font-size: 0.88rem; font-weight: 500; transition: background 0.15s;"
                               onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                <span style="width:36px; height:36px; background: #ecfdf5; border-radius: 8px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">🧾</span>
                                <div>
                                    <div style="font-weight: 600; color: #047857;">GST Tax Invoice</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">Download GST invoice bill</div>
                                </div>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <script>
                    function toggleDlDrop() {
                        var m = document.getElementById('dlDropMenu');
                        m.style.display = m.style.display === 'none' ? 'block' : 'none';
                    }
                    // Close on outside click
                    document.addEventListener('click', function(e) {
                        if (!document.getElementById('dlDropWrap').contains(e.target)) {
                            document.getElementById('dlDropMenu').style.display = 'none';
                        }
                    });
                    </script>
                <?php else: ?>
                    <!-- Single Download Button for General Public -->
                    <a href="/download.php?id=<?php echo $journal['id']; ?>&type=article" 
                       style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: var(--primary-color); color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem; text-decoration: none; cursor: pointer; font-family: var(--font-body);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download Article
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <div style="background-color: var(--warning-color); color: #856404; padding: 6px 14px; border-radius: 6px; font-size: 0.85rem; font-weight: 600;">
                    Preview Mode (Not Published)
                </div>
            <?php endif; ?>
        </div>

        <h1 style="font-family: var(--font-heading); font-size: 2.2rem; color: var(--primary-color); line-height: 1.25; margin-bottom: 1.5rem;">
            <?php echo sanitize($journal['title']); ?>
        </h1>

        <div style="border-top: 1px solid var(--border-color); padding-top: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                <?php foreach ($all_authors as $index => $auth): ?>
                    <div style="display: flex; align-items: center; gap: 12px; background: #f8fafc; padding: 10px 16px; border-radius: 10px; border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);">
                        <?php if (!empty($auth['photo_path']) && file_exists(__DIR__ . '/' . $auth['photo_path'])): ?>
                            <img src="<?php echo $path_prefix . htmlspecialchars($auth['photo_path']); ?>" alt="<?php echo htmlspecialchars($auth['name']); ?>" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent-color);">
                        <?php else: ?>
                            <div style="width: 44px; height: 44px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; color: #64748b; font-weight: bold; border: 2px solid #cbd5e1; font-size: 1.1rem;">
                                <?php echo strtoupper(substr($auth['name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <p style="font-size: 0.95rem; font-weight: 600; color: var(--primary-color); margin: 0;"><?php echo htmlspecialchars($auth['name']); ?></p>
                            <p style="font-size: 0.75rem; color: var(--text-muted); margin: 0; font-weight: 500;">
                                <?php echo $index === 0 ? 'Corresponding Author' : 'Co-Author'; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div style="text-align: right;">
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 4px;">Subject Domain</p>
                <span style="font-weight: 600; font-size: 0.95rem; color: var(--primary-color); background-color: #f1f5f9; padding: 6px 12px; border-radius: 6px; display: inline-block;">
                    <?php echo sanitize($journal['subject_domain']); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Article Content Card -->
    <div class="detail-body">
        <h2 style="font-family: var(--font-heading); font-size: 1.5rem; color: var(--primary-color); margin-bottom: 1rem; border-bottom: 2px solid var(--border-color); padding-bottom: 8px;">
            Abstract
        </h2>
        <div style="font-size: 1.1rem; line-height: 1.8; color: #334155; text-align: justify; margin-bottom: 2rem; font-style: italic; background-color: #f8fafc; padding: 2rem; border-radius: 8px; border-left: 4px solid var(--primary-color);">
            <?php echo nl2br(sanitize($journal['abstract'])); ?>
        </div>

        <?php if (!empty($journal['content'])): ?>
            <h2 style="font-family: var(--font-heading); font-size: 1.5rem; color: var(--primary-color); margin-bottom: 1rem; border-bottom: 2px solid var(--border-color); padding-bottom: 8px; margin-top: 3rem;">
                Full Text / Manuscript Content
            </h2>
            <div class="rich-content" style="font-size: 1rem; line-height: 1.7; color: #1e293b; text-align: justify;">
                <?php echo strip_tags($journal['content'], '<a><p><strong><em><u><h1><h2><h3><h4><h5><h6><ul><ol><li><blockquote><br>'); ?>
            </div>
        <?php endif; ?>

        <?php if ($journal['status'] === 'published' && !empty($journal['volume'])): ?>
            <div style="margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color); font-size: 0.85rem; color: var(--text-muted); display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                <p>Published in: <strong>RJPES Volume <?php echo sanitize($journal['volume']); ?>, Issue <?php echo sanitize($journal['issue']); ?> (<?php echo date('F Y', strtotime($journal['published_at'])); ?>)</strong><?php if ($journal['start_page'] !== null && $journal['end_page'] !== null): ?> &bull; <strong>pp. <?php echo $journal['start_page']; ?>-<?php echo $journal['end_page']; ?></strong><?php endif; ?></p>
                <p>Published Date: <strong><?php echo date('d M Y', strtotime($journal['published_at'])); ?></strong></p>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
