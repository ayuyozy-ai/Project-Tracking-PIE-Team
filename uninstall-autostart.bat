@echo off
net session >nul 2>&1
if errorlevel 1 (
  echo Jalankan dengan klik kanan ^> "Run as administrator".
  pause
  exit /b 1
)
schtasks /end /tn "ProjectTracker" >nul 2>&1
schtasks /delete /tn "ProjectTracker" /f
netsh advfirewall firewall delete rule name="Project Tracker" >nul 2>&1
echo Tugas autostart dan aturan firewall lama untuk backend Node dihapus.
echo Folder data dan layanan Apache/MySQL TIDAK dihapus.
pause
