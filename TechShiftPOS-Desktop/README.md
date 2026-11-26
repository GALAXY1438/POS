# TechShift POS — Desktop

Electron shell around the portable TechShift POS (PHP + PostgreSQL). Produces
`TechShift-POS-Setup-<version>.exe` and publishes updates to
**GitHub Releases** (`GALAXY1438/POS`), which installed clients
pick up automatically.

> **Why a second repository?** Update downloads are anonymous, so the release
> repository must be **public**. The source code stays private in
> `GALAXY1438/POS`. Create `POS` as an empty public
> repository once; the build uploads installers and `latest.yml` there.

## How it works

- `main.js` starts `Start POS.bat` from `resources\pos` with
  `TECHSHIFT_DATA_ROOT=%APPDATA%\TechShift POS\pos-data`, waits for
  `http://localhost:8787/pos/`, then shows the POS window.
- Database, logs and backups live in `%APPDATA%\TechShift POS\pos-data`, so
  installing an update never touches shop data.
- On quit, `Stop POS.bat` shuts the web server and PostgreSQL down cleanly.
- `electron-updater` checks GitHub Releases shortly after launch and prompts the
  user to download/install when a newer version exists.
- The portable folder (`Start POS.bat` in a normal window) still works exactly
  as before — the desktop app is the same code with a launcher.

## Prerequisites (build machine)

- Windows 10/11 x64
- Node.js 20+ on PATH (`node -v`)
- The portable POS folder at `..\PointShiftPOS` (or set `POS_SOURCE`)
- A GitHub token with `Contents: Read and write` on `GALAXY1438/POS`

## Build the installer (no publishing)

```bat
npm install
npm run dist
```

Result: `release\TechShift-POS-Setup-2.0.0.exe`

## Publish an update

Releases are built by GitHub Actions in the source repository
(`.github/workflows/release.yml`), so no signing/Smart App Control restrictions
apply to the build machine.

1. Tag the version from the repository root and push it:

   ```bat
   git tag v2.0.1
   git push origin v2.0.1
   ```

   The workflow checks out the source, downloads the PHP/PostgreSQL runtime
   from `POS` (release `runtime-2026-11-21`),
   builds the installer and uploads it plus `latest.yml` as a **draft** release.

   You can also run it manually (Actions → Build Windows installer → Run
   workflow) and pass a version and release notes.

2. Open https://github.com/GALAXY1438/POS/releases,
   review the draft and click **Publish release**.

3. Installed clients show "Update available" on their next launch and update
   in place. Data in `%APPDATA%\TechShift POS\pos-data` is untouched.

The token used by the workflow is the `RELEASE_TOKEN` repository secret; it
needs `contents: write` on the releases repository.

### Local build (optional)

`Release.bat 2.0.1 "notes"` still builds and publishes from this PC, but Smart
App Control on some machines blocks electron-builder from executing the
unsigned uninstaller stub. Prefer the GitHub Actions route.

## What is packaged

`npm run stage` copies the portable POS into `pos-app\` (runtime, `www`,
`setup`, batch files) and excludes `data\`, `logs\`, `backups\`, `.db_ready`.

A fresh install therefore starts with a new empty database and the default
login **admin / admin**.

## Notes

- The PHP source inside the installer is not encrypted. To keep the source out
  of the client's hands, encode `www\pos` with ionCube before staging (a paid
  ionCube Encoder licence is required, and the ionCube loader must be enabled
  in `runtime\php\php.ini`).
- App updates require the machine to reach github.com. If the shop has no
  internet, ship the new `TechShift-POS-Setup-*.exe` and run it — data is kept.
