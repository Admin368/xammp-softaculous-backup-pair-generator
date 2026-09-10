@echo off
rem softpack launcher (cmd.exe and PowerShell).
rem Set SOFTPACK_PHP to point at a specific php.exe, otherwise php on PATH is used.

setlocal
if "%SOFTPACK_PHP%"=="" (set "SOFTPACK_PHP=php")
"%SOFTPACK_PHP%" "%~dp0..\softpack.php" %*
exit /b %ERRORLEVEL%
