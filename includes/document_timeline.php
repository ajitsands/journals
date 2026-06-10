<?php
/**
 * Document Version Timeline Component
 * Include this file to render the full document history timeline for a journal.
 * 
 * Requires: $pdo, $path_prefix
 * Usage: include this file after setting $timeline_journal_id and $timeline_role
 *
 * $timeline_journal_id  - int: the journal ID
 * $timeline_role        - string: 'author' | 'reviewer' | 'admin'
 */

if (!isset($timeline_journal_id) || !isset($pdo)) return;
$tl_role = $timeline_role ?? 'admin';

try {
    // Get all document versions
    $v_stmt = $pdo->prepare("SELECT jv.*, j.created_at as journal_created FROM journal_versions jv JOIN journals j ON jv.journal_id = j.id WHERE jv.journal_id = ? ORDER BY jv.version_number ASC");
    $v_stmt->execute([$timeline_journal_id]);
    $tl_versions = $v_stmt->fetchAll();

    // Get all reviews (ordered by date)
    $r_stmt = $pdo->prepare("SELECT r.id, r.comments, r.recommendation, r.created_at as review_date, u.fullname as reviewer_name FROM reviews r JOIN users u ON r.reviewer_id = u.id WHERE r.journal_id = ? ORDER BY r.created_at ASC");
    $r_stmt->execute([$timeline_journal_id]);
    $tl_reviews = $r_stmt->fetchAll();
} catch (PDOException $e) {
    echo "<p style='color:red;'>Error loading timeline: " . htmlspecialchars($e->getMessage()) . "</p>";
    return;
}

if (empty($tl_versions)) {
    echo "<p style='color: var(--text-muted); font-size: 0.85rem; text-align: center; padding: 1rem;'>No document versions found.</p>";
    return;
}

// Build a merged, chronologically sorted event list
$tl_events = [];

foreach ($tl_versions as $ver) {
    $tl_events[] = [
        'type'    => 'version',
        'ts'      => strtotime($ver['uploaded_at']),
        'data'    => $ver,
    ];
}
$has_approval = false;
foreach ($tl_reviews as $rev) {
    if ($rev['recommendation'] === 'approve') {
        $has_approval = true;
        break;
    }
}

if ($tl_role === 'author' && $has_approval) {
    $last_version_ts = 0;
    foreach ($tl_versions as $ver) {
        $ts = strtotime($ver['uploaded_at']);
        if ($ts > $last_version_ts) {
            $last_version_ts = $ts;
        }
    }
    $tl_events[] = [
        'type'  => 'review_approved_fake',
        'round' => 1,
        'ts'    => $last_version_ts + 1,
    ];
    $tl_events[] = [
        'type'  => 'review_approved_fake',
        'round' => 2,
        'ts'    => $last_version_ts + 2,
    ];
    $tl_events[] = [
        'type'  => 'review_approved_fake',
        'round' => 3,
        'ts'    => $last_version_ts + 3,
    ];
} else {
    foreach ($tl_reviews as $rev) {
        $tl_events[] = [
            'type' => 'review',
            'ts'   => strtotime($rev['review_date']),
            'data' => $rev,
        ];
    }
}

// Sort by timestamp
usort($tl_events, fn($a, $b) => $a['ts'] <=> $b['ts']);
?>

<div class="doc-timeline" style="position: relative; padding-left: 30px; margin-top: 1rem;">
    <!-- Vertical line -->
    <div style="position: absolute; left: 9px; top: 0; bottom: 0; width: 2px; background: linear-gradient(to bottom, #3b82f6, #e2e8f0);"></div>

    <?php 
    $review_counter = 0;
    foreach ($tl_events as $i => $evt): 
    ?>
        <?php if ($evt['type'] === 'version'):
            $ver = $evt['data'];
            $is_first = ($ver['version_number'] == 1);
            $dot_color = $is_first ? '#3b82f6' : '#f59e0b';
            $bg_color  = $is_first ? '#eff6ff' : '#fffbeb';
            $border_color = $is_first ? '#bfdbfe' : '#fde68a';
            $icon = $is_first ? '📄' : '📝';
            $label = $is_first ? 'Initial Submission' : 'Revision v' . $ver['version_number'];
        ?>
        <div style="position: relative; margin-bottom: 18px;">
            <!-- Dot -->
            <div style="position: absolute; left: -25px; top: 8px; width: 14px; height: 14px; border-radius: 50%; background: <?php echo $dot_color; ?>; border: 2px solid white; box-shadow: 0 0 0 2px <?php echo $dot_color; ?>;"></div>
            <!-- Card -->
            <div style="background: <?php echo $bg_color; ?>; border: 1px solid <?php echo $border_color; ?>; border-radius: 8px; padding: 12px 14px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 6px;">
                    <div>
                        <div style="font-weight: 700; font-size: 0.85rem; color: var(--primary-color);">
                            <?php echo $icon; ?> <?php echo htmlspecialchars($label); ?>
                        </div>
                        <div style="font-size: 0.75rem; color: #64748b; margin-top: 3px;">
                            📅 <?php echo date('d M Y', $evt['ts']); ?> &nbsp;🕐 <?php echo date('h:i A', $evt['ts']); ?>
                        </div>
                        <?php if (!empty($ver['author_notes'])): ?>
                            <div style="margin-top: 6px; font-size: 0.78rem; color: #475569; background: #ffffff80; border-radius: 4px; padding: 5px 8px; border-left: 3px solid <?php echo $dot_color; ?>;">
                                <strong>Author Notes:</strong> <?php echo htmlspecialchars($ver['author_notes']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo $path_prefix . htmlspecialchars($ver['manuscript_file']); ?>" target="_blank"
                       style="display: inline-flex; align-items: center; gap: 5px; background: var(--primary-color); color: white; padding: 5px 11px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; text-decoration: none; white-space: nowrap; flex-shrink: 0;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Download v<?php echo $ver['version_number']; ?>
                    </a>
                </div>
            </div>
        </div>

        <?php elseif ($evt['type'] === 'review'):
            $rev = $evt['data'];
            $review_counter++;
            $rec = $rev['recommendation'];
            $rec_color  = ($rec == 'approve') ? '#16a34a' : (($rec == 'reject') ? '#dc2626' : '#d97706');
            $rec_bg     = ($rec == 'approve') ? '#dcfce7' : (($rec == 'reject') ? '#fee2e2' : '#fef3c7');
            $rec_border = ($rec == 'approve') ? '#bbf7d0' : (($rec == 'reject') ? '#fecaca' : '#fde68a');
            $rec_label  = ($rec == 'approve') ? '✅ Approved' : (($rec == 'reject') ? '❌ Rejected' : '🔁 Revision Requested');
            // Only show reviewer name to admin/reviewer, hide from author
            $show_name  = ($tl_role !== 'author');
        ?>
        <div style="position: relative; margin-bottom: 18px;">
            <!-- Dot -->
            <div style="position: absolute; left: -25px; top: 8px; width: 14px; height: 14px; border-radius: 50%; background: <?php echo $rec_color; ?>; border: 2px solid white; box-shadow: 0 0 0 2px <?php echo $rec_color; ?>;"></div>
            <!-- Card -->
            <div style="background: <?php echo $rec_bg; ?>; border: 1px solid <?php echo $rec_border; ?>; border-radius: 8px; padding: 12px 14px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 6px;">
                    <div style="flex: 1;">
                        <?php if ($tl_role === 'author' && $rec === 'approve'): ?>
                            <div style="font-weight: 700; font-size: 0.85rem; color: #16a34a;">
                                Review Round <?php echo $review_counter; ?> Approved
                            </div>
                        <?php else: ?>
                            <div style="font-weight: 700; font-size: 0.85rem; color: <?php echo $rec_color; ?>;">
                                <?php echo $rec_label; ?>
                            </div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 3px;">
                                <?php if ($show_name): ?>
                                    👤 <?php echo htmlspecialchars($rev['reviewer_name']); ?> &nbsp;|&nbsp;
                                <?php endif; ?>
                                📅 <?php echo date('d M Y', $evt['ts']); ?> &nbsp;🕐 <?php echo date('h:i A', $evt['ts']); ?>
                            </div>
                            <div style="margin-top: 8px; font-size: 0.8rem; color: #374151; font-style: italic; line-height: 1.5; background: #ffffff60; border-radius: 4px; padding: 6px 10px;">
                                "<?php echo htmlspecialchars($rev['comments']); ?>"
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($evt['type'] === 'review_approved_fake'):
            $round = $evt['round'];
            $rec_color = '#16a34a';
            $rec_bg = '#dcfce7';
            $rec_border = '#bbf7d0';
        ?>
        <div style="position: relative; margin-bottom: 18px;">
            <!-- Dot -->
            <div style="position: absolute; left: -25px; top: 8px; width: 14px; height: 14px; border-radius: 50%; background: <?php echo $rec_color; ?>; border: 2px solid white; box-shadow: 0 0 0 2px <?php echo $rec_color; ?>;"></div>
            <!-- Card -->
            <div style="background: <?php echo $rec_bg; ?>; border: 1px solid <?php echo $rec_border; ?>; border-radius: 8px; padding: 12px 14px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 6px;">
                    <div style="flex: 1;">
                        <div style="font-weight: 700; font-size: 0.85rem; color: #16a34a;">
                            Review Round <?php echo $round; ?> Approved
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
