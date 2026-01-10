# Git Repository Setup Guide

## Quick Setup Instructions

### 1. Create GitHub Repository
1. Go to [GitHub.com](https://github.com) and sign in
2. Click "New repository" or go to https://github.com/new
3. Repository name: `tsuhelpdesk`
4. Description: `TSU ICT Help Desk System - Complaint Management System`
5. Set to **Public** or **Private** (your choice)
6. **DO NOT** initialize with README, .gitignore, or license (we already have these)
7. Click "Create repository"

### 2. Connect Local Repository to GitHub
After creating the GitHub repository, run these commands in your project folder:

```bash
# Add the remote repository (replace 'yourusername' with your GitHub username)
git remote add origin https://github.com/yourusername/tsuhelpdesk.git

# Push the initial commit
git push -u origin master
```

### 3. Using the Auto-Update Batch File
Once the remote repository is connected:

1. **Make your changes** to any files in the system
2. **Double-click** `update_repo.bat`
3. The script will automatically:
   - Check for changes
   - Add all modified files
   - Create a commit with timestamp
   - Push to GitHub

### 4. Alternative: Manual Git Commands
If you prefer manual control:

```bash
# Check status
git status

# Add specific files
git add filename.php

# Add all changes
git add .

# Commit with message
git commit -m "Your commit message"

# Push to GitHub
git push origin master
```

## Troubleshooting

### If you get authentication errors:
1. **Personal Access Token** (Recommended):
   - Go to GitHub Settings > Developer settings > Personal access tokens
   - Generate new token with 'repo' permissions
   - Use token as password when prompted

2. **SSH Key** (Alternative):
   - Generate SSH key: `ssh-keygen -t rsa -b 4096 -C "your_email@example.com"`
   - Add to GitHub: Settings > SSH and GPG keys
   - Use SSH URL: `git@github.com:yourusername/tsuhelpdesk.git`

### If the batch file fails:
- Ensure Git is installed and in your system PATH
- Check that you're in the correct directory
- Verify remote repository is configured: `git remote -v`

## Repository Structure
```
tsuhelpdesk/
├── README.md                           # Project documentation
├── .gitignore                          # Git ignore rules
├── update_repo.bat                     # Auto-update script
├── FINAL_STUDENT_SYSTEM_UPDATE.sql     # Database structure
├── config.php                          # Database config (ignored)
├── index.php                           # Homepage
├── admin.php                           # Admin dashboard
├── dashboard.php                       # Staff dashboard
├── student_portal.php                  # Student portal
├── api/                                # API endpoints
├── css/                                # Stylesheets
├── js/                                 # JavaScript files
├── includes/                           # Shared components
├── uploads/                            # File uploads (ignored)
└── logs/                               # System logs (ignored)
```

## Important Notes
- The `config.php` file is ignored to protect database credentials
- Upload directories and logs are ignored for security
- The batch file creates automatic commits with timestamps
- Always test changes locally before pushing to production

---
**Created:** January 2026  
**System:** TSU ICT Help Desk v2.0