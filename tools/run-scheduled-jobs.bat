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
REM  One minute is Windows' minimum repeat interval. par_tick,
REM  pending_messages_tick and channel_receive_tick are all genuinely 60s
REM  jobs; audit_log_purge_tick and message_log_purge_tick are documented as
REM  "run once a day" -- that describes the RETENTION cadence, not how often
REM  this script may safely call them. Both are idempotent cutoff-date
REM  queries (SELECT eligible rows / DELETE WHERE created_at < cutoff):
REM  calling them every minute costs one cheap query on the minutes nothing
REM  is due and is otherwise correct. One task drives all seven ticks: fewer
REM  moving parts than seven separate tasks, and they are always in step.
REM  (channel_receive_tick, added Phase 134, is a no-op sweep -- 0 channels
REM  polled -- on any install that hasn't opted a channel in to inbound
REM  polling, so scheduling it unconditionally alongside the others is safe
REM  and harmless. org_relationship_activation_cleanup, added Phase 143, is
REM  the same shape: a no-op sweep -- 0 expired-but-open activations -- on
REM  any install that has never used a standing cross-org relationship.
REM  inbound_calls_tick, added Phase 149, is the same shape again: a no-op
REM  sweep -- 0 wrapup folds, 0 stale claims -- on any install with zero
REM  configured inbound-call trunks.)
REM
REM  audit_log_purge_tick and message_log_purge_tick were missing from this
REM  file entirely until 2026-08-14 -- each got a systemd timer the day it
REM  shipped (Phase 133, GH#42), but this file never got the matching line.
REM  Exactly the "shipped the job, forgot the Windows wiring" gap this whole
REM  runner exists to prevent. tests/test_scheduled_jobs_windows.php now
REM  asserts the runner invokes EVERY job sched_job_registry() knows about,
REM  driven from the registry itself rather than a hardcoded job list, so
REM  this can't silently happen a third time.
REM
REM  Verify it is FIRING, not merely registered -- those look identical from
REM  the Task Scheduler UI. Watch the run counter climb on
REM  Settings -> Status -> Scheduled background jobs across ~75 seconds, or:
REM
REM    schtasks /Query /TN "TicketsCAD Background Jobs" /V /FO LIST
REM
REM  PHP: uses php.exe from PATH. Override by setting TICKETSCAD_PHP to a full
REM  path before calling, e.g. in the task's environment or by editing the
REM  SET line below. It must be php.exe (the CLI binary) -- both scripts
REM  refuse to run under a non-CLI SAPI, so php-cgi.exe will not work.
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

"%TICKETSCAD_PHP%" tools\par_tick.php >> "%LOGDIR%\par_tick.log" 2>&1
if errorlevel 1 set "RC=1"

"%TICKETSCAD_PHP%" tools\pending_messages_tick.php >> "%LOGDIR%\pending_messages_tick.log" 2>&1
if errorlevel 1 set "RC=1"

"%TICKETSCAD_PHP%" tools\channel_receive_tick.php >> "%LOGDIR%\channel_receive_tick.log" 2>&1
if errorlevel 1 set "RC=1"

"%TICKETSCAD_PHP%" tools\audit_log_purge_tick.php >> "%LOGDIR%\audit_log_purge_tick.log" 2>&1
if errorlevel 1 set "RC=1"

"%TICKETSCAD_PHP%" tools\message_log_purge_tick.php >> "%LOGDIR%\message_log_purge_tick.log" 2>&1
if errorlevel 1 set "RC=1"

"%TICKETSCAD_PHP%" tools\org_relationship_cleanup_tick.php >> "%LOGDIR%\org_relationship_cleanup_tick.log" 2>&1
if errorlevel 1 set "RC=1"

"%TICKETSCAD_PHP%" tools\inbound_calls_tick.php >> "%LOGDIR%\inbound_calls_tick.log" 2>&1
if errorlevel 1 set "RC=1"

REM All seven jobs always run: a failure in one must not stop the others. The
REM exit code reports whether any of them failed, so Task Scheduler's
REM "Last Run Result" is meaningful.
exit /b %RC%
