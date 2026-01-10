# TSU ICT Help Desk System

A comprehensive complaint management system for Taraba State University ICT Help Desk with both staff and student portals.

## Features

### Staff Portal
- Admin dashboard with complaint management
- Department-specific dashboards
- User management and role-based access
- Complaint tracking and status updates
- Export functionality
- Messaging system
- Notifications

### Student Portal
- Student registration with academic information
- Result verification complaint system
- Course-specific complaints (FA, F, Incorrect Grade)
- Real-time status tracking
- Email notifications

## System Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- Mail server configuration (for email features)

## Installation

1. **Database Setup**
   ```sql
   -- Import the comprehensive database structure
   -- Run: FINAL_STUDENT_SYSTEM_UPDATE.sql
   ```

2. **Configuration**
   - Update `config.php` with your database credentials
   - Configure email settings for notifications

3. **File Permissions**
   - Ensure `uploads/` directory is writable
   - Set appropriate permissions for log files

## Default Login Credentials

### Admin Access
- **Username:** williams
- **Password:** admin123

### Department Accounts
- **Username:** [department_code] (e.g., comp_sci, math_stats)
- **Password:** user2025

## University Color Scheme

The system uses TSU's official colors:
- **Primary Blue:** #1e3c72 to #2a5298
- **Secondary Gold:** #f7971e to #ffd200

## File Structure

```
tsuhelpdesk/
├── api/                    # API endpoints
├── assets/                 # Static assets
├── css/                    # Stylesheets
├── includes/               # Shared components
├── js/                     # JavaScript files
├── logs/                   # System logs
├── uploads/                # File uploads
├── index.php               # Homepage with portal selection
├── staff_login.php         # Staff authentication
├── student_portal.php      # Student portal entry
├── admin.php               # Admin dashboard
├── dashboard.php           # Staff dashboard
├── config.php              # Database configuration
└── FINAL_STUDENT_SYSTEM_UPDATE.sql  # Database structure
```

## Registration Number Format

Students' registration numbers follow the format:
`TSU/[FACULTY]/[PROGRAMME]/[YEAR]/[DIGITS]`

Example: `TSU/FSC/CS/24/0001`

## Support

For technical support or system issues, contact the TSU ICT Help Desk team.

## Version

Current Version: 2.0 (with Student Portal)
Last Updated: January 2026

---

**Taraba State University**  
ICT Help Desk System