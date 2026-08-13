-- CareerPath AI — Migration 10
-- Backs three Software Development items from the Gantt chart that weren't
-- built yet: Consultation Request & Appointment Scheduling, the Notification
-- Module, and the Administrator Module's System Settings piece.
-- NOTE: Consultation Scheduling, Notifications, and System Settings are not
-- named entities/use cases in the capstone paper's ERD or Use Case Diagram —
-- built anyway per direct instruction, since they're on the group's Gantt
-- chart. See README for the full paper-alignment note.

CREATE TABLE IF NOT EXISTS consultations (
    consultation_id  INT AUTO_INCREMENT PRIMARY KEY,
    student_id       INT NOT NULL,
    counselor_id     INT NULL,      -- assigned when a counselor/admin schedules it
    reason           TEXT NULL,     -- optional note from the student on request
    preferred_date   DATE NULL,
    preferred_time   TIME NULL,
    status           ENUM('pending','scheduled','completed','cancelled') NOT NULL DEFAULT 'pending',
    scheduled_date   DATE NULL,
    scheduled_time   TIME NULL,
    counselor_notes  TEXT NULL,
    requested_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (counselor_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notifications (
    notification_id  INT AUTO_INCREMENT PRIMARY KEY,
    audience         ENUM('student','staff') NOT NULL,
    student_id       INT NULL,  -- set when audience = 'student'
    user_id          INT NULL,  -- set when audience = 'staff' targets one account; NULL = any counselor/admin
    message          VARCHAR(255) NOT NULL,
    link             VARCHAR(255) NULL,
    is_read          BOOLEAN NOT NULL DEFAULT FALSE,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key    VARCHAR(100) PRIMARY KEY,
    setting_value  VARCHAR(255) NOT NULL,
    description    VARCHAR(255) NULL,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('recommendation_count', '5', 'How many careers the matching engine returns per assessment (Top-N).'),
    ('site_name', 'CareerPath AI', 'Display name shown in page titles and nav bars.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
