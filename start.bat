@echo off
chcp 65001 >nul
cd /d "%~dp0"
title PI Project Management - XAMPP
set "XAMPP_HOME=C:\xampp"
if not exist "%XAMPP_HOME%\xampp-control.exe" (
  echo XAMPP tidak ditemukan di %XAMPP_HOME%.
  echo Sesuaikan XAMPP_HOME di file start.bat dengan lokasi instalasi XAMPP.
  pause
  exit /b 1
)
start "XAMPP Control Panel" "%XAMPP_HOME%\xampp-control.exe"
echo.
echo Pada XAMPP Control Panel, klik Start untuk Apache dan MySQL.
echo Setelah keduanya berstatus Running, buka http://localhost/ProjectTracker/
echo Untuk akses LAN, gunakan IP server dan pastikan firewall mengizinkan TCP port 80.
echo Jangan jalankan server.js untuk aplikasi PHP ini.
pause
