TECHSHIFT POS — KEY STORE (PRIVATE, DO NOT PUBLISH)
===================================================

This folder is the recovery copy of the TechShift POS signing keys and every
licence key issued to a customer. It lives in the private source repository.
It must never be pushed to the public releases repository, attached to an
email, or shared.

Who owns the software: TechShift.
Support: dantech1438@gmail.com · 0790554926 / 0706039116 (WhatsApp)


CONTENTS
--------
signing\license_private.pem   The RSA private key that signs licence keys.
                              THIS IS THE MOST SENSITIVE FILE. Anyone with it
                              can issue keys for any PC. Back it up in a
                              password manager / encrypted drive as well.
signing\license_public.pem    Public key. Must stay byte-identical to
                              PointShiftPOS\www\pos\app\keys\license_public.pem
                              (that copy is what the app verifies with).
tools\licence.php             Generator (self-contained copy; uses the keys
                              in signing\ by default).
issued\<Customer>\<KeyID>.txt One record per key issued, including machine ID,
                              type, expiry and the full key text.
machine-ids.txt               Machine IDs seen so far.


ISSUING A KEY
-------------
Three ways to issue a key. All use the same private key and produce the same
key format.

GitHub Actions (from any device)
  The private repo contains .github/workflows/issue-licence.yml.
  1. Add a repository secret named LICENCE_PRIVATE_KEY containing the full
     text of signing\license_private.pem (Settings → Secrets and variables →
     Actions). It is encrypted by GitHub and never printed in logs.
  2. Open Actions → "Issue licence" → Run workflow, fill in the customer,
     machine ID and subscription length (or choose perpetual).
  3. The signed key appears in the run summary and as a downloadable
     "licence-key" artifact. Save that record under issued\<Customer>\.
  Workflows can only be started by people with write access to this repo.

GUI (easiest on the office PC)
  Double-click gui\Start Licence Manager.bat and open http://127.0.0.1:8799/
  if the browser does not open by itself. The first run asks you to set a PIN
  (stored hashed in gui-data\pin.txt). Fill in:
    - Customer / shop name
    - Machine ID from the client's activation screen
    - Subscription (length in days or exact expiry date) or One-time
    - Key ID (next free number is suggested) and optional notes
  The key is signed, the record is saved to issued\<Customer>\<KeyID>.txt,
  and only then shown with a Copy button. The Issued keys table lists every
  key with its status (active, expiring, grace, expired) and a .txt download.
  The manager binds to 127.0.0.1 only and is not part of the client package.

Command line
  Run with the portable PHP from the repo root:

  PointShiftPOS\runtime\php\php.exe -c PointShiftPOS\runtime\php\php.ini ^
      keystore\tools\licence.php issue --type=perpetual ^
      --name="Customer Name" --machine=XXXX-XXXX-XXXX-XXXX --id=TS-2026-001

  ... issue --type=subscription --name="Customer Name" ^
      --machine=XXXX-XXXX-XXXX-XXXX --days=30

Options:
  --days=N          subscription length (default 30)
  --exp=YYYY-MM-DD  explicit expiry instead of --days
  --out=file.txt    also write the customer block to a file

Then save the printed customer block as
  issued\<Customer Name>\<Key ID>.txt
so the key can be recovered if the customer loses it.

Check any key:
  ... licence.php verify TS-...


RECOVERY
--------
If the development PC is lost, clone the private repository on a new PC.
Everything needed to issue keys is in this folder. Copy the key record for
the customer from issued\ and send it again. Do not reissue a different key
for the same machine unless asked.

If the private key is ever exposed, the keypair must be rotated: generate a
new pair, replace the public key in the app, ship an update, and reissue
every client's key. Keep this folder out of any public repository.


IMPORTANT
---------
- Machine IDs are derived from live hardware. The OS lookup is cached per
  Windows user (LOCALAPPDATA\TechShift), never inside the pos-data folder, so
  copying that folder to another PC cannot reuse a key there.
- Renaming a computer or changing its hardware changes the Machine ID; the
  customer must request a new key if that happens.
- Keep this repository private.