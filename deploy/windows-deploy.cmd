@echo off
REM ============================================================
REM  藍新金流付款閘道 — Windows 自動部署（雙擊執行版）
REM  1) 先把主控台字碼頁切到 UTF-8，中文才不會顯示成亂碼
REM  2) 自動改用 PowerShell 執行 windows-deploy.ps1
REM ============================================================
chcp 65001 >nul
setlocal

cd /d "%~dp0.."

where pwsh >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    pwsh -NoProfile -ExecutionPolicy Bypass -File "%~dp0windows-deploy.ps1" -Interactive %*
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0windows-deploy.ps1" -Interactive %*
)

echo.
pause
