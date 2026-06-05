<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$message = "";
$message_type = "";

// Handle Add New Admin Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $fullname = sanitize($_POST['fullname']);
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    $phone = sanitize($_POST['phone'] ?? '');
    
    if (empty($fullname) || empty($username) || empty($email) || empty($password)) {
        $message = "Please fill in all required fields.";
        $message_type = "danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email address format.";
        $message_type = "danger";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters long.";
        $message_type = "danger";
    } else {
        try {
            // Check for duplicate username or email
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $message = "Username or email already exists.";
                $message_type = "danger";
            } else {
                // Insert new admin user
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $ins = $pdo->prepare("INSERT INTO users (username, email, password, fullname, role, phone) VALUES (?, ?, ?, ?, 'admin', ?)");
                $ins->execute([$username, $email, $hashed_password, $fullname, $phone]);
                $message = "Administrator account created successfully!";
                $message_type = "success";
            }
        } catch (PDOException $e) {
            $message = "Registration failed: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Handle Update Admin Info Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_info'])) {
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
                    $stmt = $pdo->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ? AND role = 'admin'");
                    $stmt->execute([$fullname, $email, $phone, $user_id]);
                    $message = "Administrator profile updated successfully.";
                    $message_type = "success";
                    
                    // If the updated admin is the logged-in user, refresh their session name
                    if ($user_id == $_SESSION['user_id']) {
                        $_SESSION['fullname'] = $fullname;
                    }
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

// Handle Password Reset Action
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
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'admin'");
                $stmt->execute([$hashed, $user_id]);
                $message = "Administrator password reset successfully.";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Failed to reset password: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// Fetch all administrators
try {
    $stmt = $pdo->prepare("SELECT id, username, email, fullname, phone, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC");
    $stmt->execute();
    $admins = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database query failed: " . $e->getMessage());
}

$open_add_modal = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin']) && $message_type === 'danger');

$page_title = "Manage Administrators";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <main class="main-content" style="width: 100%;">
        <!-- Page Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="font-family: var(--font-heading); color: var(--primary-color);">Manage Administrators</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Review and manage registered portal administrators</p>
            </div>
            <!-- Add Admin Button -->
            <button onclick="openAddAdminModal()" class="btn btn-dark" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="8.5" cy="7" r="4"/>
                    <line x1="20" y1="8" x2="20" y2="14"/>
                    <line x1="23" y1="11" x2="17" y2="11"/>
                </svg>
                Add New Admin
            </button>
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

        <!-- Administrators Table Card -->
        <div class="card" style="padding: 1.5rem;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                Active Administrators
            </h3>
            
            <?php if (empty($admins)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.7;">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                    </svg>
                    <p style="font-weight: 500;">No administrators found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Admin Name</th>
                                <th>Email / Username</th>
                                <th>Registered Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $a): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--primary-color);">
                                        <?php echo sanitize($a['fullname']); ?>
                                        <?php if ($a['id'] == $_SESSION['user_id']): ?>
                                            <span style="font-size: 0.75rem; background-color: #dbeafe; color: #1d4ed8; padding: 2px 6px; border-radius: 4px; font-weight: normal; margin-left: 6px;">You</span>
                                        <?php endif; ?>
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
                                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
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

<!-- ===== MODAL: Add New Admin ===== -->
<div id="addAdminModal" class="modal-overlay" style="display: none; align-items: flex-start; padding-top: 40px;">
    <div class="modal-content" style="max-width: 540px; width: 95%;">
        <!-- Modal Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 2px solid var(--accent-color); padding-bottom: 12px;">
            <div>
                <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem; margin: 0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px; margin-bottom: 2px;">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="8.5" cy="7" r="4"/>
                        <line x1="20" y1="8" x2="20" y2="14"/>
                        <line x1="23" y1="11" x2="17" y2="11"/>
                    </svg>
                    Add New Administrator
                </h3>
            </div>
            <button onclick="closeAddAdminModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); line-height: 1;">&times;</button>
        </div>
        
        <!-- Add Admin Form -->
        <form action="admins.php" method="POST" id="addAdminForm">
            <input type="hidden" name="add_admin" value="1">
            
            <div class="form-group">
                <label for="fullname">Full Name</label>
                <input type="text" name="fullname" id="fullname" class="form-control" placeholder="Enter administrator name" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" class="form-control" placeholder="Unique login username" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" name="email" id="email" class="form-control" placeholder="admin@portal.com" required>
                </div>
            </div>

            <div class="form-group">
                <label for="phone">Mobile Number <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">(Optional)</span></label>
                <input type="text" name="phone" id="phone" class="form-control" placeholder="e.g. +919876543210">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" onclick="closeAddAdminModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 10px 20px;">
                    Cancel
                </button>
                <button type="submit" class="btn btn-dark" style="padding: 10px 24px; display: inline-flex; align-items: center; gap: 8px;">
                    Create Admin Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL: Edit Admin Info ===== -->
<div id="editModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 500px; width: 95%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.2rem;">Edit Admin Info</h3>
            <button onclick="closeEditModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="admins.php" method="POST">
            <input type="hidden" name="update_admin_info" value="1">
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

<!-- ===== MODAL: Reset Password ===== -->
<div id="resetModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.2rem;">Reset Password</h3>
            <button onclick="closeResetModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="admins.php" method="POST">
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="user_id" id="resetUserId">
            
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Set a new password for administrator <strong id="resetUserName"></strong>.
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

<script>
// ── Add Admin Modal ──────────────────────────────────────────────
function openAddAdminModal() {
    document.getElementById('addAdminModal').style.display = 'flex';
}
function closeAddAdminModal() {
    document.getElementById('addAdminModal').style.display = 'none';
    document.getElementById('addAdminForm').reset();
}
document.getElementById('addAdminModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddAdminModal();
});

// Auto-open modal on page load if there was a validation error on add_admin
<?php if ($open_add_modal): ?>
window.addEventListener('DOMContentLoaded', function() { openAddAdminModal(); });
<?php endif; ?>

// ── Reset Password Modal ──────────────────────────────────────────
function openResetModal(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').textContent = userName;
    document.getElementById('resetModal').style.display = 'flex';
}
function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
    document.getElementById('new_password').value = '';
}

// ── Edit Info Modal ───────────────────────────────────────────────
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
