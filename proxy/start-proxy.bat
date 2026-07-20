@echo off
title Zello Proxy Server
cd /d "%~dp0.."
C:\xampp\8.2.4\php\php.exe proxy\zello-proxy.php
pause
