<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$message = "";
$message_type = "";

// 1. Handle Block / Unblock Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_block'])) {
    $user_id = intval($_POST['user_id']);
    $new_status = intval($_POST['status']);
    
    if ($user_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_blocked = ? WHERE id = ? AND role = 'author'");
            $stmt->execute([$new_status, $user_id]);
            $message = $new_status ? "Author account blocked successfully." : "Author account unblocked successfully.";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Failed to update account status: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// 2. Handle Password Reset Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $user_id = intval($_POST['user_id']);
    $new_password = $_POST['new_password'];
    
    if ($user_id > 0 && !empty($new_password)) {
        if (strlen($new_password) < 6) {
            $message = "Password must be at least 6 characters long.";
            $message_type = "danger";
        } else {
            try {
                $hashed = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'author'");
                $stmt->execute([$hashed, $user_id]);
                $message = "Author password reset successfully.";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Failed to reset password: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// 3. Handle Update Author Info Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_author_info'])) {
    $user_id = intval($_POST['user_id']);
    $fullname = sanitize($_POST['fullname']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone'] ?? '');
    
    if ($user_id > 0 && !empty($fullname) && !empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email address format.";
            $message_type = "danger";
        } else {
            try {
                // Check if email already exists for another user
                $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $chk->execute([$email, $user_id]);
                if ($chk->fetch()) {
                    $message = "Email address is already in use by another account.";
                    $message_type = "danger";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ? AND role = 'author'");
                    $stmt->execute([$fullname, $email, $phone, $user_id]);
                    $message = "Author profile updated successfully.";
                    $message_type = "success";
                }
            } catch (PDOException $e) {
                $message = "Failed to update profile: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    } else {
        $message = "Full Name and Email are required fields.";
        $message_type = "danger";
    }
}

// Fetch all authors
try {
    $stmt = $pdo->prepare("SELECT id, username, email, fullname, phone, is_blocked, created_at FROM users WHERE role = 'author' ORDER BY created_at DESC");
    $stmt->execute();
    $authors = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database query failed: " . $e->getMessage());
}

$page_title = "Manage Authors";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <main class="main-content" style="width: 100%;">
        <div style="margin-bottom: 2rem;">
            <h2 style="font-family: var(--font-heading); color: var(--primary-color);">Manage Authors</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Review registered researchers, manage account status, and perform password resets</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php if ($message_type == 'success'): ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php endif; ?>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <div class="card" style="padding: 1.5rem;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Registered Authors</h3>
            
            <?php if (empty($authors)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.7;">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                    </svg>
                    <p style="font-weight: 500;">No authors have registered in the portal yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Author Name</th>
                                <th>Email / Username</th>
                                <th>Registered Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($authors as $a): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--primary-color);">
                                        <?php echo sanitize($a['fullname']); ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo sanitize($a['email']); ?></div>
                                        <?php if (!empty($a['phone'])): ?>
                                            <div style="font-size: 0.8rem; color: var(--text-color); margin-top: 2px;">📞 <?php echo sanitize($a['phone']); ?></div>
                                        <?php endif; ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">User: <?php echo sanitize($a['username']); ?></div>
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);">
                                        <?php echo date('d M Y', strtotime($a['created_at'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($a['is_blocked']): ?>
                                            <span class="badge badge-rejected" style="font-size: 0.72rem;">Blocked</span>
                                        <?php else: ?>
                                            <span class="badge badge-ready_for_publish" style="font-size: 0.72rem;">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                            <!-- Block/Unblock Form -->
                                            <form action="authors.php" method="POST" style="margin: 0;">
                                                <input type="hidden" name="toggle_block" value="1">
                                                <input type="hidden" name="user_id" value="<?php echo $a['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $a['is_blocked'] ? '0' : '1'; ?>">
                                                <?php if ($a['is_blocked']): ?>
                                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background-color: var(--success-color); color: white;">
                                                        Unblock
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background-color: var(--danger-color); color: white;">
                                                        Block
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <!-- Edit Info Trigger -->
                                            <button onclick="openEditModal(<?php echo $a['id']; ?>, '<?php echo addslashes(sanitize($a['fullname'])); ?>', '<?php echo addslashes(sanitize($a['email'])); ?>', '<?php echo addslashes(sanitize($a['phone'] ?? '')); ?>')" class="btn btn-primary" style="padding: 4px 8px; font-size: 0.75rem; background-color: var(--accent-color); color: var(--primary-dark); border: none;">
                                                Edit Info
                                            </button>

                                            <!-- Reset Password Trigger -->
                                            <button onclick="openResetModal(<?php echo $a['id']; ?>, '<?php echo addslashes(sanitize($a['fullname'])); ?>')" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem; border: 1px solid var(--border-color); color: var(--text-color);">
                                                Reset Password
                                            </button>
                                        </div>
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

<!-- Modal: Reset Password -->
<div id="resetModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.2rem;">Reset Password</h3>
            <button onclick="closeResetModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="authors.php" method="POST">
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="user_id" id="resetUserId">
            
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Set a new password for author <strong id="resetUserName"></strong>.
            </p>
            
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Enter at least 6 characters" required minlength="6">
            </div>
            
            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeResetModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 16px;">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Info -->
<div id="editModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.2rem;">Edit Author Info</h3>
            <button onclick="closeEditModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="authors.php" method="POST">
            <input type="hidden" name="update_author_info" value="1">
            <input type="hidden" name="user_id" id="editUserId">
            
            <div class="form-group">
                <label for="edit_fullname">Full Name</label>
                <input type="text" name="fullname" id="edit_fullname" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="edit_email">Email Address</label>
                <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="edit_phone">Mobile Number <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">(Optional)</span></label>
                <input type="text" name="phone" id="edit_phone" class="form-control" placeholder="e.g. +919876543210">
            </div>
            
            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 16px;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').textContent = userName;
    document.getElementById('resetModal').style.display = 'flex';
}
function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
    document.getElementById('new_password').value = '';
}

function openEditModal(userId, userName, userEmail, userPhone) {
    document.getElementById('editUserId').value = userId;
    document.getElementById('edit_fullname').value = userName;
    document.getElementById('edit_email').value = userEmail;
    document.getElementById('edit_phone').value = userPhone;
    document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.getElementById('editUserId').value = '';
    document.getElementById('edit_fullname').value = '';
    document.getElementById('edit_email').value = '';
    document.getElementById('edit_phone').value = '';
}

// Backdrop modal click close
document.getElementById('resetModal').addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
