@echo off
chcp 65001 >nul
title TSU ICT Help Desk - Sync and Deploy
color 0A
echo.
echo  ╔══════════════════════════════════════════════════════════════╗
echo  ║              TSU ICT HELP DESK                               ║
echo  ║              SYNC AND DEPLOY TOOL                            ║
echo  ╚══════════════════════════════════════════════════════════════╝
echo.
echo  Repository: https://github.com/kiwixcompo/tsuhelpdesk
echo  Live Site:  https://helpdesk.tsuniversity.ng
echo  ══════════════════════════════════════════════════════════════
echo.

cd /d "%~dp0"

REM ── Check git ────────────────────────────────────────────
git --version >nul 2>&1
if errorlevel 1 (
    echo  [ERROR] Git is not installed or not in PATH.
    pause
    exit /b 1
)

REM ── Check curl ───────────────────────────────────────────
curl --version >nul 2>&1
if errorlevel 1 (
    echo  [ERROR] curl not found. Install Git for Windows which includes curl.
    pause
    exit /b 1
)

REM ════════════════════════════════════════════════════════
REM  STEP 1: Commit local changes
REM ════════════════════════════════════════════════════════
echo  [1/4] Checking for local changes...
git add .

git diff --cached --quiet
if errorlevel 1 (
    for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value 2^>nul') do set "dt=%%a"
    set "timestamp=%dt:~0,4%-%dt:~4,2%-%dt:~6,2% %dt:~8,2%:%dt:~10,2%"
    echo  Committing changes...
    git commit -m "Auto-update: %timestamp%"
    if errorlevel 1 (
        echo  [ERROR] Commit failed.
        pause
        exit /b 1
    )
    echo  [OK] Changes committed.
) else (
    echo  [OK] No local changes to commit.
)
echo.

REM ════════════════════════════════════════════════════════
REM  STEP 2: Push to GitHub
REM ════════════════════════════════════════════════════════
echo  [2/4] Pushing to GitHub...
git push origin main
if errorlevel 1 (
    echo  [ERROR] Push to GitHub failed.
    pause
    exit /b 1
)
echo  [OK] Pushed to GitHub.
echo.

REM ════════════════════════════════════════════════════════
REM  STEP 3: Tell cPanel to pull from GitHub
REM  Uses cPanel UAPI with basic auth (username:password)
REM  Set your cPanel password below.
REM ════════════════════════════════════════════════════════
echo  [3/4] Triggering cPanel "Update from Remote"...

set "CPANEL_USER=tsuniver"
set "CPANEL_PASS=YOUR_CPANEL_PASSWORD_HERE"
set "REPO_ROOT=/home/tsuniver/repositories/tsuhelpdesk"

curl -s -k -u "%CPANEL_USER%:%CPANEL_PASS%" ^
     "https://helpdesk.tsuniversity.ng:2083/execute/VersionControl/update?repository_root=%REPO_ROOT%" ^
     -o cpanel_pull.tmp

findstr /i "\"errors\"\:\[\]" cpanel_pull.tmp >nul 2>&1
if errorlevel 1 (
    echo  [WARNING] cPanel pull response unexpected. Check cpanel_pull.tmp
    echo  You may need to set your cPanel password in this bat file.
    type cpanel_pull.tmp
) else (
    echo  [OK] cPanel pulled latest code from GitHub.
    del cpanel_pull.tmp >nul 2>&1
)
echo.

REM Wait for pull to complete
timeout /t 8 /nobreak >nul

REM ════════════════════════════════════════════════════════
REM  STEP 4: Trigger cPanel "Deploy HEAD Commit"
REM  This runs the .cpanel.yml tasks (cp -rf repo to webroot)
REM ════════════════════════════════════════════════════════
echo  [4/4] Triggering cPanel "Deploy HEAD Commit"...

curl -s -k -u "%CPANEL_USER%:%CPANEL_PASS%" ^
     "https://helpdesk.tsuniversity.ng:2083/execute/VersionControl/retrieve_repositories" ^
     -o cpanel_repos.tmp >nul 2>&1

REM Extract the repository clone_url to find the repo_id
REM Then trigger deploy — cPanel deploy endpoint
curl -s -k -u "%CPANEL_USER%:%CPANEL_PASS%" ^
     "https://helpdesk.tsuniversity.ng:2083/execute/VersionControlDeployment/create?repository_root=%REPO_ROOT%" ^
     -o cpanel_deploy.tmp

findstr /i "\"errors\"\:\[\]" cpanel_deploy.tmp >nul 2>&1
if errorlevel 1 (
    echo  [WARNING] Deploy trigger response unexpected. Check cpanel_deploy.tmp
    type cpanel_deploy.tmp
    echo.
    echo  If this keeps failing, open cPanel ^> Git Version Control ^> Deploy HEAD Commit manually.
) else (
    echo  [OK] Deployment triggered successfully.
    del cpanel_deploy.tmp >nul 2>&1
)
del cpanel_repos.tmp >nul 2>&1

echo.
echo  ══════════════════════════════════════════════════════════════
echo  Done! Live site: https://helpdesk.tsuniversity.ng
echo  ══════════════════════════════════════════════════════════════
echo.
pause
