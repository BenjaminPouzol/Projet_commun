@echo off
:loop
powershell -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0serial_reader.ps1"
timeout /t 5 /nobreak >nul
goto loop
