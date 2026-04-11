-- Add manager_departments relationship table
CREATE TABLE IF NOT EXISTS manager_departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    manager_id INT NOT NULL,
    department_id INT NOT NULL,
    manager_type ENUM('operations', 'commercial') NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (manager_id, department_id),
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

-- Create audit logs table for tracking supervisor/manager assignments
CREATE TABLE IF NOT EXISTS organization_audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    action_type VARCHAR(50) NOT NULL COMMENT 'supervisor_assigned, supervisor_removed, manager_assigned, manager_removed, department_created, department_updated, department_deleted',
    altered_by INT NOT NULL,
    altered_by_role VARCHAR(50),
    target_user_id INT,
    target_user_name VARCHAR(100),
    target_department_id INT,
    target_department_name VARCHAR(100),
    old_value TEXT,
    new_value TEXT,
    action_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (altered_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (target_department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX (action_date),
    INDEX (altered_by),
    INDEX (target_user_id),
    INDEX (target_department_id)
);

-- Update departments table to have manager_type field
ALTER TABLE departments ADD COLUMN IF NOT EXISTS manager_type ENUM('operations', 'commercial') COMMENT 'Which manager oversees this department';

-- Assign departments to managers based on their responsibilities:
-- Production, Quality Control, Maintenance → Manager A (Operations)
UPDATE departments SET manager_type = 'operations' WHERE name IN ('Production', 'Quality Control & Safety', 'Maintenance & Technical Support');

-- Sales, Logistics → Manager B (Commercial)
UPDATE departments SET manager_type = 'commercial' WHERE name IN ('Sales & Customer Relations', 'Logistics & Warehousing');
