@echo off
chcp 65001 >nul 2>&1
cd /d "%~dp0"

echo ========================================
echo  Git Push - kp-atlant
echo ========================================
echo.

echo [1/4] Status...
echo ----------------------------------------
git status
echo.

echo [2/4] Adding files...
echo ----------------------------------------
git add -A
echo Done.
echo.

echo [3/4] Commit...
echo ----------------------------------------
set /p MSG="Commit message (Enter = auto): "
if "%MSG%"=="" set MSG=update %date% %time%
git commit -m "%MSG%"
echo.

echo [4/4] Push to GitHub...
echo ----------------------------------------
git remote set-url origin https://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@github.com/dansury/kp-atlant.git
git push -u origin main
echo.

echo ========================================
if %ERRORLEVEL%==0 (
    echo  Push OK!
) else (
    echo  Push FAILED! Code: %ERRORLEVEL%
)
echo ========================================
echo.
pause
