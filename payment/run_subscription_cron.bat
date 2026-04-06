@echo off
REM ใช้กับ Windows Task Scheduler: รันวันละครั้งหรือทุก 1-6 ชม. ให้ครอบเวลา 08:00 เวลาไทย
REM ถ้า PHP ไม่อยู่ที่นี้ แก้ path ด้านล่างให้ตรงกับเครื่องคุณ
set PHP_EXE=C:\xampp\php\php.exe
"%PHP_EXE%" "%~dp0cron_child_subscription_charges.php"
exit /b %ERRORLEVEL%
