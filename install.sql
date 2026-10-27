CREATE DATABASE IF NOT EXISTS cycle_exam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cycle_exam;

CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 login_id VARCHAR(100) NOT NULL UNIQUE,
 display_name VARCHAR(180) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('super_admin','exam_admin','teacher','student') NOT NULL DEFAULT 'student',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 must_change_password TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_users_role_active(role,is_active),
 INDEX idx_users_name(display_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 department_name VARCHAR(160) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_profiles (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 register_number VARCHAR(100) NOT NULL UNIQUE,
 department_id INT UNSIGNED NULL,
 year_level VARCHAR(40) NULL,
 section_name VARCHAR(80) NULL,
 course_name VARCHAR(160) NULL,
 semester VARCHAR(40) NULL,
 email VARCHAR(190) NULL,
 phone VARCHAR(40) NULL,
 CONSTRAINT fk_profile_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_profile_department FOREIGN KEY(department_id) REFERENCES departments(id) ON DELETE SET NULL,
 INDEX idx_profile_group(department_id,year_level,section_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subjects (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subject_name VARCHAR(180) NOT NULL,
 subject_code VARCHAR(80) NULL UNIQUE,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subject_id INT UNSIGNED NOT NULL,
 question_text TEXT NOT NULL,
 option_a TEXT NOT NULL,
 option_b TEXT NOT NULL,
 option_c TEXT NOT NULL,
 option_d TEXT NOT NULL,
 correct_option ENUM('A','B','C','D') NOT NULL,
 marks DECIMAL(8,2) NOT NULL DEFAULT 1.00,
 is_archived TINYINT(1) NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_question_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
 CONSTRAINT fk_question_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_questions_subject(subject_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exams (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(220) NOT NULL,
 subject_id INT UNSIGNED NOT NULL,
 exam_pattern ENUM('mcq','typing','mixed') NOT NULL DEFAULT 'mcq',
 start_at DATETIME NOT NULL,
 end_at DATETIME NOT NULL,
 duration_minutes INT UNSIGNED NOT NULL,
 total_marks DECIMAL(8,2) NOT NULL,
 pass_marks DECIMAL(8,2) NOT NULL DEFAULT 0,
 department_id INT UNSIGNED NULL,
 year_level VARCHAR(40) NULL,
 status ENUM('draft','scheduled','active','closed','evaluated','published') NOT NULL DEFAULT 'scheduled',
 is_archived TINYINT(1) NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_exam_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
 CONSTRAINT fk_exam_department FOREIGN KEY(department_id) REFERENCES departments(id) ON DELETE SET NULL,
 CONSTRAINT fk_exam_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_exam_schedule(status,start_at,end_at),
 INDEX idx_exam_subject(subject_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS descriptive_questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 exam_id BIGINT UNSIGNED NOT NULL,
 subject_id INT UNSIGNED NOT NULL,
 question_number INT UNSIGNED NOT NULL,
 question_text TEXT NOT NULL,
 max_marks DECIMAL(8,2) NOT NULL,
 accepted_answers JSON NOT NULL,
 keyword_rules JSON NOT NULL,
 numeric_tolerance DECIMAL(12,4) NULL,
 ignore_punctuation TINYINT(1) NOT NULL DEFAULT 1,
 spelling_tolerance TINYINT UNSIGNED NOT NULL DEFAULT 0,
 is_archived TINYINT(1) NOT NULL DEFAULT 0,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_descriptive_exam FOREIGN KEY(exam_id) REFERENCES exams(id) ON DELETE RESTRICT,
 CONSTRAINT fk_descriptive_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
 CONSTRAINT fk_descriptive_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 UNIQUE KEY uq_descriptive_exam_number(exam_id,question_number),
 INDEX idx_descriptive_exam_subject(exam_id,subject_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exam_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 exam_id BIGINT UNSIGNED NOT NULL,
 student_user_id BIGINT UNSIGNED NOT NULL,
 status ENUM('in_progress','submitted','evaluated','cancelled') NOT NULL DEFAULT 'in_progress',
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 submitted_at DATETIME NULL,
 marks_obtained DECIMAL(8,2) NULL,
 max_marks DECIMAL(8,2) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_attempt_exam FOREIGN KEY(exam_id) REFERENCES exams(id) ON DELETE RESTRICT,
 CONSTRAINT fk_attempt_student FOREIGN KEY(student_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 UNIQUE KEY uq_attempt_exam_student(exam_id,student_user_id),
 INDEX idx_attempt_student(student_user_id,status),
 INDEX idx_attempt_exam_status(exam_id,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attempt_answers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 attempt_id BIGINT UNSIGNED NOT NULL,
 question_id BIGINT UNSIGNED NOT NULL,
 selected_option ENUM('A','B','C','D') NULL,
 is_correct TINYINT(1) NULL,
 marks_awarded DECIMAL(8,2) NOT NULL DEFAULT 0,
 is_flagged TINYINT(1) NOT NULL DEFAULT 0,
 saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_answer_attempt FOREIGN KEY(attempt_id) REFERENCES exam_attempts(id) ON DELETE RESTRICT,
 CONSTRAINT fk_answer_question FOREIGN KEY(question_id) REFERENCES questions(id) ON DELETE RESTRICT,
 UNIQUE KEY uq_attempt_question(attempt_id,question_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS descriptive_answers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 attempt_id BIGINT UNSIGNED NOT NULL,
 descriptive_question_id BIGINT UNSIGNED NOT NULL,
 answer_text LONGTEXT NOT NULL,
 marks_awarded DECIMAL(8,2) NULL,
 marking_status ENUM('pending_review','auto_marked','teacher_reviewed') NOT NULL DEFAULT 'pending_review',
 matched_keywords JSON NULL,
 marking_explanation TEXT NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 review_reason TEXT NULL,
 saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_descriptive_answer_attempt FOREIGN KEY(attempt_id) REFERENCES exam_attempts(id) ON DELETE RESTRICT,
 CONSTRAINT fk_descriptive_answer_question FOREIGN KEY(descriptive_question_id) REFERENCES descriptive_questions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_descriptive_answer_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
 UNIQUE KEY uq_descriptive_attempt_question(attempt_id,descriptive_question_id),
 INDEX idx_descriptive_answer_status(marking_status,descriptive_question_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS descriptive_answer_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 descriptive_answer_id BIGINT UNSIGNED NOT NULL,
 reviewer_user_id BIGINT UNSIGNED NOT NULL,
 previous_marks DECIMAL(8,2) NULL,
 awarded_marks DECIMAL(8,2) NOT NULL,
 reason TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_descriptive_review_answer FOREIGN KEY(descriptive_answer_id) REFERENCES descriptive_answers(id) ON DELETE RESTRICT,
 CONSTRAINT fk_descriptive_review_user FOREIGN KEY(reviewer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 INDEX idx_descriptive_review_answer(descriptive_answer_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS results (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 exam_id BIGINT UNSIGNED NOT NULL,
 student_user_id BIGINT UNSIGNED NOT NULL,
 attempt_id BIGINT UNSIGNED NOT NULL UNIQUE,
 marks_obtained DECIMAL(8,2) NOT NULL DEFAULT 0,
 max_marks DECIMAL(8,2) NOT NULL DEFAULT 0,
 percentage DECIMAL(6,2) NOT NULL DEFAULT 0,
 is_published TINYINT(1) NOT NULL DEFAULT 0,
 published_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_result_exam FOREIGN KEY(exam_id) REFERENCES exams(id) ON DELETE RESTRICT,
 CONSTRAINT fk_result_student FOREIGN KEY(student_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_result_attempt FOREIGN KEY(attempt_id) REFERENCES exam_attempts(id) ON DELETE RESTRICT,
 UNIQUE KEY uq_result_exam_student(exam_id,student_user_id),
 INDEX idx_result_published(is_published,exam_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(100) PRIMARY KEY,
 setting_value MEDIUMTEXT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_user_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 target_type VARCHAR(80) NULL,
 target_id VARCHAR(100) NULL,
 metadata JSON NULL,
 ip_address VARCHAR(45) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_audit_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
 INDEX idx_audit_created(created_at),
 INDEX idx_audit_action(action)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ip_address VARCHAR(45) NOT NULL,
 login_id VARCHAR(100) NOT NULL,
 succeeded TINYINT(1) NOT NULL DEFAULT 0,
 attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_login_attempts(ip_address,attempted_at,succeeded)
) ENGINE=InnoDB;

INSERT INTO settings(setting_key,setting_value) VALUES
('institution_name','College Cycle Test Portal'),
('portal_description','Monthly cycle test examination portal'),
('primary_color','#e31825'),
('accent_color','#ffc20e'),
('footer_text','Powered by College Cycle Test Portal'),
('privacy_policy','This portal uses student information only for authorized academic examination purposes. Contact the institution for questions about data handling.'),
('exam_rules','Use your own login. Read each question carefully. Do not refresh repeatedly or close the browser during an exam. Submit before the timer expires. Contact the examination office if a technical issue occurs.')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
