@echo off
:loop
if exist "%~dp0capteur_disabled.txt" goto fin
powershell -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0serial_reader.ps1"
timeout /t 5 /nobreak >nul
if exist "%~dp0capteur_disabled.txt" goto fin
goto loop
:fin
