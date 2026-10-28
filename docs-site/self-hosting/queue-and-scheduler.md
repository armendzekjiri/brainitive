---
title: Queue worker and scheduler
description: Why Brainitive needs a queue worker and a scheduler, how to run them with systemd, Supervisor or cron, and which scheduled tasks exist.
order: 60
---

# Queue worker and scheduler

Besides the web server, Brainitive needs two background processes: a **queue worker** and a **scheduler**. Both are required. In Docker they are the `queue` and `scheduler` containers and need no setup.

## Why both are required

**The queue worker** runs work in the background so pages stay fast. Without it:

- no emails or notifications are sent,
- the search index is never built, so search finds nothing,
- imports, project exports and "Download my data" never finish,
- incoming helpdesk mail and outgoing webhooks are not processed.

**The scheduler** starts timed tasks. Without it:

- no reminders and no daily task digest,
- recurring issues are not created, due-date automations don't run,
- connected helpdesk mailboxes are not checked, SLA warnings don't go out,
- old data is not cleaned up.

## Queue worker

The command:

```bash
php artisan queue:work --tries=3 --max-time=3600
```

`--max-time=3600` restarts the worker every hour, which keeps memory in check. Your process manager starts it again.

### With systemd

`/etc/systemd/system/brainitive-queue.service`:

```ini
[Unit]
Description=Brainitive queue worker
After=network.target postgresql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/opt/brainitive
ExecStart=/usr/bin/php artisan queue:work --tries=3 --max-time=3600
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now brainitive-queue
```

### With Supervisor

`/etc/supervisor/conf.d/brainitive-queue.conf`:

```ini
[program:brainitive-queue]
command=php /opt/brainitive/artisan queue:work --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/opt/brainitive/storage/logs/queue.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

### After a code or `.env` change

The worker keeps the old code and settings in memory. Tell it to restart after its current job:

```bash
php artisan queue:restart
```

### Failed jobs

A job that fails three times lands in the failed jobs list. **Instance admin → Overview** shows how many there are. On the command line:

```bash
php artisan queue:failed          # list them
php artisan queue:retry all       # try them again
```

Failed jobs are removed automatically after 30 days.

## Scheduler

Add one cron entry for the web server user (`sudo crontab -u www-data -e`):

```bash
* * * * * cd /opt/brainitive && php artisan schedule:run >> /dev/null 2>&1
```

Cron runs `schedule:run` every minute, and Brainitive decides which tasks are due. Docker uses `php artisan schedule:work` instead, which does the same in a long-running process.

To see all tasks and when they run next:

```bash
php artisan schedule:list
```

## Scheduled tasks

| Task | How often | What it does |
|---|---|---|
| `app:poll-helpdesk-mailboxes` | every minute | Imports new mail from connected helpdesk mailboxes |
| `app:check-helpdesk-sla` | every minute | Warns before and on helpdesk SLA breaches |
| `app:send-calendar-reminders` | every 5 minutes | Browser reminders for upcoming calendar events and Google Tasks |
| `app:send-item-reminders` | every 5 minutes | Reminders for issues and tasks that start soon or are due |
| `app:send-task-digest` | every 5 minutes | The morning summary of tasks due today or overdue (from 08:00 in each user's time zone) |
| `app:run-due-date-automations` | every 5 minutes | Runs "Due date passed" automation rules |
| `search:ensure` | every 5 minutes | Queues a search index build when this version of the index was never built |
| `app:send-booking-reminders` | every 15 minutes | Emails booking guests a reminder about a day before |
| `app:create-recurring-issues` | every 15 minutes | Creates the issues of recurring issue templates that are due |
| `github-stars` | hourly | Refreshes the star count on "Star on GitHub" buttons (turn off with `GITHUB_STARS=false`) |
| `exports:cleanup` | hourly | Deletes project exports and "Download my data" files after their 24-hour download window |
| `imports:cleanup-files` | daily, 04:10 | Deletes uploaded import files after 7 days |
| `model:prune` | daily, at night | Removes old records: MCP request keys, webhook and GitHub deliveries, inbox notifications, automation run logs, helpdesk inbound mail records, import runs, exports, spent portal sign-in links, offline sync records, expired push subscriptions |
| `auth:clear-resets` | daily, 04:45 | Removes expired password-reset tokens |
| `passport:purge` | daily, 04:50 | Removes revoked and expired OAuth tokens |
| `queue:prune-failed` | daily, 04:55 | Removes failed jobs older than 30 days |

Many of these tasks only queue work. The queue worker then does it, so both processes have to run.
