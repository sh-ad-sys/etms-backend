-- Create departments table if it doesn't exist
CREATE TABLE IF NOT EXISTS departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    supervisor_id INT,
    manager_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Create manager_roles table for Manager A & Manager B distinction
CREATE TABLE IF NOT EXISTS manager_roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    manager_type ENUM('operations', 'commercial') NOT NULL COMMENT 'operations = Manager A (Technical), commercial = Manager B (Administrative)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert the 5 departments
INSERT INTO departments (name, description) VALUES
(
    'Production',
    'Operating the rolling machines, cutting sheets to size, and stone-coating.'
),
(
    'Quality Control & Safety',
    'Ensuring the gauge (thickness) is correct and the paint/coating won''t peel, while maintaining factory safety.'
),
(
    'Logistics & Warehousing',
    'Managing the heavy coils of steel and coordinating the delivery trucks (very important for a company that offers "free delivery").'
),
(
    'Sales & Customer Relations',
    'Handling the "mabati showrooms," taking custom orders, and managing the quote-to-payment process.'
),
(
    'Maintenance & Technical Support',
    'Keeping the heavy industrial rollers and machinery running without breakdowns.'
);

-- Add department_id column to users table if it doesn't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS department_id INT AFTER department;
ALTER TABLE users ADD FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;

-- Update the department column to store department names (for backward compatibility)
UPDATE users SET department = 'Production' WHERE department LIKE '%Production%' OR department LIKE '%rolling%';
UPDATE users SET department = 'Quality Control & Safety' WHERE department LIKE '%Quality%' OR department LIKE '%Safety%';
UPDATE users SET department = 'Logistics & Warehousing' WHERE department LIKE '%Logistics%' OR department LIKE '%Warehouse%';
UPDATE users SET department = 'Sales & Customer Relations' WHERE department LIKE '%Sales%' OR department LIKE '%Customer%';
UPDATE users SET department = 'Maintenance & Technical Support' WHERE department LIKE '%Maintenance%' OR department LIKE '%Technical%';
