-- =====================================================
-- TSU ICT HELP DESK - COMPLETE SYSTEM SETUP
-- This file sets up the entire system from scratch
-- Version: 2.0 (with Student Portal)
-- Created: January 2026
-- =====================================================

-- Create database if not exists (uncomment if needed)
-- CREATE DATABASE IF NOT EXISTS tsu_helpdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE tsu_helpdesk;

-- =====================================================
-- CORE SYSTEM TABLES
-- =====================================================

-- Create roles table
CREATE TABLE IF NOT EXISTS roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default roles
INSERT IGNORE INTO roles (role_name, description) VALUES
('Admin', 'System Administrator'),
('Staff', 'Regular Staff Member'),
('Director', 'Director Level Access'),
('DVC', 'Deputy Vice Chancellor'),
('I4CUS', 'I4CUS Staff'),
('Payment Admin', 'Payment Administration'),
('Department', 'Department Staff');

-- Create users table
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(191) DEFAULT NULL,
    role_id INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role_id (role_id),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin user (password: admin123)
INSERT IGNORE INTO users (username, password, full_name, role_id) VALUES
('williams', MD5('admin123'), 'System Administrator', 1);

-- Create complaints table
CREATE TABLE IF NOT EXISTS complaints (
    complaint_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(191) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    complaint_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('Pending', 'Treated', 'Needs More Info') DEFAULT 'Pending',
    feedback TEXT DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    handled_by INT DEFAULT NULL,
    is_payment_related TINYINT(1) DEFAULT 0,
    is_i4cus TINYINT(1) DEFAULT 0,
    feedback_type VARCHAR(50) DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_complaint_type (complaint_type),
    INDEX idx_created_at (created_at),
    INDEX idx_handled_by (handled_by),
    FOREIGN KEY (handled_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create login_activities table
CREATE TABLE IF NOT EXISTS login_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    status ENUM('success', 'failed') DEFAULT 'success',
    INDEX idx_user_id (user_id),
    INDEX idx_login_time (login_time),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create messages table
CREATE TABLE IF NOT EXISTS messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sender_id (sender_id),
    INDEX idx_receiver_id (receiver_id),
    INDEX idx_is_read (is_read),
    FOREIGN KEY (sender_id) REFERENCES users(user_id),
    FOREIGN KEY (receiver_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create notifications table
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_type (type),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create suggestions table
CREATE TABLE IF NOT EXISTS suggestions (
    suggestion_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('pending', 'reviewed', 'implemented', 'rejected') DEFAULT 'pending',
    admin_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- STUDENT SYSTEM TABLES
-- =====================================================

-- Create faculties table
CREATE TABLE IF NOT EXISTS faculties (
    faculty_id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_name VARCHAR(255) NOT NULL,
    faculty_code VARCHAR(10) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create student departments table
CREATE TABLE IF NOT EXISTS student_departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(255) NOT NULL,
    department_code VARCHAR(10) NOT NULL,
    faculty_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_faculty_id (faculty_id),
    FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create programmes table
CREATE TABLE IF NOT EXISTS programmes (
    programme_id INT AUTO_INCREMENT PRIMARY KEY,
    programme_name VARCHAR(255) NOT NULL,
    programme_code VARCHAR(10) NOT NULL,
    department_id INT NOT NULL,
    reg_number_format VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_department_id (department_id),
    FOREIGN KEY (department_id) REFERENCES student_departments(department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create students table
CREATE TABLE IF NOT EXISTS students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    registration_number VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    faculty_id INT NOT NULL,
    department_id INT NOT NULL,
    programme_id INT NOT NULL,
    year_of_entry YEAR NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_faculty_id (faculty_id),
    INDEX idx_department_id (department_id),
    INDEX idx_programme_id (programme_id),
    INDEX idx_registration_number (registration_number),
    INDEX idx_email (email),
    FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id),
    FOREIGN KEY (department_id) REFERENCES student_departments(department_id),
    FOREIGN KEY (programme_id) REFERENCES programmes(programme_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create student complaints table
CREATE TABLE IF NOT EXISTS student_complaints (
    complaint_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_code VARCHAR(20) NOT NULL,
    course_title VARCHAR(255) NOT NULL,
    complaint_type ENUM('FA', 'F', 'Incorrect Grade') NOT NULL,
    description TEXT,
    status ENUM('Pending', 'Under Review', 'Resolved', 'Rejected') DEFAULT 'Pending',
    admin_response TEXT DEFAULT NULL,
    handled_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student_id (student_id),
    INDEX idx_status (status),
    INDEX idx_complaint_type (complaint_type),
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (handled_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- INSERT FACULTY AND ACADEMIC DATA
-- =====================================================

-- Clear existing student system data to avoid conflicts
DELETE FROM programmes WHERE 1=1;
DELETE FROM student_departments WHERE 1=1;
DELETE FROM faculties WHERE 1=1;

-- Insert all faculties
INSERT INTO faculties (faculty_name, faculty_code) VALUES
('Faculty of Agriculture', 'FAG'),
('Faculty of Health Sciences', 'FAH'),
('Faculty of Arts', 'FART'),
('Faculty of Communication and Media Studies', 'FCMS'),
('Faculty of Education', 'FED'),
('Faculty of Engineering', 'FEN'),
('Faculty of Management Sciences', 'FMS'),
('Faculty of Sciences', 'FSC'),
('Faculty of Social and Management Sciences', 'FSMS'),
('Faculty of Social Sciences', 'FSS'),
('Faculty of Law', 'LAW');

-- Insert departments for Faculty of Agriculture (FAG)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Agricultural Economics and Extension', 'AE', faculty_id FROM faculties WHERE faculty_code = 'FAG';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Agronomy', 'AG', faculty_id FROM faculties WHERE faculty_code = 'FAG';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Animal Science', 'AS', faculty_id FROM faculties WHERE faculty_code = 'FAG';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Crop Protection', 'CP', faculty_id FROM faculties WHERE faculty_code = 'FAG';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Family and Consumer Science', 'FSC', faculty_id FROM faculties WHERE faculty_code = 'FAG';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Forestry and Wildlife Management', 'FW', faculty_id FROM faculties WHERE faculty_code = 'FAG';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Home Economics', 'HE', faculty_id FROM faculties WHERE faculty_code = 'FAG';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Soil Science and Land Resource Management', 'SS', faculty_id FROM faculties WHERE faculty_code = 'FAG';

-- Insert departments for Faculty of Health Sciences (FAH)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Environmental Health', 'EH', faculty_id FROM faculties WHERE faculty_code = 'FAH';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Medical Laboratory Science', 'ML', faculty_id FROM faculties WHERE faculty_code = 'FAH';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Nursing', 'NS', faculty_id FROM faculties WHERE faculty_code = 'FAH';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Public Health', 'PU', faculty_id FROM faculties WHERE faculty_code = 'FAH';

-- Insert departments for Faculty of Arts (FART)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Arabic', 'AR', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Christian Religious Studies', 'CR', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'French', 'FL', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Hausa', 'HL', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'History and Diplomatic Studies', 'HS', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Islamic Studies', 'IS', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Linguistics English', 'LE', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'English', 'LG', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Linguistics Hausa', 'LH', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Linguistics Mumuye', 'MU', faculty_id FROM faculties WHERE faculty_code = 'FART';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Theatre and Film Studies', 'TF', faculty_id FROM faculties WHERE faculty_code = 'FART';

-- Insert departments for Faculty of Communication and Media Studies (FCMS)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Advertising', 'AD', faculty_id FROM faculties WHERE faculty_code = 'FCMS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Broadcasting', 'BC', faculty_id FROM faculties WHERE faculty_code = 'FCMS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Journalism and Media Studies', 'JM', faculty_id FROM faculties WHERE faculty_code = 'FCMS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Mass Communication', 'MC', faculty_id FROM faculties WHERE faculty_code = 'FCMS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Public Relations', 'PR', faculty_id FROM faculties WHERE faculty_code = 'FCMS';

-- Insert departments for Faculty of Education (FED)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Agricultural Education', 'AE', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Educational Administration and Planning', 'AP', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Business Education', 'BE', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Biology Education', 'BL', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Chemistry Education', 'CH', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Computer Science Education', 'CS', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Christian Religious Studies Education', 'CSR', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Economics Education', 'EC', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'English Education', 'EL', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Primary Education', 'EP', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Guidance and Counselling', 'GC', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Geography Education', 'GE', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Home Economics Education', 'HE', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Human Kinetics', 'HK', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Hausa Education', 'HL', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'History Education', 'HS', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Industrial Technical Education', 'INT', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Integrated Science Education', 'IS', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Islamic Religious Studies Education', 'ISL', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Library and Information Science Education', 'LI', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Mathematics Education', 'MT', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Physics Education', 'PH', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Political Science Education', 'PL', faculty_id FROM faculties WHERE faculty_code = 'FED';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Social Studies Education', 'SS', faculty_id FROM faculties WHERE faculty_code = 'FED';

-- Insert departments for Faculty of Engineering (FEN)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Agricultural and Bio-Resource Engineering', 'AE', faculty_id FROM faculties WHERE faculty_code = 'FEN';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Civil Engineering', 'CE', faculty_id FROM faculties WHERE faculty_code = 'FEN';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Electrical and Electronics Engineering', 'EE', faculty_id FROM faculties WHERE faculty_code = 'FEN';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Mechanical Engineering', 'ME', faculty_id FROM faculties WHERE faculty_code = 'FEN';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Mining and Mineral Processing Engineering', 'MPE', faculty_id FROM faculties WHERE faculty_code = 'FEN';

-- Insert departments for Faculty of Management Sciences (FMS)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Accounting', 'AC', faculty_id FROM faculties WHERE faculty_code = 'FMS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Business Administration', 'BM', faculty_id FROM faculties WHERE faculty_code = 'FMS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Public Administration', 'PB', faculty_id FROM faculties WHERE faculty_code = 'FMS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Tourism Management', 'TR', faculty_id FROM faculties WHERE faculty_code = 'FMS';

-- Insert departments for Faculty of Sciences (FSC)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Biochemistry', 'BCH', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Botany', 'BO', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Biotechnology', 'BTH', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Chemistry', 'CH', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Computer Science', 'CS', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Ecology and Conservation', 'EC', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Industrial Chemistry', 'ICH', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Microbiology', 'MCB', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Mathematics', 'MT', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Physics', 'PH', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Statistics', 'ST', faculty_id FROM faculties WHERE faculty_code = 'FSC';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Zoology', 'ZO', faculty_id FROM faculties WHERE faculty_code = 'FSC';

-- Insert departments for Faculty of Social and Management Sciences (FSMS)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Economics', 'EC', faculty_id FROM faculties WHERE faculty_code = 'FSMS';

-- Insert departments for Faculty of Social Sciences (FSS)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Geography', 'GE', faculty_id FROM faculties WHERE faculty_code = 'FSS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Peace and Conflict Studies', 'PC', faculty_id FROM faculties WHERE faculty_code = 'FSS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Political Science and International Relations', 'PL', faculty_id FROM faculties WHERE faculty_code = 'FSS';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Sociology', 'SO', faculty_id FROM faculties WHERE faculty_code = 'FSS';

-- Insert departments for Faculty of Law (LAW)
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Commercial Law', 'CL', faculty_id FROM faculties WHERE faculty_code = 'LAW';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Islamic Law', 'IL', faculty_id FROM faculties WHERE faculty_code = 'LAW';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Law', 'LLB', faculty_id FROM faculties WHERE faculty_code = 'LAW';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Public Law', 'PL', faculty_id FROM faculties WHERE faculty_code = 'LAW';
INSERT INTO student_departments (department_name, department_code, faculty_id) 
SELECT 'Private and Property Law', 'PP', faculty_id FROM faculties WHERE faculty_code = 'LAW';

-- =====================================================
-- INSERT ALL PROGRAMMES (85+ programmes)
-- =====================================================

-- Faculty of Agriculture programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.AGRIC (Agricultural Economics and Extension)', 'AE', sd.department_id, 'TSU/FAG/AE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AE' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Agricultural Economics', 'AEC', sd.department_id, 'TSU/FAG/AEC/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AE' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Agricultural Extension', 'AEX', sd.department_id, 'TSU/FAG/AEX/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AE' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.AGRIC (Agronomy)', 'AG', sd.department_id, 'TSU/FAG/AG/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AG' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.AGRIC (Animal Science)', 'AS', sd.department_id, 'TSU/FAG/AS/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AS' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.AGRIC (Crop Protection)', 'CP', sd.department_id, 'TSU/FAG/CP/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'CP' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Family and Consumer Science', 'FSC', sd.department_id, 'TSU/FAG/FSC/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'FSC' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.AGRIC (Forestry and Wildlife Management)', 'FW', sd.department_id, 'TSU/FAG/FW/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'FW' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Home Economics', 'HE', sd.department_id, 'TSU/FAG/HE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'HE' AND f.faculty_code = 'FAG';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'Soil Science & Land Resource Management', 'SS', sd.department_id, 'TSU/FAG/SS/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'SS' AND f.faculty_code = 'FAG';

-- Faculty of Health Sciences programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.EHS Environmental Health', 'EH', sd.department_id, 'TSU/FAH/EH/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'EH' AND f.faculty_code = 'FAH';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'BMLS. Medical Laboratory Science', 'ML', sd.department_id, 'TSU/FAH/ML/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'ML' AND f.faculty_code = 'FAH';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'BNSC. Nursing', 'NS', sd.department_id, 'TSU/FAH/NS/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'NS' AND f.faculty_code = 'FAH';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Public Health', 'PU', sd.department_id, 'TSU/FAH/PU/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'PU' AND f.faculty_code = 'FAH';

-- Faculty of Arts programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. Arabic', 'AR', sd.department_id, 'TSU/FART/AR/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AR' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. Christian Religious Studies', 'CR', sd.department_id, 'TSU/FART/CR/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'CR' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. French', 'FL', sd.department_id, 'TSU/FART/FL/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'FL' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. Hausa', 'HL', sd.department_id, 'TSU/FART/HL/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'HL' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A.(HONS) History and Diplomatic Studies', 'HS', sd.department_id, 'TSU/FART/HS/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'HS' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. Islamic Studies', 'IS', sd.department_id, 'TSU/FART/IS/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'IS' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. Linguistics English', 'LE', sd.department_id, 'TSU/FART/LE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'LE' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. English', 'LG', sd.department_id, 'TSU/FART/LG/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'LG' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. Linguistics Hausa', 'LH', sd.department_id, 'TSU/FART/LH/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'LH' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. Linguistic Mumuye', 'MU', sd.department_id, 'TSU/FART/MU/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'MU' AND f.faculty_code = 'FART';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.A. (HONS) Theatre and Film Studies', 'TF', sd.department_id, 'TSU/FART/TF/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'TF' AND f.faculty_code = 'FART';

-- Faculty of Communication and Media Studies programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Advertising', 'AD', sd.department_id, 'TSU/FCMS/AD/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AD' AND f.faculty_code = 'FCMS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Broadcasting', 'BC', sd.department_id, 'TSU/FCMS/BC/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'BC' AND f.faculty_code = 'FCMS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Journalism and Media Studies', 'JM', sd.department_id, 'TSU/FCMS/JM/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'JM' AND f.faculty_code = 'FCMS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.Sc. Mass Communication', 'MC', sd.department_id, 'TSU/FCMS/MC/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'MC' AND f.faculty_code = 'FCMS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Public Relations', 'PR', sd.department_id, 'TSU/FCMS/PR/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'PR' AND f.faculty_code = 'FCMS';

-- Faculty of Education programmes (key ones - full list would be very long)
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.AGRIC (ED) Agricultural Education', 'AE', sd.department_id, 'TSU/FED/AE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AE' AND f.faculty_code = 'FED';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.ED. Educational Administration and Planning', 'AP', sd.department_id, 'TSU/FED/AP/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AP' AND f.faculty_code = 'FED';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. (ED) Business Education', 'BE', sd.department_id, 'TSU/FED/BE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'BE' AND f.faculty_code = 'FED';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. (ED) Computer Science Education', 'CS', sd.department_id, 'TSU/FED/CS/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'CS' AND f.faculty_code = 'FED';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. (ED.) Mathematics', 'MT', sd.department_id, 'TSU/FED/MT/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'MT' AND f.faculty_code = 'FED';

-- Faculty of Engineering programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.ENG. (HONS) Agric and Bio-Resource Engineering', 'AE', sd.department_id, 'TSU/FEN/AE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AE' AND f.faculty_code = 'FEN';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.ENG. (HONS) Civil Engineering', 'CE', sd.department_id, 'TSU/FEN/CE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'CE' AND f.faculty_code = 'FEN';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.ENG. (HONS) Electrical and Electronics Engineering', 'EE', sd.department_id, 'TSU/FEN/EE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'EE' AND f.faculty_code = 'FEN';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.ENG. (HONS) Mechanical Engineering', 'ME', sd.department_id, 'TSU/FEN/ME/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'ME' AND f.faculty_code = 'FEN';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.ENG. (HONS) Mining and Mineral Processing Engineering', 'MPE', sd.department_id, 'TSU/FEN/MPE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'MPE' AND f.faculty_code = 'FEN';

-- Faculty of Management Sciences programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Accounting', 'AC', sd.department_id, 'TSU/FMS/AC/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'AC' AND f.faculty_code = 'FMS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Business Administration', 'BM', sd.department_id, 'TSU/FMS/BM/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'BM' AND f.faculty_code = 'FMS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Public Administration', 'PB', sd.department_id, 'TSU/FMS/PB/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'PB' AND f.faculty_code = 'FMS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Tourism Management', 'TR', sd.department_id, 'TSU/FMS/TR/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'TR' AND f.faculty_code = 'FMS';

-- Faculty of Sciences programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Biochemistry', 'BCH', sd.department_id, 'TSU/FSC/BCH/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'BCH' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Botany', 'BO', sd.department_id, 'TSU/FSC/BO/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'BO' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Biotechnology', 'BTH', sd.department_id, 'TSU/FSC/BTH/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'BTH' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Chemistry', 'CH', sd.department_id, 'TSU/FSC/CH/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'CH' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Computer Science', 'CS', sd.department_id, 'TSU/FSC/CS/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'CS' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Ecology and Conservation', 'EC', sd.department_id, 'TSU/FSC/EC/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'EC' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Industrial Chemistry', 'ICH', sd.department_id, 'TSU/FSC/ICH/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'ICH' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Microbiology', 'MCB', sd.department_id, 'TSU/FSC/MCB/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'MCB' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Mathematics', 'MT', sd.department_id, 'TSU/FSC/MT/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'MT' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Physics', 'PH', sd.department_id, 'TSU/FSC/PH/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'PH' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Statistics', 'ST', sd.department_id, 'TSU/FSC/ST/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'ST' AND f.faculty_code = 'FSC';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Zoology', 'ZO', sd.department_id, 'TSU/FSC/ZO/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'ZO' AND f.faculty_code = 'FSC';

-- Faculty of Social and Management Sciences programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Economics', 'EC', sd.department_id, 'TSU/FSMS/EC/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'EC' AND f.faculty_code = 'FSMS';

-- Faculty of Social Sciences programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Geography', 'GE', sd.department_id, 'TSU/FSS/GE/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'GE' AND f.faculty_code = 'FSS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Peace and Conflict Studies', 'PC', sd.department_id, 'TSU/FSS/PC/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'PC' AND f.faculty_code = 'FSS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Political Science and International Relations', 'PL', sd.department_id, 'TSU/FSS/PL/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'PL' AND f.faculty_code = 'FSS';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'B.SC. Sociology', 'SO', sd.department_id, 'TSU/FSS/SO/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'SO' AND f.faculty_code = 'FSS';

-- Faculty of Law programmes
INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'LLB. Commercial Law', 'CL', sd.department_id, 'TSU/LAW/CL/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'CL' AND f.faculty_code = 'LAW';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'LLB. Islamic Law', 'IL', sd.department_id, 'TSU/LAW/IL/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'IL' AND f.faculty_code = 'LAW';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'LLB. Law', 'LLB', sd.department_id, 'TSU/LAW/LLB/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'LLB' AND f.faculty_code = 'LAW';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'LLB. Public Law', 'PL', sd.department_id, 'TSU/LAW/PL/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'PL' AND f.faculty_code = 'LAW';

INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
SELECT 'LLB. Private and Property Law', 'PP', sd.department_id, 'TSU/LAW/PP/YY/XXXX'
FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
WHERE sd.department_code = 'PP' AND f.faculty_code = 'LAW';

-- =====================================================
-- CREATE DEPARTMENT ACCOUNTS (Password: user2025)
-- =====================================================

INSERT IGNORE INTO users (username, password, full_name, role_id) VALUES
('agex_econ', MD5('user2025'), 'Agric Extension & Economics', 7),
('agron', MD5('user2025'), 'Agronomy', 7),
('anim_sci', MD5('user2025'), 'Animal Science', 7),
('crop_prod', MD5('user2025'), 'Crop Production', 7),
('forest_wild', MD5('user2025'), 'Forestry & Wildlife Conservation', 7),
('home_econ', MD5('user2025'), 'Home Economics', 7),
('soil_res', MD5('user2025'), 'Soil Science & Land Resources Mgmt', 7),
('eng_lit', MD5('user2025'), 'English & Literary Studies', 7),
('theatre_film', MD5('user2025'), 'Theatre & Film Studies', 7),
('french', MD5('user2025'), 'French', 7),
('history', MD5('user2025'), 'History', 7),
('arabic_stu', MD5('user2025'), 'Arabic Studies', 7),
('lang_ling', MD5('user2025'), 'Languages & Linguistic', 7),
('mass_comm', MD5('user2025'), 'Mass Communication', 7),
('arts_edu', MD5('user2025'), 'Arts Education', 7),
('edu_found', MD5('user2025'), 'Educational Foundations', 7),
('counsel_psych', MD5('user2025'), 'Counselling, Educational Psychology & Human Development', 7),
('sci_edu', MD5('user2025'), 'Science Education', 7),
('human_kin', MD5('user2025'), 'Human Kinetics & Physical Education', 7),
('socsci_edu', MD5('user2025'), 'Social Science Education', 7),
('voc_tech', MD5('user2025'), 'Vocational & Technology Education', 7),
('lib_info', MD5('user2025'), 'Library & Info Science', 7),
('abres_eng', MD5('user2025'), 'Agric & Bio-Resources Engineering', 7),
('elec_eng', MD5('user2025'), 'Electrical/Electronics Engineering', 7),
('civil_eng', MD5('user2025'), 'Civil Engineering', 7),
('mech_eng', MD5('user2025'), 'Mechanical Engineering', 7),
('env_health', MD5('user2025'), 'Environmental Health', 7),
('pub_health', MD5('user2025'), 'Public Health', 7),
('nursing', MD5('user2025'), 'Nursing', 7),
('med_lab', MD5('user2025'), 'Medical Lab Science', 7),
('pub_law', MD5('user2025'), 'Public Law', 7),
('priv_prop_law', MD5('user2025'), 'Private & Property Law', 7),
('acct', MD5('user2025'), 'Accounting', 7),
('bus_admin', MD5('user2025'), 'Business Administration', 7),
('pub_admin', MD5('user2025'), 'Public Administration', 7),
('hosp_tour', MD5('user2025'), 'Hospitality & Tourism', 7),
('bio_sci', MD5('user2025'), 'Biological Sciences', 7),
('chem_sci', MD5('user2025'), 'Chemical Sciences', 7),
('math_stats', MD5('user2025'), 'Mathematics & Statistics', 7),
('physics', MD5('user2025'), 'Physics', 7),
('comp_sci', MD5('user2025'), 'Computer Science', 7),
('data_ai', MD5('user2025'), 'Data Science & Artificial Intelligence', 7),
('ict', MD5('user2025'), 'Information & Communication Technology', 7),
('soft_eng', MD5('user2025'), 'Software Engineering', 7),
('econ', MD5('user2025'), 'Economics', 7),
('geog', MD5('user2025'), 'Geography', 7),
('pol_intrel', MD5('user2025'), 'Political & International Relations', 7),
('peace_conf', MD5('user2025'), 'Peace & Conflict Studies', 7),
('sociol', MD5('user2025'), 'Sociology', 7),
('islam_stu', MD5('user2025'), 'Islamic Studies', 7),
('crs', MD5('user2025'), 'CRS', 7);

-- =====================================================
-- SYSTEM SETUP COMPLETE
-- =====================================================

-- Update system version
UPDATE users SET last_login = NULL WHERE username = 'williams';

-- Display setup completion message
SELECT 'TSU ICT Help Desk System Setup Complete!' as message,
       'Version 2.0 with Student Portal' as version,
       'Admin Login: williams/admin123' as admin_credentials,
       'Department Password: user2025' as department_password;