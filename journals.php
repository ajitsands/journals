<?php
$page_title = "Journal Archive";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/config/db.php';

// Subject domain list
$domains = [
    "Physical Education",
    "Sports Science",
    "Sports and Society",
    "Kinesiology and Biomechanics",
    "Exercise Physiology",
    "Diet, Nutrition and Drugs",
    "Health, Fitness, Yoga and Wellness",
    "Sports Equipment and Facilities",
    "Sports Training and Competitions"
];

// Read filters & pagination
$selected_domain = sanitize($_GET['domain'] ?? '');
$search_query    = sanitize($_GET['q'] ?? '');
$current_page    = max(1, intval($_GET['page'] ?? 1));
$per_page        = 12; // Articles per page
$offset          = ($current_page - 1) * $per_page;

// Build WHERE clause
$where = "WHERE j.status = 'published'";
$params = [];

if (!empty($selected_domain)) {
    $where .= " AND j.subject_domain = ?";
    $params[] = $selected_domain;
}
if (!empty($search_query)) {
    $where .= " AND (j.title LIKE ? OR j.abstract LIKE ? OR u.fullname LIKE ? OR j.journal_number LIKE ?)";
    $sp = "%" . $search_query . "%";
    $params = array_merge($params, [$sp, $sp, $sp, $sp]);
}

// Total count for pagination
$total_journals = 0;
try {
    $count_sql = "SELECT COUNT(*) FROM journals j JOIN users u ON j.author_id = u.id $where";
    $cstmt = $pdo->prepare($count_sql);
    $cstmt->execute($params);
    $total_journals = (int)$cstmt->fetchColumn();
} catch (PDOException $e) {
    $total_journals = 0;
}

$total_pages = $total_journals > 0 ? (int)ceil($total_journals / $per_page) : 1;
if ($current_page > $total_pages) $current_page = $total_pages;

// Fetch current page's journals — newest first
$published_journals = [];
$error_msg = '';
try {
    $sql = "SELECT j.*, u.fullname as author_name FROM journals j 
            JOIN users u ON j.author_id = u.id 
            $where
            ORDER BY j.published_at DESC, j.id DESC
            LIMIT $per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $published_journals = $stmt->fetchAll();
} catch (PDOException $e) {
    $error_msg = "Error fetching journals: " . $e->getMessage();
}

// Build pagination URL helper
function paginate_url($page, $domain, $q) {
    $args = ['page' => $page];
    if (!empty($domain)) $args['domain'] = $domain;
    if (!empty($q))      $args['q']      = $q;
    return 'journals.php?' . http_build_query($args);
}
?>

<section class="hero" style="padding: 3rem 2rem;">
    <div class="hero-content">
        <h1>RJPES Published Archives</h1>
        <p>Explore double-blind peer-reviewed research papers in physical education and sports sciences — newest first.</p>
    </div>
</section>

<main class="container">

    <!-- Search & Filter Bar -->
    <div class="search-filter-bar">
        <form action="journals.php" method="GET" class="search-form">
            <div class="form-group" style="flex: 2;">
                <label for="q">Search keywords</label>
                <input type="text" name="q" id="q" class="form-control"
                    placeholder="Search by title, author, abstract, or journal number..."
                    value="<?php echo $search_query; ?>">
            </div>
            <div class="form-group">
                <label for="domain">Subject Domain</label>
                <select name="domain" id="domain" class="form-control">
                    <option value="">All Domains</option>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?php echo $d; ?>" <?php echo ($selected_domain === $d) ? 'selected' : ''; ?>>
                            <?php echo $d; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex: 0; min-width: 120px;">
                <button type="submit" class="btn btn-dark btn-search" style="width: 100%; padding: 11px;">Search</button>
            </div>
            <?php if (!empty($selected_domain) || !empty($search_query)): ?>
                <div class="form-group" style="flex: 0; min-width: 100px;">
                    <a href="journals.php" class="btn btn-secondary btn-search"
                        style="width: 100%; padding: 10px; text-align: center; border: 1px solid var(--border-color); color: var(--primary-color);">Clear</a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- Results Summary Bar -->
    <?php if ($total_journals > 0): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 8px;">
            <div style="font-size: 0.85rem; color: var(--text-muted);">
                <?php
                $from = $offset + 1;
                $to   = min($offset + $per_page, $total_journals);
                echo "Showing <strong>$from&ndash;$to</strong> of <strong>$total_journals</strong> published article" . ($total_journals !== 1 ? 's' : '');
                if (!empty($search_query)) echo " &mdash; matching &ldquo;<em>" . htmlspecialchars($search_query) . "</em>&rdquo;";
                if (!empty($selected_domain)) echo " in <em>" . htmlspecialchars($selected_domain) . "</em>";
                ?>
            </div>
            <div style="font-size: 0.82rem; color: var(--text-muted);">
                Page <strong><?php echo $current_page; ?></strong> of <strong><?php echo $total_pages; ?></strong>
            </div>
        </div>
    <?php endif; ?>

    <!-- ===== VOLUME-GROUPED JOURNAL SECTIONS ===== -->
    <style>
    /* Volume section header */
    .vol-section { margin-bottom: 2.5rem; }
    .vol-header {
        display: flex;
        align-items: center;
        gap: 0;
        margin-bottom: 1.25rem;
    }
    .vol-header-badge {
        background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
        color: white;
        font-size: 0.75rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        padding: 8px 22px;
        border-radius: 10px 0 0 10px;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 3px 10px rgba(37,99,235,0.22);
    }
    .vol-header-badge .vol-dot {
        width: 6px; height: 6px;
        border-radius: 50%;
        background: rgba(255,255,255,0.5);
        flex-shrink: 0;
    }
    .vol-header-period {
        background: #e8f0fe;
        color: #1e3a5f;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 8px 18px;
        border-radius: 0;
        letter-spacing: 0.5px;
    }
    .vol-header-count {
        background: #f1f5f9;
        color: #64748b;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 8px 14px;
        border-radius: 0 10px 10px 0;
        letter-spacing: 0.3px;
        border-left: 1px solid #e2e8f0;
    }
    .vol-header-line {
        flex: 1;
        height: 2px;
        background: linear-gradient(to right, #e2e8f0, transparent);
        margin-left: 12px;
    }

    /* Article Card Grid — 4 columns on wide screens */
    .arc-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.1rem;
    }
    @media (max-width: 1199px) {
        .arc-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 899px) {
        .arc-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 599px) {
        .arc-grid { grid-template-columns: 1fr; }
    }
    .arc-card {
        background: white;
        border-radius: 14px;
        border: 1px solid #e8edf5;
        padding: 1.25rem 1.4rem;
        display: flex;
        flex-direction: column;
        gap: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        transition: box-shadow 0.2s, transform 0.2s;
        position: relative;
    }
    .arc-card:hover {
        box-shadow: 0 8px 28px rgba(37,99,235,0.13);
        transform: translateY(-2px);
    }
    .arc-card .arc-num {
        font-size: 0.68rem;
        color: #b45309;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 8px;
    }
    .arc-card .arc-title {
        font-size: 0.97rem;
        font-weight: 700;
        color: var(--primary-color);
        line-height: 1.4;
        margin-bottom: 12px;
        flex: 1;
    }
    .arc-card .arc-title a {
        color: inherit;
        text-decoration: none;
    }
    .arc-card .arc-title a:hover { color: #2563eb; }
    .arc-card .arc-meta {
        display: flex;
        flex-direction: column;
        gap: 5px;
        font-size: 0.78rem;
        color: #64748b;
        margin-bottom: 14px;
        border-top: 1px solid #f1f5f9;
        padding-top: 10px;
    }
    .arc-card .arc-meta span {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .arc-card .arc-btn {
        display: block;
        text-align: center;
        padding: 9px 16px;
        background: linear-gradient(135deg, #065f46 0%, #059669 100%);
        color: white;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        letter-spacing: 0.3px;
        transition: all 0.18s;
        box-shadow: 0 2px 8px rgba(5,150,105,0.22);
    }
    .arc-card .arc-btn:hover {
        background: linear-gradient(135deg, #047857 0%, #10b981 100%);
        box-shadow: 0 4px 14px rgba(5,150,105,0.35);
        transform: translateY(-1px);
    }
    .latest-badge {
        position: absolute;
        top: -8px; right: 14px;
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: white;
        font-size: 0.6rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 3px 10px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(245,158,11,0.4);
    }
    @media (max-width: 640px) {
        .vol-header-count { display: none; }
    }
    /* Widen page for 4-column grid */
    main.container {
        max-width: 1440px !important;
        padding-left: 1.5rem;
        padding-right: 1.5rem;
    }
    </style>

    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-danger"><?php echo $error_msg; ?></div>

    <?php elseif (empty($published_journals)): ?>
        <div class="card" style="text-align: center; padding: 4rem 2rem;">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="margin: 0 auto 1.5rem;">
                <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
                <path d="M16 16l-3.5-3.5"/><circle cx="11" cy="11" r="4"/>
            </svg>
            <h3 style="color: var(--primary-color); margin-bottom: 0.5rem;">No Published Journals Found</h3>
            <p style="color: var(--text-muted);">Try refining your search keyword or selecting a different subject domain.</p>
            <a href="journals.php" class="btn btn-primary" style="margin-top: 1rem; display: inline-block;">View All Archives</a>
        </div>

    <?php else:
        // ── Pre-group articles by Volume + Issue ──────────────────────────────
        $grouped = [];     // [ group_key => [ 'label'=>..., 'period'=>..., 'articles'=>[] ] ]
        $group_order = []; // preserve insertion order

        foreach ($published_journals as $j) {
            if (!empty($j['volume']) && !empty($j['issue'])) {
                $gk    = 'vol' . intval($j['volume']) . 'iss' . intval($j['issue']);
                $label = 'Volume ' . sanitize($j['volume']) . ', Issue ' . sanitize($j['issue']);
            } else {
                $yr = !empty($j['published_at']) ? date('Y', strtotime($j['published_at'])) : date('Y');
                $gk    = 'year' . $yr;
                $label = $yr;
            }
            $period = !empty($j['published_at']) ? date('F Y', strtotime($j['published_at'])) : 'March 2026';

            if (!isset($grouped[$gk])) {
                $grouped[$gk]    = ['label' => $label, 'period' => $period, 'articles' => []];
                $group_order[]   = $gk;
            }
            $grouped[$gk]['articles'][] = $j;
        }

        $is_very_first = true; // track the globally first card for the "Latest" badge
    ?>

    <?php foreach ($group_order as $gk):
        $grp = $grouped[$gk];
        $count = count($grp['articles']);
    ?>
        <div class="vol-section">
            <!-- Volume Header — full width above the cards -->
            <div class="vol-header">
                <div class="vol-header-badge">
                    <span class="vol-dot"></span>
                    <?php echo htmlspecialchars($grp['label']); ?>
                </div>
                <div class="vol-header-period"><?php echo htmlspecialchars($grp['period']); ?></div>
                <div class="vol-header-count"><?php echo $count; ?> article<?php echo $count !== 1 ? 's' : ''; ?></div>
                <div class="vol-header-line"></div>
            </div>

            <!-- Card Grid for this Volume -->
            <div class="arc-grid">
                <?php foreach ($grp['articles'] as $journal):
                    $is_latest = $is_very_first && $current_page === 1;
                    $is_very_first = false;
                    $period_j = !empty($journal['published_at']) ? date('F Y', strtotime($journal['published_at'])) : 'March 2026';
                ?>
                    <div class="arc-card">
                        <?php if ($is_latest): ?>
                            <span class="latest-badge">⭐ Latest</span>
                        <?php endif; ?>

                        <div class="arc-num">
                            Journal No: <?php echo sanitize($journal['journal_number']); ?> &nbsp;|&nbsp; ISSN: 0975-4687
                        </div>

                        <div class="arc-title">
                            <a href="/journal-detail.php?id=<?php echo $journal['id']; ?>">
                                <?php echo sanitize($journal['title']); ?>
                            </a>
                        </div>

                        <div class="arc-meta">
                            <span>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <?php echo sanitize($journal['author_name']); ?>
                            </span>
                            <span>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                <?php echo sanitize($journal['subject_domain']); ?>
                            </span>
                            <span>
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <?php echo $period_j; ?>
                            </span>
                        </div>

                        <a href="/journal-detail.php?id=<?php echo $journal['id']; ?>" class="arc-btn">View Article →</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>



    <!-- ===== PAGINATION ===== -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Archive pagination" style="margin: 2.5rem 0 3rem; display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 6px;">
            <?php
            $qs_base = [];
            if (!empty($selected_domain)) $qs_base['domain'] = $selected_domain;
            if (!empty($search_query))    $qs_base['q']      = $search_query;

            $pag_style_base = "display:inline-flex; align-items:center; justify-content:center; min-width:38px; height:38px; padding:0 12px; border-radius:8px; font-size:0.85rem; font-weight:600; cursor:pointer; text-decoration:none; border:1px solid var(--border-color); color:var(--primary-color); background:white; transition:all 0.15s;";
            $pag_style_active = "display:inline-flex; align-items:center; justify-content:center; min-width:38px; height:38px; padding:0 12px; border-radius:8px; font-size:0.85rem; font-weight:700; cursor:default; text-decoration:none; border:none; color:white; background:linear-gradient(135deg,var(--primary-color),#2563eb); box-shadow:0 2px 8px rgba(37,99,235,0.3);";
            $pag_style_disabled = "display:inline-flex; align-items:center; justify-content:center; min-width:38px; height:38px; padding:0 10px; border-radius:8px; font-size:0.85rem; font-weight:600; text-decoration:none; border:1px solid var(--border-color); color:#cbd5e1; background:#f8fafc; pointer-events:none;";

            // ← Previous
            if ($current_page > 1):
                $prev_url = paginate_url($current_page - 1, $selected_domain, $search_query);
            ?>
                <a href="<?php echo $prev_url; ?>" style="<?php echo $pag_style_base; ?>" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='white'" aria-label="Previous">
                    ← Prev
                </a>
            <?php else: ?>
                <span style="<?php echo $pag_style_disabled; ?>" aria-disabled="true">← Prev</span>
            <?php endif; ?>

            <?php
            // Smart page number window
            $window = 2; // pages each side of current
            $start  = max(1, $current_page - $window);
            $end    = min($total_pages, $current_page + $window);

            // Always show first page
            if ($start > 1):
            ?>
                <a href="<?php echo paginate_url(1, $selected_domain, $search_query); ?>" style="<?php echo $pag_style_base; ?>" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='white'">1</a>
                <?php if ($start > 2): ?>
                    <span style="<?php echo $pag_style_disabled; ?> padding:0 4px; min-width:auto;">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
                <?php if ($p === $current_page): ?>
                    <span style="<?php echo $pag_style_active; ?>" aria-current="page"><?php echo $p; ?></span>
                <?php else: ?>
                    <a href="<?php echo paginate_url($p, $selected_domain, $search_query); ?>"
                        style="<?php echo $pag_style_base; ?>"
                        onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='white'"><?php echo $p; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php
            // Always show last page
            if ($end < $total_pages):
                if ($end < $total_pages - 1): ?>
                    <span style="<?php echo $pag_style_disabled; ?> padding:0 4px; min-width:auto;">…</span>
                <?php endif; ?>
                <a href="<?php echo paginate_url($total_pages, $selected_domain, $search_query); ?>"
                    style="<?php echo $pag_style_base; ?>"
                    onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='white'"><?php echo $total_pages; ?></a>
            <?php endif; ?>

            <!-- Next → -->
            <?php if ($current_page < $total_pages):
                $next_url = paginate_url($current_page + 1, $selected_domain, $search_query);
            ?>
                <a href="<?php echo $next_url; ?>" style="<?php echo $pag_style_base; ?>" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='white'" aria-label="Next">
                    Next →
                </a>
            <?php else: ?>
                <span style="<?php echo $pag_style_disabled; ?>" aria-disabled="true">Next →</span>
            <?php endif; ?>
        </nav>

        <!-- Jump-to-page (useful when 100s of pages) -->
        <?php if ($total_pages > 10): ?>
            <div style="text-align: center; margin-bottom: 2.5rem;">
                <form action="journals.php" method="GET" style="display: inline-flex; align-items: center; gap: 8px;">
                    <?php if (!empty($selected_domain)): ?><input type="hidden" name="domain" value="<?php echo htmlspecialchars($selected_domain); ?>"><?php endif; ?>
                    <?php if (!empty($search_query)):    ?><input type="hidden" name="q"      value="<?php echo htmlspecialchars($search_query); ?>"><?php endif; ?>
                    <label style="font-size: 0.82rem; color: var(--text-muted);">Jump to page:</label>
                    <input type="number" name="page" min="1" max="<?php echo $total_pages; ?>"
                        value="<?php echo $current_page; ?>"
                        style="width: 68px; padding: 6px 8px; border-radius: 7px; border: 1px solid var(--border-color); font-size: 0.85rem; text-align: center;">
                    <button type="submit" style="padding: 6px 14px; border-radius: 7px; background: var(--primary-color); color: white; border: none; font-size: 0.82rem; font-weight: 600; cursor: pointer;">Go</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
