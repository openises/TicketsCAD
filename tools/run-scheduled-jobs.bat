@echo off
REM ---------------------------------------------------------------------------
REM  TicketsCAD - Windows background job runner
REM
REM  The Windows counterpart to the systemd timers described in
REM  docs/MAINTENANCE-RUNBOOK.md. Windows has no systemd, so on a Windows/IIS
REM  install these jobs need a Task Scheduler entry or they never run at
REM  all -- and nothing except Settings -> Status will say so.
REM  (GH openises/TicketsCAD#18)
REM
REM  Register it, from an ELEVATED prompt, adjusting the path:
REM
REM    schtasks /Create /TN "TicketsCAD Background Jobs" /SC MINUTE /MO 1 ^
REM      /RU SYSTEM /RL HIGHEST /F ^
REM      /TR "C:\inetpub\wwwroot\TicketsCAD\tools\run-scheduled-jobs.bat"
REM
REM  WHAT IT RUNS (since 2026-10-19): this file no longer lists the jobs one
REM  by one. It hands the work to tools\run-scheduled-jobs.php --once, the same
REM  runner the Docker "scheduler" service uses, which knows EVERY job and its
REM  interval -- every job in sched_job_registry() (inc/scheduled-jobs.php)
REM  plus the runner-only ones in inc/scheduled-job-runner.php (automatic
REM  backups, expired-grant cleanup, weather polling, the patch-rail
REM  regulatory audit). Listing them here is how the audit-log and
REM  message-log purges went missing from this file for months (2026-08-14)
REM  and how webhook retries never ran on Windows at all; there is now one
REM  list, and tests/test_scheduled_jobs_windows.php asserts the runner covers
REM  every registered job.
REM
REM  Called every minute, the runner runs each job only when it is due: the
REM  one-minute jobs every time, the daily purges once a day (it remembers
REM  each job's last start in cache\job-state), backups when a backup is due.
REM  Each job runs as its own process, so one failing or hanging job cannot
REM  stop the others, and a hung one is killed after its timeout. One minute
REM  is Windows' minimum repeat interval, so the 15-second inbound-call sweep
REM  runs once a minute here.
REM
REM  One consequence worth knowing: the runner waits for the jobs it started,
REM  so on the run that takes a backup this task stays busy until the backup
REM  is written, and Task Scheduler skips the minutes in between ("do not
REM  start a new instance"). Those jobs simply run on the next minute.
REM
REM  Each job's output is appended to cache\job-logs\<script>.log (the same
REM  file names as before); failures are also written to
REM  cache\job-logs\run-scheduled-jobs.log.
REM
REM  Verify it is FIRING, not merely registered -- those look identical from
REM  the Task Scheduler UI. Watch the run counter climb on
REM  Settings -> Status -> Scheduled background jobs across ~75 seconds, or:
REM
REM    schtasks /Query /TN "TicketsCAD Background Jobs" /V /FO LIST
REM
REM  PHP: uses php.exe from PATH. Override by setting TICKETSCAD_PHP to a full
REM  path before calling, e.g. in the task's environment or by editing the
REM  SET line below. It must be php.exe (the CLI binary) -- the runner and
REM  every job refuse to run under a non-CLI SAPI, so php-cgi.exe will not
REM  work.
REM ---------------------------------------------------------------------------

setlocal

REM Repo root is one level up from tools\.
cd /d "%~dp0.." || exit /b 1

if "%TICKETSCAD_PHP%"=="" set "TICKETSCAD_PHP=php"

set "LOGDIR=%CD%\cache\job-logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%" 2>nul

REM Fail loudly if PHP is not reachable, rather than writing an empty log
REM every minute forever. A silent scheduled job is the whole reason this
REM file exists.
"%TICKETSCAD_PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo [%DATE% %TIME%] FATAL: could not run "%TICKETSCAD_PHP%" - is PHP on PATH^? Set TICKETSCAD_PHP to the full path to php.exe.>> "%LOGDIR%\run-scheduled-jobs.log"
    exit /b 1
)

set "RC=0"

"%TICKETSCAD_PHP%" tools\run-scheduled-jobs.php --once "--log-dir=%LOGDIR%" >> "%LOGDIR%\run-scheduled-jobs.log" 2>&1
if errorlevel 1 set "RC=1"

REM The runner's exit code reports whether any job failed, so Task
REM Scheduler's "Last Run Result" is meaningful.
exit /b %RC%
