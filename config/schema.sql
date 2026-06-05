-- Create Database
CREATE DATABASE IF NOT EXISTS rjpes_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rjpes_db;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    role ENUM('author', 'reviewer', 'admin') NOT NULL,
    subject_domain VARCHAR(100) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journals Table
CREATE TABLE IF NOT EXISTS journals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    abstract TEXT NOT NULL,
    content TEXT DEFAULT NULL, -- Additional text content for the journal
    subject_domain VARCHAR(100) NOT NULL,
    manuscript_file VARCHAR(255) NOT NULL, -- Path to uploaded draft
    journal_number VARCHAR(50) NOT NULL UNIQUE,
    status ENUM(
        'submitted_waiting_review', 
        'under_review', 
        'revisions_required', 
        'ready_for_publish', 
        'payment_pending', 
        'published', 
        'rejected'
    ) DEFAULT 'submitted_waiting_review',
    payment_amount DECIMAL(10,2) DEFAULT NULL,
    volume VARCHAR(50) DEFAULT NULL,
    issue VARCHAR(50) DEFAULT NULL,
    published_at TIMESTAMP NULL DEFAULT NULL,
    pdf_path VARCHAR(255) DEFAULT NULL, -- Path to generated final PDF
    verifier_cut DECIMAL(10,2) DEFAULT NULL,
    admin_cut DECIMAL(10,2) DEFAULT NULL,
    portal_cut DECIMAL(10,2) DEFAULT NULL,
    gst_type VARCHAR(10) DEFAULT 'exclude',
    gst_amount DECIMAL(10,2) DEFAULT 0.00,
    base_amount DECIMAL(10,2) DEFAULT 0.00,
    bill_number VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reviews Table
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    comments TEXT NOT NULL,
    recommendation ENUM('approve', 'revision', 'reject') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payments Table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_id INT NOT NULL UNIQUE,
    transaction_id VARCHAR(100) NOT NULL,
    payment_proof VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_by INT DEFAULT NULL,
    FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal Reviewer Assignment Table (to track who is assigned to review what)
CREATE TABLE IF NOT EXISTS reviewer_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('assigned', 'reviewed') DEFAULT 'assigned',
    FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment (journal_id, reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert Seed Admin User
-- Username: admin, Password: AdminPassword123!
INSERT INTO users (username, email, password, fullname, role)
VALUES ('admin', 'admin@portal.com', '$2y$10$3dn.p0m/3whJoEn4NZHwYeYXnKa/uLuloIWMl4azMDMFMJiqB2vuu', 'System Administrator', 'admin')
ON DUPLICATE KEY UPDATE id=id;

-- Journal Document Versions Table (tracks every uploaded document version)
CREATE TABLE IF NOT EXISTS journal_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_id INT NOT NULL,
    version_number INT NOT NULL DEFAULT 1,
    manuscript_file VARCHAR(255) NOT NULL,   -- Path to this version's file
    author_notes TEXT DEFAULT NULL,          -- Optional notes from author on revision
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (journal_id) REFERENCES journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
