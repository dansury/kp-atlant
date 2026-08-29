@echo off
chcp 65001 >nul 2>&1
cd /d "%~dp0"

set TOKEN=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
set REPO=dansury/kp-atlant
set GH_TOKEN=%TOKEN%

echo ========================================
echo  New PR - kp-atlant
echo ========================================

REM Clean stale git locks
del /q ".git\index.lock" ".git\ORIG_HEAD.lock" >nul 2>&1

REM Stop if a rebase/merge is unfinished
if exist ".git\rebase-merge" goto :busy
if exist ".git\rebase-apply" goto :busy
if exist ".git\MERGE_HEAD" goto :busy

REM Branch name: pr-YYYYMMDD-HHMMSS
for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set BR=pr-%%I
set MSG=update %BR%

git remote set-url origin https://%TOKEN%@github.com/%REPO%.git

echo [1/4] Branch %BR%
git checkout -b %BR% || goto :fail

echo [2/4] Commit
git add -A
git commit -m "%MSG%" || echo (nothing to commit)

echo [3/4] Push
git push -u origin %BR% || goto :fail

echo [4/4] Pull request
gh pr create --repo %REPO% --base main --head %BR% --title "%MSG%" --body "Auto PR from push.bat" || goto :nogh

git checkout main
echo.
echo  PR created. Local branch back on main.
pause
exit /b 0

:busy
echo.
echo  Unfinished rebase/merge in repo. Fix it first:
echo    git rebase --abort   /   git merge --abort
pause
exit /b 1

:nogh
echo.
echo  gh CLI failed. Open PR manually:
echo    https://github.com/%REPO%/compare/main...%BR%?expand=1
pause
exit /b 1

:fail
echo.
echo  FAILED. Code: %ERRORLEVEL%
pause
exit /b 1
