-- MoviePro admin login repair for an already imported database.
-- Run this file once in phpMyAdmin. It does not delete any project data.

USE moviepro_db;

INSERT INTO users
    (username, email, password, full_name, phone, city, preferred_language,
     preferred_genres, profile_completed, role, is_active)
VALUES
    ('admin', 'admin@moviepro.com',
     '$2y$12$QxVjJLcoEt5y6j7ukVOhy.EXxUkDh8vsfVRMfJh.jegIlWOMqxopi',
     'MoviePro Administrator', '0300-0000000', 'Karachi', 'English',
     'Action, Drama, Sci-Fi', 1, 'admin', 1)
ON DUPLICATE KEY UPDATE
    username = 'admin',
    password = VALUES(password),
    full_name = 'MoviePro Administrator',
    role = 'admin',
    is_active = 1;

SELECT email, role, is_active
FROM users
WHERE email = 'admin@moviepro.com';
