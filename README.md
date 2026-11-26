# TechShift POS

Point of sale for retail shops — PHP + PostgreSQL, shipped as a one-click
Windows package and an Electron desktop application.

| Folder | Contents |
| --- | --- |
| `PointShiftPOS/` | Portable POS: PHP/PostgreSQL runtime, `www/pos` application, `setup/sql`, batch launchers |
| `TechShiftPOS-Desktop/` | Electron shell, installer and auto-update configuration (NSIS, electron-updater) |

## Build the Windows installer

1. Put the portable POS folder at `PointShiftPOS/` (the runtime/ and `www/`
   must be present).
2. In `TechShiftPOS-Desktop/`:

   ```bat
   npm install
   npm run dist
   ```

   Result: `release/TechShift-POS-Setup-<version>.exe`.

## Publish an update

Releases are built by GitHub Actions (`.github/workflows/release.yml`) and
uploaded to the **public** releases repository
`GALAXY1438/POS`. Installed clients check that
repository on start and offer the update. The source code stays in this
**private** repository.

```bat
git tag v2.0.1
git push origin v2.0.1
```

Then publish the draft release on GitHub. The workflow needs the runtime
archive published once under the `runtime-2026-11-21` release (PHP/PostgreSQL
only — `www/` comes from this repository), and the `RELEASE_TOKEN` secret with
`contents: write` on the releases repository.

## Data locations

- Desktop app: `%APPDATA%\TechShift POS\pos-data` (database, logs, backups).
- Portable build: `PointShiftPOS\data`, `logs`, `backups` (not committed).

A fresh installation ships the default account **admin / admin** — change it
after the first login.
