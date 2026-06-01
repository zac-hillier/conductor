# Conductor — always-on services (systemd user units)

These run Conductor unattended on a workstation, using **user** services (no root),
matching the local `127.0.0.1` user-service convention.

## Install

```
cp deploy/systemd/conductor-queue@.service deploy/systemd/conductor-scheduler.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now conductor-queue@1 conductor-queue@2 conductor-queue@3 conductor-scheduler
```

- **conductor-queue@N** — queue workers that execute dispatched jobs. Three instances
  approximate the per-profile cap-3 concurrency; add/remove instances to scale the pool.
- **conductor-scheduler** — runs `schedule:work`: the every-minute auto-dispatch tick
  (`conductor:dispatch-tick`) and the 5-minute stuck-task recovery (`conductor:recover`).

## Notes

- The unit `Environment=PATH` must include the directory holding the `claude` binary
  (`/home/zac/.local/bin`) so workers can launch it.
- Headless operation requires lingering: `loginctl enable-linger $USER`.
- **Starting the scheduler arms autonomous execution**: profiles with `auto_dispatch`
  enabled (personal profiles, which run with `bypassPermissions`) will run any
  scored-ready task unattended. Tasks below the readiness threshold, or unscored, are held.
- Check status: `systemctl --user status conductor-scheduler`; logs: `journalctl --user -u conductor-scheduler -f`.
