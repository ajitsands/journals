<?php
$page_title = "Research Journal on Physical Education and Sports (RJPES)";
require_once __DIR__ . '/includes/header.php';

// Fetch admin-configured current edition from system_settings
$latest_vol = '20';
$latest_iss = '1';
$latest_date = 'MARCH 2026';
$latest_month_year = 'March 2026';
$min_processing_fee = 1000;
$min_process_duration = '15 Working Days';
$home_gst_mode = 'exclude'; // 'include' | 'exclude'
$home_gst_pct  = 18;

try {
    $set_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('current_volume', 'current_issue', 'current_edition_date', 'min_processing_fee', 'min_process_duration', 'gst_mode', 'gst_percentage')");
    $edition_settings = [];
    while ($row = $set_stmt->fetch()) {
        $edition_settings[$row['setting_key']] = $row['setting_value'];
    }

    if (!empty($edition_settings['current_volume']) && !empty($edition_settings['current_issue'])) {
        $latest_vol         = $edition_settings['current_volume'];
        $latest_iss         = $edition_settings['current_issue'];
        $ed_date            = !empty($edition_settings['current_edition_date']) ? $edition_settings['current_edition_date'] : date('Y-m-d');
        $latest_date        = strtoupper(date('F Y', strtotime($ed_date)));
        $latest_month_year  = date('F Y', strtotime($ed_date));
    }
    if (isset($edition_settings['min_processing_fee'])) {
        $min_processing_fee = floatval($edition_settings['min_processing_fee']);
    }
    if (!empty($edition_settings['min_process_duration'])) {
        $min_process_duration = $edition_settings['min_process_duration'];
    }
    if (!empty($edition_settings['gst_mode']) && in_array($edition_settings['gst_mode'], ['include', 'exclude'])) {
        $home_gst_mode = $edition_settings['gst_mode'];
    }
    if (isset($edition_settings['gst_percentage'])) {
        $home_gst_pct = floatval($edition_settings['gst_percentage']);
    }
} catch (PDOException $e) {
    // Fallback to defaults already set above
}
?>

<!-- Hero Header -->
<section class="hero">
    <div class="hero-content">
        <h1>Research Journal on Physical Education & Sports (RJPES)</h1>
        <p style="font-size: 1.2rem; font-weight: 400; line-height: 1.6; max-width: 700px; margin: 0 auto 2.5rem;">
            Voice of Sports - Association of College Teachers in Physical Education (ACTPE), University of Calicut
            <br>
            <span style="display: inline-block; margin-top: 12px; font-weight: 700; color: var(--accent-color); font-family: monospace; letter-spacing: 0.5px;">ISSN 0975-4687</span>
            <br>
            <span style="display: inline-block; margin-top: 6px; font-size: 1rem; font-weight: 500; background: rgba(255,255,255,0.08); padding: 4px 16px; border-radius: 30px; border: 1px solid rgba(255,255,255,0.12);">
                ✨ Peer-Reviewed Journal, UGC Guidelines Followed
            </span>
        </p>
        <div class="hero-actions">
            <?php if (is_logged_in()): ?>
                <a href="/author/submit.php" class="btn btn-primary">Submit Manuscript</a>
            <?php else: ?>
                <a href="/register.php" class="btn btn-primary">Register to Submit</a>
                <a href="/login.php" class="btn btn-secondary">Login to Portal</a>
            <?php endif; ?>
            <a href="/journals.php" class="btn btn-secondary">Browse Archive</a>
        </div>
    </div>
</section>

<!-- Main Page Layout -->
<style>
.home-main-wrap { position: relative; }
.home-bg-art { position: absolute; inset: 0; pointer-events: none; z-index: 0; }
.home-main-wrap > .container { position: relative; z-index: 1; }
@keyframes bgDrift  { 0%,100%{transform:translate(0,0) rotate(0deg);} 50%{transform:translate(10px,-10px) rotate(1.5deg);} }
@keyframes bgDrift2 { 0%,100%{transform:translate(0,0) rotate(0deg);} 50%{transform:translate(-8px,12px) rotate(-1.5deg);} }
@keyframes bgPulse  { 0%,100%{opacity:0.065;} 50%{opacity:0.11;} }
.home-bg-art .l1 { animation: bgDrift 18s ease-in-out infinite; }
.home-bg-art .l2 { animation: bgDrift2 22s ease-in-out infinite; }
.home-bg-art .l3 { animation: bgPulse 12s ease-in-out infinite; }
</style>

<div class="home-main-wrap">
<div class="home-bg-art" aria-hidden="true">

  <!-- Concentric circles + crosshairs (top-left area) -->
  <svg class="l1" style="position:absolute;top:20px;left:20px;width:600px;height:600px;opacity:0.06;" viewBox="0 0 700 700" fill="none">
    <circle cx="350" cy="350" r="320" stroke="#0b2240" stroke-width="2"/>
    <circle cx="350" cy="350" r="255" stroke="#0b2240" stroke-width="1.5"/>
    <circle cx="350" cy="350" r="185" stroke="#0b2240" stroke-width="1.2"/>
    <circle cx="350" cy="350" r="108" stroke="#0b2240" stroke-width="1"/>
    <line x1="30" y1="350" x2="670" y2="350" stroke="#0b2240" stroke-width="1"/>
    <line x1="350" y1="30" x2="350" y2="670" stroke="#0b2240" stroke-width="1"/>
    <line x1="123" y1="123" x2="577" y2="577" stroke="#0b2240" stroke-width="0.8"/>
    <line x1="577" y1="123" x2="123" y2="577" stroke="#0b2240" stroke-width="0.8"/>
    <path d="M350 30 A320 320 0 0 1 670 350" stroke="#d4af37" stroke-width="3" fill="none"/>
    <path d="M670 350 A320 320 0 0 1 350 670" stroke="#d4af37" stroke-width="2" fill="none"/>
  </svg>

  <!-- Dot grid (top-right corner) -->
  <svg class="l3" style="position:absolute;top:0;right:0;width:450px;height:550px;opacity:0.075;" viewBox="0 0 400 500">
    <defs><pattern id="dg2" x="0" y="0" width="24" height="24" patternUnits="userSpaceOnUse"><circle cx="3" cy="3" r="2.2" fill="#0b2240"/></pattern></defs>
    <rect width="400" height="500" fill="url(#dg2)"/>
  </svg>

  <!-- Nested squares + diagonal rays (bottom-left) -->
  <svg class="l2" style="position:absolute;bottom:40px;left:30px;width:480px;height:400px;opacity:0.055;" viewBox="0 0 500 400" fill="none">
    <rect x="10"  y="10"  width="200" height="200" stroke="#0b2240" stroke-width="1.8" rx="3"/>
    <rect x="32"  y="32"  width="156" height="156" stroke="#0b2240" stroke-width="1.2" rx="2"/>
    <rect x="58"  y="58"  width="104" height="104" stroke="#d4af37" stroke-width="2"   rx="2"/>
    <line x1="10" y1="110" x2="210" y2="110" stroke="#0b2240" stroke-width="1"/>
    <line x1="110" y1="10" x2="110" y2="210" stroke="#0b2240" stroke-width="1"/>
    <line x1="250" y1="50"  x2="490" y2="290" stroke="#0b2240" stroke-width="1.2"/>
    <line x1="270" y1="30"  x2="490" y2="250" stroke="#0b2240" stroke-width="0.9"/>
    <line x1="230" y1="70"  x2="490" y2="330" stroke="#0b2240" stroke-width="0.9"/>
    <line x1="210" y1="90"  x2="490" y2="370" stroke="#0b2240" stroke-width="0.7"/>
    <circle cx="370" cy="80"  r="32" stroke="#d4af37" stroke-width="2"/>
    <circle cx="420" cy="160" r="20" stroke="#0b2240" stroke-width="1.5"/>
    <circle cx="310" cy="200" r="13" stroke="#0b2240" stroke-width="1.2"/>
  </svg>

  <!-- Wave lines + diamond + vertical columns (right side) -->
  <svg class="l1" style="position:absolute;top:20%;right:30px;width:280px;height:500px;opacity:0.05;" viewBox="0 0 320 500" fill="none">
    <path d="M10 50 Q80 10 160 50 Q240 90 320 50"  stroke="#0b2240" stroke-width="2"/>
    <path d="M10 90 Q80 50 160 90 Q240 130 320 90"  stroke="#0b2240" stroke-width="1.5"/>
    <path d="M10 130 Q80 90 160 130 Q240 170 320 130" stroke="#0b2240" stroke-width="1.2"/>
    <path d="M10 170 Q80 130 160 170 Q240 210 320 170" stroke="#0b2240" stroke-width="1"/>
    <path d="M10 210 Q80 170 160 210 Q240 250 320 210" stroke="#d4af37" stroke-width="2"/>
    <line x1="40"  y1="240" x2="40"  y2="490" stroke="#0b2240" stroke-width="1"/>
    <line x1="80"  y1="255" x2="80"  y2="490" stroke="#0b2240" stroke-width="0.8"/>
    <line x1="120" y1="245" x2="120" y2="490" stroke="#d4af37" stroke-width="1.8"/>
    <line x1="160" y1="250" x2="160" y2="490" stroke="#0b2240" stroke-width="0.8"/>
    <line x1="200" y1="255" x2="200" y2="490" stroke="#0b2240" stroke-width="1"/>
    <line x1="240" y1="248" x2="240" y2="490" stroke="#0b2240" stroke-width="0.8"/>
    <line x1="280" y1="258" x2="280" y2="490" stroke="#0b2240" stroke-width="0.8"/>
    <polygon points="160,330 205,365 160,400 115,365" stroke="#d4af37" stroke-width="2.5" fill="none"/>
    <polygon points="160,345 192,365 160,385 128,365" stroke="#0b2240" stroke-width="1.5" fill="none"/>
  </svg>

  <!-- Arching banner curves (top-centre) -->
  <svg class="l3" style="position:absolute;top:0;left:50%;transform:translateX(-50%);width:900px;height:200px;opacity:0.045;" viewBox="0 0 900 200" fill="none">
    <path d="M0 200 Q450 -80 900 200"  stroke="#0b2240" stroke-width="2.5"/>
    <path d="M40 200 Q450 -40 860 200" stroke="#0b2240" stroke-width="1.8"/>
    <path d="M80 200 Q450 0 820 200"   stroke="#d4af37" stroke-width="2.5"/>
    <path d="M120 200 Q450 40 780 200" stroke="#0b2240" stroke-width="1.2"/>
    <path d="M160 200 Q450 80 740 200" stroke="#0b2240" stroke-width="0.9"/>
  </svg>

</div>

<main class="container">
    <div class="grid-2">
        <!-- Main Column -->
        <div>
            <div class="card">
                <h2 class="card-title" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <span>📚 Call For Papers</span>
                    <span class="badge-active">Open</span>
                </h2>
                <p style="margin-bottom: 1.5rem;">
                    The Editorial Board of the <strong>Research Journal on Physical Education and Sports (RJPES)</strong> invites researchers, scholars, academicians, and sports professionals to submit their original research articles, review papers, and short communications for publication.
                </p>
                
                <div class="journal-details-grid" style="margin-bottom: 1rem;">
                    <div class="detail-card">
                        <span class="detail-label">ISSN</span>
                        <span class="detail-value">0975-4687</span>
                    </div>
                    <div class="detail-card">
                        <span class="detail-label">Review Process</span>
                        <span class="detail-value">Double-Blind Peer-Reviewed</span>
                    </div>
                    <div class="detail-card">
                        <span class="detail-label">Guidelines</span>
                        <span class="detail-value">UGC Guidelines Compliant</span>
                    </div>
                    <div class="detail-card">
                        <span class="detail-label">Frequency</span>
                        <span class="detail-value">Biannual (Mar & Sep)</span>
                    </div>
                    <div class="detail-card" style="background-color: #fffbeb; border-color: #fde68a;">
                        <span class="detail-label" style="color: #b45309;">Min. Processing Cost</span>
                        <span class="detail-value" style="color: #78350f;">
                            ₹<?php echo number_format($min_processing_fee); ?>*
                            <?php if ($home_gst_mode === 'include'): ?>
                                <span style="display: inline-block; margin-left: 6px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; background: #16a34a; color: white; border-radius: 20px; padding: 2px 8px; vertical-align: middle; line-height: 1.4;">
                                    Incl. GST <?php echo $home_gst_pct; ?>%
                                </span>
                            <?php else: ?>
                                <span style="display: inline-block; margin-left: 6px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; background: #2563eb; color: white; border-radius: 20px; padding: 2px 8px; vertical-align: middle; line-height: 1.4;">
                                    + GST <?php echo $home_gst_pct; ?>%
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-card" style="background-color: #f0fdf4; border-color: #bbf7d0;">
                        <span class="detail-label" style="color: #15803d;">Min. Process Duration</span>
                        <span class="detail-value" style="color: #166534; font-weight: 700;"><?php echo sanitize($min_process_duration); ?></span>
                    </div>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 2rem; text-align: right; font-style: italic;">
                    <?php if ($home_gst_mode === 'include'): ?>
                        * Minimum fee shown is <strong>inclusive of <?php echo $home_gst_pct; ?>% GST</strong>. Actual fee is fixed by the Admin and may vary.
                    <?php else: ?>
                        * Minimum fee shown is <strong>exclusive of GST</strong>. <?php echo $home_gst_pct; ?>% GST will be added on top. Actual fee is fixed by the Admin and may vary.
                    <?php endif; ?>
                </p>
                
                <h3 style="color: var(--primary-color); margin-bottom: 1rem; font-family: var(--font-heading);">🎯 Subject Domains We Publish</h3>
                <p style="margin-bottom: 1rem; font-size: 0.95rem;">
                    We welcome papers across a broad spectrum of physical education, sports, and health disciplines, including but not limited to:
                </p>
                
                <div class="domain-grid">
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                        Physical Education
                    </div>
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><path d="M2 12h20"/></svg>
                        Sports Science
                    </div>
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Sports and Society
                    </div>
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        Kinesiology and Biomechanics
                    </div>
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                        Exercise Physiology
                    </div>
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>
                        Diet, Nutrition and Drugs
                    </div>
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                        Health, Fitness, Yoga and Wellness
                    </div>
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        Sports Equipment and Facilities
                    </div>
                    <div class="domain-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.45 1-1 1H4v2h16v-2h-5c-.55 0-1-.45-1-1v-2.34"/><path d="M12 2a7.7 7.7 0 0 1 7.54 8H4.46A7.7 7.7 0 0 1 12 2z"/></svg>
                        Sports Training and Competitions
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">📖 Submission Workflow</h2>
                <div class="workflow-timeline">
                    <div class="workflow-step">
                        <div class="workflow-badge">1</div>
                        <div class="workflow-content">
                            <h4>Register an Account</h4>
                            <p>Register as an Author and select your relevant sports or research domains to initialize your profile.</p>
                        </div>
                    </div>
                    <div class="workflow-step">
                        <div class="workflow-badge">2</div>
                        <div class="workflow-content">
                            <h4>Submit Manuscript</h4>
                            <p>Upload your draft manuscript in DOCX/DOC format. The system will automatically convert it into a PDF (with standard header, footer, and page numbers) for the portal. A unique <strong>Journal Number</strong> will be assigned to your submission.</p>
                        </div>
                    </div>
                    <div class="workflow-step">
                        <div class="workflow-badge">3</div>
                        <div class="workflow-content">
                            <h4>Peer Review</h4>
                            <p>The Editorial Board will assign your paper to expert Verifiers/Reviewers specializing in your domain for double-blind peer review.</p>
                        </div>
                    </div>
                    <div class="workflow-step">
                        <div class="workflow-badge">4</div>
                        <div class="workflow-content">
                            <h4>Revision & Refinement</h4>
                            <p>If revision is required, address reviewer comments in your draft and submit the updated draft for final verification.</p>
                        </div>
                    </div>
                    <div class="workflow-step">
                        <div class="workflow-badge">5</div>
                        <div class="workflow-content">
                            <h4>Payment & Publishing</h4>
                            <p>Once approved, complete the publication fee payment (fixed by Admin). Upload transaction proof, and the paper will be published, rendered to PDF format, and displayed publicly.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar / Contact Column -->
        <div>
            <div class="card" style="background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);">
                <h3 style="color: var(--primary-color); margin-bottom: 1rem; font-family: var(--font-heading); font-size: 1.3rem;">📩 Editorial Contact</h3>
                <p style="font-size: 0.9rem; margin-bottom: 1rem;">
                    If you experience portal issues or wish to enquire about direct submissions, please contact the Editor:
                </p>
                <div style="background: white; border: 1px solid var(--border-color); padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <p style="font-weight: 700; margin-bottom: 4px; color: var(--primary-dark);"><?php echo sanitize(rjpes_get_setting('editor_name', 'Prof. (Dr.) Biju Lona K.')); ?></p>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px;">Editor-in-Chief, RJPES</p>
                    <p style="font-size: 0.85rem; font-weight: 500;">
                        Email: <a href="mailto:journals@rjpes.in" style="color: var(--primary-color); word-break: break-all;">journals@rjpes.in</a>
                    </p>
                </div>
                <div style="font-size: 0.8rem; color: var(--text-muted); border-top: 1px solid var(--border-color); padding-top: 1rem;">
                    <p>Research Journal on Physical Education and Sports</p>
                    <p>ISSN 0975-4687</p>
                </div>
            </div>

            <div class="card" style="border-left: 4px solid var(--accent-color);">
                <h3 style="color: var(--primary-color); margin-bottom: 0.5rem; font-family: var(--font-heading); font-size: 1.2rem;">UGC Guidelines</h3>
                <p style="font-size: 0.85rem; margin-bottom: 1.25rem;">
                    RJPES follows the standard academic publishing guidelines for peer-reviewed journals. All research articles undergo double-blind peer review to maintain strict scientific standards.
                </p>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="<?php echo $path_prefix; ?>guidelines/9678711_PUBLIC-NOTICE-CARE.pdf" target="_blank" style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; padding: 10px 12px; border: 1px solid var(--primary-color); color: var(--primary-color); background: rgba(11, 34, 64, 0.03); border-radius: 6px; width: 100%; justify-content: center; transition: var(--transition);" onmouseover="this.style.background='var(--primary-color)'; this.style.color='white'; this.style.transform='translateY(-2px)';" onmouseout="this.style.background='rgba(11, 34, 64, 0.03)'; this.style.color='var(--primary-color)'; this.style.transform='none';">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="18" x2="12" y2="12"></line>
                            <polyline points="9 15 12 18 15 15"></polyline>
                        </svg>
                        UGC CARE Public Notice (PDF)
                    </a>
                    
                    <a href="<?php echo $path_prefix; ?>guidelines/UGC PEER REVIEWED JOURNAL GUIDELINES.pdf" target="_blank" style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; padding: 10px 12px; border: 1px solid var(--primary-color); color: var(--primary-color); background: rgba(11, 34, 64, 0.03); border-radius: 6px; width: 100%; justify-content: center; transition: var(--transition);" onmouseover="this.style.background='var(--primary-color)'; this.style.color='white'; this.style.transform='translateY(-2px)';" onmouseout="this.style.background='rgba(11, 34, 64, 0.03)'; this.style.color='var(--primary-color)'; this.style.transform='none';">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="18" x2="12" y2="12"></line>
                            <polyline points="9 15 12 18 15 15"></polyline>
                        </svg>
                        UGC Peer Reviewed Guidelines (PDF)
                    </a>

                    <a href="<?php echo $path_prefix; ?>guidelines/UGC minimum-Standards-and-Procedure-for-Award-of-PhD-Degree.pdf" target="_blank" style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; padding: 10px 12px; border: 1px solid var(--primary-color); color: var(--primary-color); background: rgba(11, 34, 64, 0.03); border-radius: 6px; width: 100%; justify-content: center; transition: var(--transition);" onmouseover="this.style.background='var(--primary-color)'; this.style.color='white'; this.style.transform='translateY(-2px)';" onmouseout="this.style.background='rgba(11, 34, 64, 0.03)'; this.style.color='var(--primary-color)'; this.style.transform='none';">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="18" x2="12" y2="12"></line>
                            <polyline points="9 15 12 18 15 15"></polyline>
                        </svg>
                        UGC PhD Award Standards (PDF)
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>
</div><!-- /.home-main-wrap -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
