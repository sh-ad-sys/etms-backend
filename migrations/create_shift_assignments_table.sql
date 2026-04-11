-- Create shift_assignments table if it doesn't exist
CREATE TABLE IF NOT EXISTS `shift_assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `monday_shift` VARCHAR(50) DEFAULT 'Morning Shift',
  `tuesday_shift` VARCHAR(50) DEFAULT 'Morning Shift',
  `wednesday_shift` VARCHAR(50) DEFAULT 'Morning Shift',
  `thursday_shift` VARCHAR(50) DEFAULT 'Morning Shift',
  `friday_shift` VARCHAR(50) DEFAULT 'Morning Shift',
  `saturday_shift` VARCHAR(50) DEFAULT 'Morning Shift',
  `sunday_shift` VARCHAR(50) DEFAULT 'Morning Shift',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;