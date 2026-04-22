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
echo  [1/3] Checking for local changes...
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
echo  [2/3] Pushing to GitHub...
git push origin main
if errorlevel 1 (
    echo  [ERROR] Push to GitHub failed.
    pause
    exit /b 1
)
echo  [OK] Pushed to GitHub.
echo.

REM ════════════════════════════════════════════════════════
REM  STEP 3: Call git_pull.php to copy files to web root
REM  This script copies all app files from the server repo
REM  to the live web root, preserving config.php
REM ════════════════════════════════════════════════════════
echo  [3/3] Deploying files to live web root...
echo.

curl -s --max-time 60 "https://helpdesk.tsuniversity.ng/git_pull.php?key=DEPLOY_TSU_2026"

echo.
echo.
echo  ══════════════════════════════════════════════════════════════
echo  Done! Live site: https://helpdesk.tsuniversity.ng
echo  ══════════════════════════════════════════════════════════════
echo.
pause
