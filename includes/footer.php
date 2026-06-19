<?php
// Fetch system settings for the footer modals
$footer_vol = '20';
$footer_issue = '1';
$footer_date = 'June 2026';
$min_fee = '₹3,500.00';
$min_duration = '7-14 Days';

if (isset($pdo)) {
    try {
        $set_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('current_volume', 'current_issue', 'current_edition_date', 'min_processing_fee', 'min_process_duration')");
        if ($set_stmt) {
            $settings = [];
            while ($row = $set_stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            if (!empty($settings['current_volume'])) $footer_vol = $settings['current_volume'];
            if (!empty($settings['current_issue'])) $footer_issue = $settings['current_issue'];
            if (!empty($settings['current_edition_date'])) {
                $footer_date = date('F Y', strtotime($settings['current_edition_date']));
            }
            if (!empty($settings['min_processing_fee'])) {
                $min_fee = '₹' . number_format($settings['min_processing_fee'], 2);
            }
            if (!empty($settings['min_process_duration'])) {
                $min_duration = $settings['min_process_duration'];
            }
        }
    } catch (Exception $e) {
        // Fallback to defaults
    }
}
?>
    <footer>
        <div class="footer-container">
            <!-- Column 1: About -->
            <div class="footer-column">
                <h4>About RJPES</h4>
                <p style="margin-bottom: 12px; font-size: 0.85rem; line-height: 1.6;">
                    The Research Journal on Physical Education and Sports (RJPES) is the official "Voice of Sports" of ACTPE, University of Calicut. It promotes scholarly research and advancement in sports sciences.
                </p>
                <p style="font-size: 0.85rem; color: white;">
                    <strong>ISSN:</strong> 0975-4687
                </p>
            </div>

            <!-- Column 2: Publications -->
            <div class="footer-column">
                <h4>Publications</h4>
                <ul style="font-size: 0.85rem; line-height: 1.8;">
                    <li><a href="javascript:void(0)" onclick="openFooterModal('feesModal')">Fees & Payment</a></li>
                    <li><a href="javascript:void(0)" onclick="openFooterModal('currentIssueModal')">Current Issue</a></li>
                    <li><a href="<?php echo $path_prefix; ?>journals.php">Publication Archive</a></li>
                </ul>
            </div>

            <!-- Column 3: For Authors -->
            <div class="footer-column">
                <h4>For Authors</h4>
                <ul style="font-size: 0.85rem; line-height: 1.8;">
                    <li><a href="<?php echo $path_prefix; ?>author/submit.php">Submit Research Paper</a></li>
                    <li><a href="<?php echo $path_prefix; ?>author/dashboard.php">Track Submission Status</a></li>
                    <li><a href="<?php echo $path_prefix; ?>guidelines/9678711_PUBLIC-NOTICE-CARE.pdf" target="_blank">Publication Guidelines</a></li>
                    <li><a href="<?php echo $path_prefix; ?>#">Publication Ethics</a></li>
                    <li><a href="<?php echo $path_prefix; ?>#">Peer Review & Plagiarism</a></li>
                </ul>
            </div>

            <!-- Column 4: Reviewers -->
            <div class="footer-column">
                <h4>Reviewers</h4>
                <ul style="font-size: 0.85rem; line-height: 1.8;">
                    <li><a href="<?php echo $path_prefix; ?>register.php?role=reviewer">Join as a Reviewer</a></li>
                    <li><a href="<?php echo $path_prefix; ?>#">Editors & Reviewers</a></li>
                    <li><a href="<?php echo $path_prefix; ?>#">Reviewer Referral Program</a></li>
                    <li><a href="<?php echo $path_prefix; ?>#">Get Reviewer Membership Certi.</a></li>
                </ul>
            </div>

            <!-- Column 5: Policies -->
            <div class="footer-column">
                <h4>Policies</h4>
                <ul style="font-size: 0.85rem; line-height: 1.8;">
                    <li><a href="<?php echo $path_prefix; ?>#">Website/Journal Policies</a></li>
                    <li><a href="<?php echo $path_prefix; ?>#">Usage Policy</a></li>
                    <li><a href="<?php echo $path_prefix; ?>#">Content Policies</a></li>
                    <li><a href="<?php echo $path_prefix; ?>#">Privacy Policy</a></li>
                </ul>
            </div>
        </div>

        <!-- Contact Section Centered above footer-bottom -->
        <div style="max-width: 1200px; margin: 0 auto 1.5rem auto; padding-top: 1.5rem; border-top: 1px solid #1e293b; text-align: center;">
            <a href="mailto:journals@rjpes.in" style="font-family: var(--font-heading); font-weight: 700; color: var(--accent-color); text-decoration: none; font-size: 1.1rem; display: inline-block; margin-bottom: 6px; transition: color 0.3s;" onmouseover="this.style.color='white'" onmouseout="this.style.color='var(--accent-color)'">Contact Us</a>
            <p style="font-size: 0.85rem; color: #94a3b8;">
                <strong>Editor:</strong> <?php echo sanitize(rjpes_get_setting('editor_name', 'Prof. (Dr.) Biju Lona K.')); ?> &nbsp;|&nbsp; 
                <strong>Email:</strong> journals@rjpes.in
            </p>
        </div>
        
        <div class="footer-bottom">
            <p style="font-size: 0.82rem; line-height: 1.8; color: #94a3b8;">
                &copy; <?php echo date('Y'); ?> Research Journal on Physical Education and Sports (RJPES). All Rights Reserved. | 
                Peer-Reviewed Journal | UGC Guidelines Followed | 
                Since 2005 &bull; <?php echo (date('Y') - 2005); ?> Years of Experience | 
                Powered By <a href="http://www.sandslab.com" target="_blank" style="color: var(--accent-color); text-decoration: none; font-weight: 700; transition: color 0.3s;" onmouseover="this.style.color='white'" onmouseout="this.style.color='var(--accent-color)'">SaNDS Lab</a>
            </p>
        </div>
    </footer>

    <!-- Floating Experience Badge -->
    <div class="floating-badge-left" onclick="window.scrollTo({top: 0, behavior: 'smooth'})" title="Click to scroll to top">
        <div class="badge-num"><?php echo (date('Y') - 2005); ?></div>
        <div class="badge-text">
            <div class="text-top">YEARS OF</div>
            <div class="text-mid">EXCELLENCE</div>
            <div class="text-bot">SINCE 2005</div>
        </div>
    </div>
    <!-- jQuery and DataTables JS (global - for all pages with .datatable tables) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script>
        $(document).ready(function() {
            if ($('.datatable').length > 0) {
                $('.datatable').DataTable({
                    pageLength: 10,
                    order: [],
                    language: {
                        searchPlaceholder: "Search records...",
                        search: ""
                    }
                });
            }
        });
    </script>
    <!-- Ladda & Spin JS (Global form submission loaders) -->
    <script src="https://cdn.jsdelivr.net/npm/ladda@1.0.6/dist/spin.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/ladda@1.0.6/dist/ladda.min.js"></script>
    <script>
        document.addEventListener('submit', function(e) {
            // Check if submission is already prevented by validation scripts
            if (e.defaultPrevented) return;

            // Find the submit button inside this form
            var submitBtn = e.target.querySelector('button[type="submit"]') || e.target.querySelector('input[type="submit"]');
            if (submitBtn) {
                // If the button has a name and is about to be disabled by Ladda,
                // append a hidden input so its name/value is still submitted to the server.
                if (submitBtn.name && !e.target.querySelector('input[type="hidden"][name="' + submitBtn.name + '"]')) {
                    var hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = submitBtn.name;
                    hiddenInput.value = submitBtn.value || '1';
                    e.target.appendChild(hiddenInput);
                }

                // Apply Ladda classes and attributes
                if (!submitBtn.classList.contains('ladda-button')) {
                    submitBtn.classList.add('ladda-button');
                }
                if (!submitBtn.hasAttribute('data-style')) {
                    submitBtn.setAttribute('data-style', 'expand-right');
                }
                
                // Wrap content inside a ladda-label tag if not already done
                if (!submitBtn.querySelector('.ladda-label')) {
                    var innerSpan = document.createElement('span');
                    innerSpan.className = 'ladda-label';
                    while (submitBtn.firstChild) {
                        innerSpan.appendChild(submitBtn.firstChild);
                    }
                    submitBtn.appendChild(innerSpan);
                }

                // Start loading animation
                var l = Ladda.create(submitBtn);
                l.start();
            }
        });
    </script>
    <!-- Fees & Payment Modal -->
    <div id="feesModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 500px; width: 90%; background: #ffffff; border-radius: 8px; padding: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; text-align: left;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
                <h3 style="margin: 0; font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem;">Fees &amp; Payment Policy</h3>
                <button onclick="closeFooterModal('feesModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8; line-height: 1;">&times;</button>
            </div>
            <div style="font-size: 0.9rem; line-height: 1.6; color: #334155;">
                <p style="margin-bottom: 12px;"><strong>Base Processing Fee:</strong> <?php echo $min_fee; ?></p>
                <p style="margin-bottom: 12px;"><strong>Processing Duration:</strong> <?php echo $min_duration; ?></p>
                <p style="margin-bottom: 12px;"><strong>GST / Taxes:</strong> Standard rates apply based on location.</p>
                <div style="background: #f0fdf4; border-left: 4px solid #16a34a; border-radius: 6px; padding: 12px; margin-top: 16px; font-size: 0.82rem; color: #15803d; line-height: 1.5;">
                    <strong>Verification Policy:</strong><br>
                    All submissions undergo standard offline double-blind peer reviews. Processing fees are requested only after the editor issues a formal acceptance request for payment.
                </div>
            </div>
            <div style="text-align: right; margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 16px;">
                <button onclick="closeFooterModal('feesModal')" class="btn btn-primary" style="padding: 8px 18px; font-size: 0.85rem; border-radius: 6px; font-weight: 600;">Close</button>
            </div>
        </div>
    </div>

    <!-- Current Issue Modal -->
    <div id="currentIssueModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 500px; width: 90%; background: #ffffff; border-radius: 8px; padding: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; text-align: left;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px;">
                <h3 style="margin: 0; font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem;">Current Issue</h3>
                <button onclick="closeFooterModal('currentIssueModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8; line-height: 1;">&times;</button>
            </div>
            <div style="font-size: 0.9rem; line-height: 1.6; color: #334155;">
                <p style="margin-bottom: 12px;"><strong>Journal Portal:</strong> Research Journal on Physical Education and Sports (RJPES)</p>
                <p style="margin-bottom: 12px;"><strong>Current Edition:</strong> Volume <?php echo htmlspecialchars($footer_vol); ?>, Issue <?php echo htmlspecialchars($footer_issue); ?></p>
                <p style="margin-bottom: 12px;"><strong>Release Month/Year:</strong> <?php echo htmlspecialchars($footer_date); ?></p>
                <p style="margin-bottom: 12px;"><strong>ISSN:</strong> 0975-4687</p>
                <div style="background: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 6px; padding: 12px; margin-top: 16px; font-size: 0.82rem; color: #1d4ed8; line-height: 1.5;">
                    <strong>Open for Submissions:</strong><br>
                    Authors can register and upload their research manuscripts directly in DOCX/DOC formats. The system automatically converts them to PDF editions.
                </div>
            </div>
            <div style="text-align: right; margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 16px;">
                <button onclick="closeFooterModal('currentIssueModal')" class="btn btn-primary" style="padding: 8px 18px; font-size: 0.85rem; border-radius: 6px; font-weight: 600;">Close</button>
            </div>
        </div>
    </div>

    <script>
    function openFooterModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'flex';
        }
    }

    function closeFooterModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // Close modal on backdrop click
    window.addEventListener('click', function(e) {
        var modal1 = document.getElementById('feesModal');
        var modal2 = document.getElementById('currentIssueModal');
        if (e.target === modal1) modal1.style.display = 'none';
        if (e.target === modal2) modal2.style.display = 'none';
    });
    </script>
</body>
</html>
