@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo Fitur autostart lama ini hanya berlaku untuk server Node dan telah dinonaktifkan.
echo Aplikasi sekarang memakai PHP + MySQL melalui XAMPP.
echo Gunakan XAMPP Control Panel untuk menjalankan Apache dan MySQL.
echo Jika autostart diperlukan, pasang Apache dan MySQL sebagai Windows services dari XAMPP Control Panel.
echo Jangan membuka port 3000; aplikasi ini memakai Apache port 80.
pause
