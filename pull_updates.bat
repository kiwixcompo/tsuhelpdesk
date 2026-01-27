@echo off
echo ========================================
echo TSU Help Desk System - Pull Updates
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

echo [1/6] Checking current branch...
for /f "tokens=*" %%i in ('git branch --show-current') do set current_branch=%%i
echo Current branch: %current_branch%

echo.
echo [2/6] Checking repository status...
git status --porcelain

echo.
echo [3/6] Stashing any local changes...
git stash push -m "Auto-stash before pull - %date% %time%"

echo.
echo [4/6] Fetching latest changes from remote...
git fetch origin

if errorlevel 1 (
    echo ERROR: Failed to fetch from remote repository
    echo Please check your internet connection
    goto :end
)

echo.
echo [5/6] Pulling latest changes...
git pull origin %current_branch%

if errorlevel 1 (
    echo ERROR: Failed to pull changes
    echo There might be merge conflicts or connection issues
    goto :restore_stash
)

echo.
echo [6/6] Checking if there are stashed changes to restore...
git stash list | findstr "Auto-stash before pull" >nul
if not errorlevel 1 (
    echo Found stashed changes. Attempting to restore...
    git stash pop
    if errorlevel 1 (
        echo WARNING: Could not automatically restore stashed changes
        echo You may need to resolve conflicts manually
        echo Use 'git stash list' to see stashed changes
    ) else (
        echo Stashed changes restored successfully
    )
) else (
    echo No stashed changes to restore
)

echo.
echo ========================================
echo SUCCESS: Repository updated successfully!
echo ========================================
echo.
echo Latest commit:
git log -1 --oneline
goto :end

:restore_stash
echo.
echo Attempting to restore stashed changes...
git stash pop
echo Please resolve any issues and try again

:end
echo.
echo Press any key to exit...
pause >nul