    <footer>
        <div class="footer-container">
            <div class="footer-column">
                <h4>About RJPES</h4>
                <p style="margin-bottom: 12px; font-size: 0.85rem;">
                    The Research Journal on Physical Education and Sports (RJPES) is the official "Voice of Sports" of ACTPE, University of Calicut. It promotes scholarly research and advancement in sports sciences.
                </p>
                <p style="font-size: 0.85rem; color: white;">
                    <strong>ISSN:</strong> 0975-4687
                </p>
            </div>
            
            <div class="footer-column">
                <h4>Subject Domains</h4>
                <ul style="font-size: 0.85rem;">
                    <li>Physical Education & Sports Science</li>
                    <li>Yoga & Health Education</li>
                    <li>Nutrition & Physical Fitness</li>
                    <li>Group Dynamics & Allied Subjects</li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h4>Editorial Submissions</h4>
                <p style="font-size: 0.85rem; margin-bottom: 8px;">
                    <strong>Editor:</strong> <?php echo sanitize(rjpes_get_setting('editor_name', 'Prof. (Dr.) Biju Lona K.')); ?>
                </p>
                <p style="font-size: 0.85rem;">
                    <strong>Email:</strong> <a href="mailto:journals@rjpes.in" style="color: var(--accent-color);">journals@rjpes.in</a>
                </p>
            </div>
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
</body>
</html>
