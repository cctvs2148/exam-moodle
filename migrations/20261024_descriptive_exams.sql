ALTER TABLE exams
 ADD COLUMN exam_pattern ENUM('mcq','typing','mixed') NOT NULL DEFAULT 'mcq' AFTER subject_id;

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
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_descriptive_exam FOREIGN KEY(exam_id) REFERENCES exams(id) ON DELETE RESTRICT,
 CONSTRAINT fk_descriptive_subject FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
 CONSTRAINT fk_descriptive_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
 UNIQUE KEY uq_descriptive_exam_number(exam_id,question_number),
 INDEX idx_descriptive_exam_subject(exam_id,subject_id)
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
