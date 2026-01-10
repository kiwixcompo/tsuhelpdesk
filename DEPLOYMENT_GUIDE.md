# TSU ICT Help Desk - Deployment Guide

## 🚀 Complete System Setup & Deployment

This guide covers setting up the TSU ICT Help Desk System from scratch on a new server and configuring automated deployments.

## 📋 Prerequisites

### Server Requirements
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher  
- **Web Server**: Apache or Nginx
- **Git**: For automated deployments
- **Mail Server**: For email notifications

### Required PHP Extensions
- mysqli
- pdo_mysql
- mbstring
- openssl
- curl
- gd (for image handling)

## 🗄️ Database Setup

### Option 1: Complete Fresh Installation
Use the comprehensive setup SQL file that includes everything:

```sql
-- Import the complete system setup
mysql -u username -p database_name < COMPLETE_SYSTEM_SETUP.sql
```

### Option 2: Existing System Update
If you already have the system and just need student portal updates:

```sql
-- Import only the student system updates
mysql -u username -p database_name < FINAL_STUDENT_SYSTEM_UPDATE.sql
```

### Default Credentials After Setup
- **Admin**: `williams` / `admin123`
- **Department Accounts**: `[department_code]` / `user2025`

## ⚙️ Configuration

### 1. Database Configuration
Create/update `config.php`:

```php
<?php
// Database configuration
$servername = "localhost";
$username = "your_db_username";
$password = "your_db_password";
$dbname = "your_database_name";

// Email configuration
$smtp_host = "your_smtp_host";
$smtp_username = "your_email@domain.com";
$smtp_password = "your_email_password";
$smtp_port = 587;

// System settings
$system_url = "https://yourdomain.com";
$system_name = "TSU ICT Help Desk";
?>
```

### 2. File Permissions
Set proper permissions for web server:

```bash
# Make directories writable
chmod 755 uploads/ logs/ backups/
chmod 644 *.php
chmod 600 config.php
```

### 3. Web Server Configuration

#### Apache (.htaccess)
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"

# Hide sensitive files
<Files "config.php">
    Order allow,deny
    Deny from all
</Files>

<Files "deploy.php">
    Order allow,deny
    Deny from all
</Files>
```

#### Nginx
```nginx
location ~ /(config|deploy)\.php$ {
    deny all;
    return 404;
}

location /uploads/ {
    location ~ \.php$ {
        deny all;
        return 404;
    }
}
```

## 🔄 Automated Deployment Setup

### 1. Configure Deployment Script
Edit `deploy.php` and change the secret key:

```php
// Change this to a strong, unique secret key
define('SECRET_KEY', 'your_very_secure_secret_key_here');
```

### 2. Deployment URL
Access the deployment interface at:
```
https://yourdomain.com/deploy.php?key=your_very_secure_secret_key_here
```

### 3. GitHub Webhook (Optional)
For automatic deployments on push, set up a GitHub webhook:

1. Go to your repository settings
2. Add webhook: `https://yourdomain.com/deploy.php?key=your_secret_key`
3. Set content type to `application/json`
4. Select "Just the push event"

### 4. Security Considerations
- **Change the secret key** in `deploy.php`
- **Restrict access** to `deploy.php` via IP whitelist if possible
- **Monitor deployment logs** in `/logs/deploy.log`
- **Regular backups** are created automatically during deployment

## 📁 Directory Structure

```
tsuhelpdesk/
├── api/                    # API endpoints
├── assets/                 # Static assets  
├── backups/               # Automatic backups (created by deploy.php)
├── css/                   # Stylesheets
├── includes/              # Shared components
├── js/                    # JavaScript files
├── logs/                  # System and deployment logs
├── uploads/               # File uploads
├── config.php             # Database configuration (create manually)
├── deploy.php             # Deployment script
├── index.php              # Homepage with portal selection
├── COMPLETE_SYSTEM_SETUP.sql  # Complete database setup
└── FINAL_STUDENT_SYSTEM_UPDATE.sql  # Student system updates only
```

## 🎨 Color Scheme

The system now uses a professional blue color scheme:

- **Primary Blue**: `#1e3c72` to `#2a5298`
- **Secondary Blue**: `#4a90e2`
- **Accent Blue**: `#6bb6ff`
- **Light Blue**: `#e8f4fd`

## 🔧 Maintenance

### Regular Tasks
1. **Monitor logs**: Check `/logs/` directory regularly
2. **Database backups**: Automated during deployments
3. **Update dependencies**: Keep PHP and MySQL updated
4. **Security updates**: Monitor for system updates

### Troubleshooting
- **Deployment fails**: Check `/logs/deploy.log`
- **Database errors**: Verify `config.php` settings
- **Permission issues**: Ensure proper file permissions
- **Email not working**: Check SMTP configuration

## 📞 Support

For technical support:
- Check system logs in `/logs/`
- Review deployment logs for issues
- Ensure all prerequisites are met
- Verify file permissions and configuration

## 🔄 Update Process

### Manual Updates
1. Make changes locally
2. Run `update_repo.bat` (Windows) or commit manually
3. Access deployment URL to pull changes

### Automatic Updates
1. Push changes to GitHub
2. Webhook triggers deployment automatically
3. System updates with preserved configuration

---

**TSU ICT Help Desk System v2.0**  
*Professional Blue Theme Edition*  
*January 2026*