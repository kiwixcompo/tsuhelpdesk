@echo off
echo ========================================
echo TSU Help Desk System - Git Auto Update
echo ========================================
echo.

:: Check if Git is available
git --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Git is not installed or not in PATH
    echo Please install Git and try again
    pause
    exit /b 1
)

:: Check if we're in a Git repository
if not exist ".git" (
    echo ERROR: This is not a Git repository
    echo Please run 'git init' first
    pause
    exit /b 1
)

echo [1/5] Checking repository status...
git status --porcelain

echo.
echo [2/5] Adding all changes to staging...
git add .

echo.
echo [3/5] Creating commit with timestamp...
for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set "dt=%%a"
set "YY=%dt:~2,2%" & set "YYYY=%dt:~0,4%" & set "MM=%dt:~4,2%" & set "DD=%dt:~6,2%"
set "HH=%dt:~8,2%" & set "Min=%dt:~10,2%" & set "Sec=%dt:~12,2%"
set "timestamp=%YYYY%-%MM%-%DD% %HH%:%Min%:%Sec%"

git commit -m "Auto-update: %timestamp%"

if errorlevel 1 (
    echo No changes to commit
    echo Repository is up to date
    goto :end
)

echo.
echo [4/5] Checking remote repository...
git remote -v
if errorlevel 1 (
    echo WARNING: No remote repository configured
    echo To add a remote repository, run:
    echo git remote add origin https://github.com/yourusername/tsuhelpdesk.git
    echo.
    goto :end
)

echo.
echo [5/5] Pushing changes to remote repository...
git push origin main

if errorlevel 1 (
    echo.
    echo Trying to push to 'master' branch instead...
    git push origin master
    
    if errorlevel 1 (
        echo.
        echo ERROR: Failed to push to remote repository
        echo This might be the first push. Try running:
        echo git push -u origin main
        echo.
        goto :end
    )
)

echo.
echo ========================================
echo SUCCESS: Repository updated successfully!
echo ========================================

:end
echo.
echo Press any key to exit...
pause >nul