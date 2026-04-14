@echo off
title TSU ICT Help Desk - Auto-Deploy Sync
color 0A
echo.
echo  ╔══════════════════════════════════════════════════════════════╗
echo  ║              TSU ICT HELP DESK                               ║
echo  ║              AUTO-DEPLOY SYNC TOOL                           ║
echo  ╚══════════════════════════════════════════════════════════════╝
echo.
echo  Repository: https://github.com/kiwixcompo/tsuhelpdesk
echo  Live Site:  https://helpdesk.tsuniversity.ng
echo  ══════════════════════════════════════════════════════════════
echo.

cd /d "%~dp0"

REM ── Step 1: Check git is available ──────────────────────
git --version >nul 2>&1
if errorlevel 1 (
    echo  [ERROR] Git is not installed or not in PATH.
    echo  Download from: https://git-scm.com/download/win
    pause
    exit /b 1
)

REM ── Step 2: Pull latest from GitHub ─────────────────────
echo  [1/4] Pulling latest from GitHub...
git pull origin main --no-edit
if errorlevel 1 (
    echo  [WARNING] Pull had conflicts or failed. Continuing...
)
echo.

REM ── Step 3: Stage and commit local changes ──────────────
echo  [2/4] Checking for local changes...
git add .

git diff --cached --quiet
if errorlevel 1 (
    REM Get timestamp
    for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set "dt=%%a"
    set "timestamp=%dt:~0,4%-%dt:~4,2%-%dt:~6,2% %dt:~8,2%:%dt:~10,2%"

    echo  [3/4] Committing and pushing changes...
    git commit -m "Auto-update: %timestamp%"
    git push origin main
    if errorlevel 1 (
        echo.
        echo  [ERROR] Push to GitHub failed.
        echo  Check your internet connection or GitHub credentials.
        echo.
        pause
        exit /b 1
    )
    echo  [OK] Changes pushed to GitHub successfully.
) else (
    echo  [OK] No local changes to commit.
)
echo.

REM ── Step 4: Trigger live deployment ─────────────────────
echo  [4/4] Triggering deployment to live server...
echo.

REM Check if curl is available
curl --version >nul 2>&1
if errorlevel 1 (
    echo  [WARNING] curl not found. Cannot auto-deploy.
    echo  Manually visit to deploy:
    echo  https://helpdesk.tsuniversity.ng/cpanel_deploy.php?key=DEPLOY_TSU_2026
    echo.
    goto :done
)

REM Trigger the deploy script and capture response
curl -s -o deploy_response.tmp -w "%%{http_code}" "https://helpdesk.tsuniversity.ng/cpanel_deploy.php?key=DEPLOY_TSU_2026" > deploy_status.tmp 2>&1

set /p HTTP_CODE=<deploy_status.tmp

if "%HTTP_CODE%"=="200" (
    echo  [OK] Deployment triggered successfully! HTTP 200
    echo  Files are now live at: https://helpdesk.tsuniversity.ng
) else if "%HTTP_CODE%"=="403" (
    echo  [ERROR] Deploy script returned 403 Forbidden.
    echo  The deploy key may be wrong or cpanel_deploy.php is not on the server.
    echo  Upload cpanel_deploy.php via cPanel File Manager first.
) else if "%HTTP_CODE%"=="000" (
    echo  [ERROR] Could not reach the server. Check your internet or the domain.
    echo  You can deploy manually by visiting:
    echo  https://helpdesk.tsuniversity.ng/cpanel_deploy.php?key=DEPLOY_TSU_2026
) else (
    echo  [WARNING] Server returned HTTP %HTTP_CODE%
    echo  Check the response in deploy_response.tmp for details.
)

REM Cleanup temp files
del deploy_response.tmp >nul 2>&1
del deploy_status.tmp >nul 2>&1

:done
echo.
echo  ══════════════════════════════════════════════════════════════
echo  Done! Repository: https://github.com/kiwixcompo/tsuhelpdesk
echo  ══════════════════════════════════════════════════════════════
echo.
pause
