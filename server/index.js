const express = require('express');
const cors = require('cors');
const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

const app = express();
const PORT = process.env.PORT || 3001;

// Middleware
app.use(cors({
  origin: process.env.ALLOWED_ORIGIN || 'http://localhost:3000',
  credentials: true,
}));
app.use(express.json());

// Database connection pool
const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  database: process.env.DB_NAME || 'etms',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || 'Shadrack2024.',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
});

// Helper function to get user from session cookie
function getAuthUser(req) {
  return {
    id: req.headers['x-user-id'],
    email: req.headers['x-user-email'],
    fullName: req.headers['x-user-name'],
    role: req.headers['x-user-role'] || 'Staff',
  };
}

// ==================== AUTH ROUTES ====================

// Login
app.post('/api/login', async (req, res) => {
  try {
    const { email, password } = req.body;

    if (!email || !password) {
      return res.status(400).json({ success: false, error: 'Email and password required' });
    }

    const [users] = await pool.execute('SELECT * FROM users WHERE email = ?', [email]);

    if (!users.length) {
      return res.status(401).json({ success: false, error: 'Invalid credentials' });
    }

    const user = users[0];
    const passwordValid = await bcrypt.compare(password, user.password);

    if (!passwordValid) {
      return res.status(401).json({ success: false, error: 'Invalid credentials' });
    }

    const [roles] = await pool.execute('SELECT role_name FROM roles WHERE id = ?', [user.role_id]);
    const role = roles[0]?.role_name || 'Staff';

    res.json({
      success: true,
      user: {
        id: user.id,
        email: user.email,
        fullName: user.full_name,
        role: role,
        department: user.department,
        avatar: user.avatar,
      }
    });

  } catch (error) {
    console.error('Login error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Logout
app.post('/api/logout', (req, res) => {
  res.json({ success: true, message: 'Logged out successfully' });
});

// ==================== PROFILE ROUTES ====================

// Get Profile
app.get('/api/profile', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const [users] = await pool.execute(
      'SELECT id, employee_code, full_name, email, phone, department, avatar FROM users WHERE id = ?',
      [user.id]
    );

    if (!users.length) {
      return res.status(404).json({ success: false, error: 'User not found' });
    }

    const userData = users[0];
    res.json({
      success: true,
      user: {
        id: userData.id.toString(),
        employeeCode: userData.employee_code,
        full_name: userData.full_name,
        email: userData.email,
        phone: userData.phone || '',
        department: userData.department || '',
        avatar: userData.avatar || '',
        role: user.role,
      }
    });

  } catch (error) {
    console.error('Get profile error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Update Profile
app.put('/api/profile', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const { full_name, phone, department, avatar } = req.body;

    await pool.execute(
      'UPDATE users SET full_name = ?, phone = ?, department = ?, avatar = ? WHERE id = ?',
      [full_name, phone, department, avatar, user.id]
    );

    res.json({ success: true, message: 'Profile updated successfully' });

  } catch (error) {
    console.error('Update profile error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== NOTIFICATIONS ROUTES ====================

// Get Notifications
app.get('/api/notifications', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const [rows] = await pool.execute(
      `SELECT id, title, message, type, priority, is_read, created_at
       FROM notifications
       WHERE (user_id = ? OR user_id IS NULL)
       ORDER BY created_at DESC
       LIMIT 60`,
      [user.id]
    );

    const notifications = rows.map(row => ({
      id: row.id,
      title: row.title,
      message: row.message,
      type: row.type || 'Alert',
      priority: row.priority || 'Medium',
      isRead: row.is_read || 0,
      createdAt: row.created_at,
    }));

    res.json({ success: true, notifications });

  } catch (error) {
    console.error('Get notifications error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Mark notifications read
app.post('/api/notifications/mark-read', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    await pool.execute(
      `UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0`,
      [user.id]
    );

    res.json({ success: true });

  } catch (error) {
    console.error('Mark notifications read error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== MESSAGES ROUTES ====================

// Get Messages
app.get('/api/messages', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const [rows] = await pool.execute(
      `SELECT m.id, COALESCE(u.full_name, 'Unknown') AS sender, m.message, m.is_read, m.created_at
       FROM messages m
       LEFT JOIN users u ON u.id = m.sender_id
       WHERE m.receiver_id = ?
       ORDER BY m.created_at DESC
       LIMIT 60`,
      [user.id]
    );

    const messages = rows.map(row => ({
      id: row.id,
      sender: row.sender,
      message: row.message,
      isRead: row.is_read || 0,
      createdAt: row.created_at,
    }));

    res.json({ success: true, messages });

  } catch (error) {
    console.error('Get messages error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== ANNOUNCEMENTS ROUTES ====================

// Get Announcements
app.get('/api/announcements', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    try {
      const [rows] = await pool.execute(
        'SELECT id, title, message, created_at FROM announcements ORDER BY created_at DESC LIMIT 40'
      );

      const announcements = rows.map(row => ({
        id: row.id,
        title: row.title,
        message: row.message,
        createdAt: row.created_at,
      }));

      res.json({ success: true, announcements });

    } catch (tableError) {
      res.json({ success: true, announcements: [] });
    }

  } catch (error) {
    console.error('Get announcements error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== TASKS ROUTES ====================

// Get Tasks
app.get('/api/tasks', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const status = req.query.status || 'all';
    let whereClause = 't.assigned_to = ?';
    
    if (status !== 'all') {
      whereClause += ` AND t.completed = ${status === 'completed' ? '1' : '0'}`;
    }

    const [rows] = await pool.execute(
      `SELECT t.id, t.title, t.description, t.due_date, t.category, t.priority, t.completed, t.completed_at, t.created_at, u.full_name AS supervisor_name
       FROM tasks t
       LEFT JOIN users u ON u.id = t.assigned_by
       WHERE ${whereClause}
       ORDER BY t.completed ASC, t.due_date ASC, t.created_at DESC`,
      [user.id]
    );

    const tasks = rows.map(row => ({
      id: row.id,
      title: row.title,
      description: row.description,
      dueDate: row.due_date,
      category: row.category,
      priority: row.priority,
      completed: Boolean(row.completed),
      completedAt: row.completed_at,
      createdAt: row.created_at,
      supervisorName: row.supervisor_name,
    }));

    res.json({ success: true, tasks });

  } catch (error) {
    console.error('Get tasks error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Update Task
app.post('/api/tasks/update', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const { id, completed } = req.body;

    if (!id || id <= 0) {
      return res.status(400).json({ success: false, error: 'Invalid task ID' });
    }

    const completedAt = completed ? new Date().toISOString() : null;

    await pool.execute(
      'UPDATE tasks SET completed = ?, completed_at = ? WHERE id = ? AND assigned_to = ?',
      [completed ? 1 : 0, completedAt, id, user.id]
    );

    res.json({ success: true, message: 'Task updated successfully' });

  } catch (error) {
    console.error('Update task error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== LEAVE ROUTES ====================

// Get Leave Balance
app.get('/api/leave/balance', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const balances = {
      'Annual Leave': 18,
      'Sick Leave': 10,
      'Emergency Leave': 5,
    };

    const [used] = await pool.execute(
      `SELECT leave_type, SUM(DATEDIFF(end_date, start_date) + 1) AS used
       FROM leave_requests
       WHERE user_id = ? AND final_status = 'APPROVED'
       GROUP BY leave_type`,
      [user.id]
    );

    const typeMap = { 'ANNUAL': 'Annual Leave', 'SICK': 'Sick Leave', 'EMERGENCY': 'Emergency Leave' };

    for (const row of used) {
      const label = typeMap[row.leave_type];
      if (label && balances[label] !== undefined) {
        balances[label] = Math.max(0, balances[label] - parseInt(row.used));
      }
    }

    res.json({ success: true, balances });

  } catch (error) {
    console.error('Get leave balance error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Get Leave Status
app.get('/api/leave/status', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const filter = (req.query.status || 'ALL').toUpperCase();
    const allowed = ['ALL', 'PENDING', 'APPROVED', 'REJECTED'];
    const validFilter = allowed.includes(filter) ? filter : 'ALL';

    let whereClause = 'lr.user_id = ?';
    const params = [user.id];

    if (validFilter !== 'ALL') {
      whereClause += ' AND lr.final_status = ?';
      params.push(validFilter);
    }

    const [rows] = await pool.execute(
      `SELECT lr.id, lr.leave_type, lr.start_date, lr.end_date, lr.reason, lr.document_url, 
              lr.supervisor_approval, lr.manager_approval, lr.final_status, lr.created_at,
              DATEDIFF(lr.end_date, lr.start_date) + 1 AS total_days
       FROM leave_requests lr
       WHERE ${whereClause}
       ORDER BY lr.created_at DESC`,
      params
    );

    const typeMap = { 'ANNUAL': 'Annual Leave', 'SICK': 'Sick Leave', 'EMERGENCY': 'Emergency Leave' };

    const requests = rows.map(row => ({
      id: row.id,
      leaveType: typeMap[row.leave_type] || row.leave_type,
      startDate: row.start_date,
      endDate: row.end_date,
      reason: row.reason,
      documentUrl: row.document_url,
      supervisorApproval: row.supervisor_approval,
      managerApproval: row.manager_approval,
      finalStatus: row.final_status,
      createdAt: row.created_at,
      totalDays: row.total_days,
    }));

    res.json({ success: true, requests });

  } catch (error) {
    console.error('Get leave status error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Apply Leave
app.post('/api/leave/apply', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const { leaveType, startDate, endDate, reason } = req.body;

    const typeMap = { 'Annual Leave': 'ANNUAL', 'Sick Leave': 'SICK', 'Emergency Leave': 'EMERGENCY' };

    if (!typeMap[leaveType]) {
      return res.status(400).json({ success: false, error: 'Invalid leave type' });
    }

    if (!startDate || !endDate || !reason) {
      return res.status(400).json({ success: false, error: 'Start date, end date and reason are required' });
    }

    const start = new Date(startDate);
    const end = new Date(endDate);

    if (end < start) {
      return res.status(400).json({ success: false, error: 'End date cannot be before start date' });
    }

    const dbLeaveType = typeMap[leaveType];

    // Check overlapping requests
    const [overlap] = await pool.execute(
      `SELECT COUNT(*) as count FROM leave_requests
       WHERE user_id = ? AND final_status != 'REJECTED' AND start_date <= ? AND end_date >= ?`,
      [user.id, endDate, startDate]
    );

    if (overlap[0].count > 0) {
      return res.status(409).json({ success: false, error: 'You already have a leave request overlapping these dates' });
    }

    await pool.execute(
      `INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, reason, supervisor_approval, manager_approval, final_status, created_at)
       VALUES (?, ?, ?, ?, ?, 'PENDING', 'PENDING', 'PENDING', NOW())`,
      [user.id, dbLeaveType, startDate, endDate, reason]
    );

    res.status(201).json({ success: true, message: 'Leave request submitted successfully' });

  } catch (error) {
    console.error('Apply leave error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== ATTENDANCE ROUTES ====================

// Check In
app.post('/api/attendance/check-in', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ error: 'Not authenticated' });
    }

    const { method, gps } = req.body;

    if (!method || !gps) {
      return res.status(400).json({ error: 'Method and GPS required' });
    }

    const time = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const date = new Date().toISOString().slice(0, 10);

    await pool.execute(
      'INSERT INTO attendance (user_id, date, check_in_time, gps, method, status) VALUES (?, ?, ?, ?, ?, ?)',
      [user.id, date, time, gps, method, 'PRESENT']
    );

    res.json({ message: 'Check-In successful' });

  } catch (error) {
    console.error('Check-in error:', error);
    res.status(500).json({ error: error.message || 'Failed to check in' });
  }
});

// Check Out
app.post('/api/attendance/check-out', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ error: 'Not authenticated' });
    }

    const { gps } = req.body;

    if (!gps) {
      return res.status(400).json({ error: 'GPS required' });
    }

    const time = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const date = new Date().toISOString().slice(0, 10);

    await pool.execute(
      'UPDATE attendance SET check_out_time = ?, gps_checkout = ? WHERE user_id = ? AND date = ? AND check_out_time IS NULL ORDER BY id DESC LIMIT 1',
      [time, gps, user.id, date]
    );

    res.json({ message: 'Check-Out successful' });

  } catch (error) {
    console.error('Check-out error:', error);
    res.status(500).json({ error: error.message || 'Failed to check out' });
  }
});

// ==================== DASHBOARD ROUTES ====================

// Staff Dashboard
app.get('/api/dashboard/staff', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Unauthorized' });
    }

    const [tasks] = await pool.execute(
      'SELECT id, title, description, created_at, completed FROM tasks WHERE assigned_to = ? ORDER BY created_at DESC LIMIT 10',
      [user.id]
    );

    const [notifications] = await pool.execute(
      'SELECT id, title, message, type, priority, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
      [user.id]
    );

    const [leaveUsed] = await pool.execute(
      `SELECT leave_type, SUM(DATEDIFF(end_date, start_date) + 1) AS used FROM leave_requests WHERE user_id = ? AND final_status = 'APPROVED' GROUP BY leave_type`,
      [user.id]
    );

    const balances = { 'ANNUAL': 18, 'SICK': 10, 'EMERGENCY': 5 };
    for (const row of leaveUsed) {
      if (balances[row.leave_type] !== undefined) {
        balances[row.leave_type] = Math.max(0, balances[row.leave_type] - parseInt(row.used));
      }
    }

    const [attendResult] = await pool.execute(
      `SELECT COUNT(*) as total_days, SUM(CASE WHEN status = 'PRESENT' THEN 1 ELSE 0 END) as present_days FROM attendance WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)`,
      [user.id]
    );
    const attendance = attendResult[0] || { total_days: 0, present_days: 0 };

    res.json({
      success: true,
      tasks,
      notifications,
      leaveBalance: balances,
      attendance: { totalDays: attendance.total_days, presentDays: attendance.present_days }
    });

  } catch (error) {
    console.error('Staff dashboard error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// User Stats
app.get('/api/user/stats', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const [attendResult] = await pool.execute(
      `SELECT COUNT(*) as total_days, SUM(CASE WHEN status = 'PRESENT' THEN 1 ELSE 0 END) as present_days, SUM(CASE WHEN status = 'LATE' THEN 1 ELSE 0 END) as late_days, SUM(CASE WHEN status = 'ABSENT' THEN 1 ELSE 0 END) as absent_days FROM attendance WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)`,
      [user.id]
    );
    const attend = attendResult[0] || { total_days: 0, present_days: 0, late_days: 0, absent_days: 0 };

    const [taskResult] = await pool.execute(
      'SELECT COUNT(*) as total_tasks, SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END) as completed_tasks FROM tasks WHERE assigned_to = ?',
      [user.id]
    );
    const tasks = taskResult[0] || { total_tasks: 0, completed_tasks: 0 };

    const [leaveResult] = await pool.execute(
      'SELECT COUNT(*) as total_requests, SUM(CASE WHEN final_status = "APPROVED" THEN 1 ELSE 0 END) as approved_requests, SUM(CASE WHEN final_status = "PENDING" THEN 1 ELSE 0 END) as pending_requests FROM leave_requests WHERE user_id = ?',
      [user.id]
    );
    const leave = leaveResult[0] || { total_requests: 0, approved_requests: 0, pending_requests: 0 };

    res.json({
      success: true,
      stats: {
        attendance: { totalDays: attend.total_days, presentDays: attend.present_days, lateDays: attend.late_days, absentDays: attend.absent_days },
        tasks: { total: tasks.total_tasks, completed: tasks.completed_tasks },
        leave: { total: leave.total_requests, approved: leave.approved_requests, pending: leave.pending_requests }
      }
    });

  } catch (error) {
    console.error('Get user stats error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== SECURITY ROUTES ====================

// Get Devices
app.get('/api/security/devices', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Unauthorized' });
    }

    const [rows] = await pool.execute(
      `SELECT id, device_name, ip_address, user_agent, last_login FROM devices WHERE user_id = ? AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY last_login DESC`,
      [user.id]
    );

    const devices = rows.map(row => {
      const uaLower = (row.user_agent || '').toLowerCase();
      const isMobile = uaLower.includes('android') || uaLower.includes('iphone') || uaLower.includes('mobile');
      return { ...row, type: isMobile ? 'mobile' : 'desktop' };
    });

    res.json({ success: true, devices });

  } catch (error) {
    console.error('Get devices error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Get Alerts
app.get('/api/security/alerts', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Unauthorized' });
    }

    const [rows] = await pool.execute(
      'SELECT id, alert_type, message, severity, is_resolved, created_at FROM security_alerts WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
      [user.id]
    );

    const alerts = rows.map(row => ({
      id: row.id,
      alertType: row.alert_type,
      message: row.message,
      severity: row.severity,
      isResolved: Boolean(row.is_resolved),
      createdAt: row.created_at,
    }));

    res.json({ success: true, alerts });

  } catch (error) {
    console.error('Get alerts error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== ADMIN ROUTES ====================

// Admin Dashboard
app.get('/api/admin/dashboard', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id || user.role !== 'Admin') {
      return res.status(401).json({ success: false, error: 'Unauthorized' });
    }

    const [usersResult] = await pool.execute('SELECT COUNT(*) as total FROM users');
    const totalUsers = usersResult[0]?.total || 0;

    const [attendResult] = await pool.execute('SELECT COUNT(*) as total, SUM(CASE WHEN status = "PRESENT" THEN 1 ELSE 0 END) as present FROM attendance WHERE date = CURDATE()');
    const todayAttendance = attendResult[0] || { total: 0, present: 0 };

    const [leaveResult] = await pool.execute('SELECT COUNT(*) as pending FROM leave_requests WHERE final_status = "PENDING"');
    const pendingLeave = leaveResult[0]?.pending || 0;

    const [activities] = await pool.execute('SELECT id, action, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 10');

    res.json({
      success: true,
      dashboard: { totalUsers, todayAttendance, pendingLeave, activities }
    });

  } catch (error) {
    console.error('Admin dashboard error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Manager Dashboard
app.get('/api/manager/dashboard', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id || !['Manager', 'Admin'].includes(user.role)) {
      return res.status(401).json({ success: false, error: 'Unauthorized' });
    }

    const [attendResult] = await pool.execute('SELECT COUNT(*) as total, SUM(CASE WHEN status = "PRESENT" THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status = "LATE" THEN 1 ELSE 0 END) as late, SUM(CASE WHEN status = "ABSENT" THEN 1 ELSE 0 END) as absent FROM attendance WHERE date = CURDATE()');
    const attendance = attendResult[0] || { total: 0, present: 0, late: 0, absent: 0 };

    const [pendingLeaves] = await pool.execute('SELECT lr.id, lr.user_id, u.full_name, lr.leave_type, lr.start_date, lr.end_date, lr.created_at FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.final_status = "PENDING" ORDER BY lr.created_at DESC LIMIT 10');

    const [tasks] = await pool.execute('SELECT t.id, t.title, t.completed, t.due_date, u.full_name as assigned_to FROM tasks t JOIN users u ON u.id = t.assigned_to ORDER BY t.due_date ASC LIMIT 10');

    res.json({
      success: true,
      dashboard: {
        teamAttendance: attendance,
        pendingLeaves,
        teamTasks: tasks.map(t => ({ ...t, completed: Boolean(t.completed) }))
      }
    });

  } catch (error) {
    console.error('Manager dashboard error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Supervisor Dashboard
app.get('/api/supervisor/dashboard', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id || !['Supervisor', 'Manager', 'Admin'].includes(user.role)) {
      return res.status(401).json({ success: false, error: 'Unauthorized' });
    }

    const [workers] = await pool.execute('SELECT id, full_name, email, department FROM users WHERE role_id IN (SELECT id FROM roles WHERE role_name = "Staff") LIMIT 50');

    const [leaveRequests] = await pool.execute('SELECT lr.id, lr.user_id, u.full_name, lr.leave_type, lr.start_date, lr.end_date, lr.reason, lr.created_at FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.supervisor_approval = "PENDING" ORDER BY lr.created_at DESC LIMIT 20');

    const [tasks] = await pool.execute('SELECT t.id, t.title, t.completed, t.due_date, u.full_name as assigned_to FROM tasks t JOIN users u ON u.id = t.assigned_by WHERE t.assigned_by = ? ORDER BY t.due_date ASC LIMIT 20', [user.id]);

    res.json({
      success: true,
      dashboard: {
        workers,
        leaveRequests,
        teamTasks: tasks.map(t => ({ ...t, completed: Boolean(t.completed) }))
      }
    });

  } catch (error) {
    console.error('Supervisor dashboard error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// HR Dashboard
app.get('/api/hr/dashboard', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id || !['HR', 'Admin'].includes(user.role)) {
      return res.status(401).json({ success: false, error: 'Unauthorized' });
    }

    const [rows] = await pool.execute('SELECT id, full_name, email, phone, department, role_id FROM users WHERE role_id IN (SELECT id FROM roles WHERE role_name IN ("HR", "Admin")) ORDER BY full_name LIMIT 50');

    const profiles = rows.map(row => ({
      id: row.id,
      fullName: row.full_name,
      email: row.email,
      phone: row.phone || '',
      department: row.department || '',
      roleId: row.role_id,
    }));

    res.json({ success: true, profiles });

  } catch (error) {
    console.error('HR dashboard error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== ID ROUTES ====================

// Get ID Status
app.get('/api/id/status', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const [rows] = await pool.execute('SELECT id, id_number, status, issued_date, expiry_date FROM id_cards WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [user.id]);

    if (!rows.length) {
      return res.json({ success: true, idCard: null });
    }

    const idCard = rows[0];
    res.json({
      success: true,
      idCard: {
        id: idCard.id,
        idNumber: idCard.id_number,
        status: idCard.status,
        issuedDate: idCard.issued_date,
        expiryDate: idCard.expiry_date,
      }
    });

  } catch (error) {
    console.error('Get ID status error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// Report Lost ID
app.post('/api/id/report-lost', async (req, res) => {
  try {
    const user = getAuthUser(req);
    if (!user.id) {
      return res.status(401).json({ success: false, error: 'Not authenticated' });
    }

    const { reason } = req.body;

    if (!reason) {
      return res.status(400).json({ success: false, error: 'Reason is required' });
    }

    await pool.execute('INSERT INTO lost_id_reports (user_id, reason, status, created_at) VALUES (?, ?, "PENDING", NOW())', [user.id, reason]);

    res.status(201).json({ success: true, message: 'Lost ID report submitted successfully' });

  } catch (error) {
    console.error('Report lost ID error:', error);
    res.status(500).json({ success: false, error: error.message });
  }
});

// ==================== START SERVER ====================

app.listen(PORT, () => {
  console.log(`ETMS API Server running on port ${PORT}`);
});
