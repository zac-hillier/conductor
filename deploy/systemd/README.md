# Conductor — always-on services (systemd user units)

These run Conductor unattended on a workstation, using **user** services (no root),
matching the local `127.0.0.1` user-service convention.

## Install

```
cp deploy/systemd/conductor-horizon.service deploy/systemd/conductor-scheduler.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now conductor-horizon conductor-scheduler
```

- **conductor-horizon** — runs `php artisan horizon`, the queue supervisor that
  executes dispatched jobs (`RunTaskJob`/`ScopeTaskJob`/`ScoreTaskJob`) off the
  dedicated Redis. The supervisor caps concurrency at 3 processes (see
  `config/horizon.php`), approximating the per-profile cap-3 concurrency; adjust
  `maxProcesses` there to scale the pool — no need to add units.
- **conductor-scheduler** — runs `schedule:work`: the every-minute auto-dispatch tick
  (`conductor:dispatch-tick`) and the 5-minute stuck-task recovery (`conductor:recover`).

### Migrating from the plain queue workers

Horizon **replaces** the previous `conductor-queue@1..3` workers. On a host that
still has them installed:

```
systemctl --user disable --now conductor-queue@1 conductor-queue@2 conductor-queue@3
```

then install and enable `conductor-horizon` as above. `conductor-scheduler` is
unchanged. The dedicated Redis (`conductor-redis`, port 6381) must be running —
bring up the compose stack first (`docker compose up -d`).

## Database backups

`conductor-backup.timer` runs `deploy/backup/conductor-db-backup.sh` hourly — a
gzipped `pg_dump` of the Conductor database to `/home/zac/backups/conductor/`
(14-day retention). Install:

```
cp conductor-backup.service conductor-backup.timer ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now conductor-backup.timer
```

Take an ad-hoc backup before any risky operation:
`deploy/backup/conductor-db-backup.sh pre-op`. Restore:
`gunzip -c <file>.sql.gz | docker exec -i conductor-postgres psql -U conductor -d conductor`.

## Notes

- The unit `Environment=PATH` must include the directory holding the `claude` binary
  (`/home/zac/.local/bin`) so workers can launch it.
- Headless operation requires lingering: `loginctl enable-linger $USER`.
- **Starting the scheduler arms autonomous execution**: profiles with `auto_dispatch`
  enabled (personal profiles, which run with `bypassPermissions`) will run any
  scored-ready task unattended. Tasks below the readiness threshold, or unscored, are held.
- Check status: `systemctl --user status conductor-scheduler`; logs: `journalctl --user -u conductor-scheduler -f`.
