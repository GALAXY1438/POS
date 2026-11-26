TECHSHIFT POS - CLIENT PACKAGE
===============================

Everything needed is inside this folder. No installation and no internet
connection are required for normal use.

REQUIREMENTS
------------
- Windows 10 or Windows 11, 64-bit.
- About 1 GB of free disk space for the program and your data/backups.
- The folder can be copied to another computer; keep the whole folder together.

DESKTOP APPLICATION (INSTALLER)
-------------------------------
The folder can also be used inside the TechShift POS desktop application.
1. Run  "TechShift-POS-Setup-<version>.exe"  and follow the installer.
2. Open "TechShift POS" from the desktop or Start menu.
3. The database, photos, logs and backups are stored in
       C:\Users\<you>\AppData\Roaming\TechShift POS\pos-data
   so installing an update never touches your sales data.
4. The application checks GitHub Releases for updates when it starts and
   asks before downloading/installing one. Internet is needed for updates;
   without internet it keeps working normally.
5. The portable method below ("Start POS.bat") still works as before and
   stores data in the folder's "data" sub-folder.

LICENSING AND ACTIVATION
------------------------
TechShift POS is licensed to one computer per key.
1. On first start the browser opens on the activation screen and shows a
   Machine ID. Send that ID to TechShift at dantech1438@gmail.com or
   0790554926 / 0706039116 (WhatsApp).
2. Paste the licence key you receive and click "Activate".
   - One-time (perpetual) licences never expire.
   - Subscription licences must be renewed before their expiry date. A 7-day
     grace period applies; after that the app locks until a renewal key is
     entered. Your data is never deleted.
3. Sign in (admin / admin). The first sign-in shows the licence agreement:
   read to the end, tick the box and click "Accept and continue".
Keep your licence key somewhere safe - it is tied to this computer (do not
rename the computer after activation). The
current licence and its Machine ID are shown in  Settings -> Licence.

FIRST TIME
----------
1. Double-click  "Start POS.bat"  (or the "TechShift POS" desktop shortcut
   if one was created).
   - The first start creates the database automatically and may take 1-2 minutes.
2. Your browser opens at:  http://localhost:8787/pos/
3. Sign in with:
       Username: admin
       Password: admin
4. IMPORTANT: change this password right away.
   Go to  User Management  ->  Reset Password  on the admin account.
5. Go to Settings and enter your shop name, phone, address, tax rate,
   receipt text and M-Pesa details (optional).
6. In User Management create accounts for your cashiers and staff.

DAILY USE
---------
- Start the system:  double-click "Start POS.bat" (wait a few seconds).
- Stop the system:   double-click "Stop POS.bat".
- If it is already running, "Start POS.bat" just opens the browser again.

RECEIPT PRINTER (thermal / Xprinter)
------------------------------------
1. Connect the printer by USB and switch it on.
2. In Windows:  Settings -> Bluetooth & devices -> Printers & scanners.
   The printer must appear there. If it does not, install its driver
   (the "Generic / Text Only" driver works for most 80 mm thermal printers).
   Receipts are sent directly to the printer without a Windows print dialog,
   so do NOT use "Microsoft Print to PDF" or "OneNote" as the receipt printer.
3. In the POS:  Settings -> Company Info -> Receipt Configuration.
   - Receipt Printer: choose your printer (or "Windows default printer").
   - Paper Width: 80 mm (48 chars) for 80 mm paper, 58 mm (32 chars) for small rolls.
   - Auto-print after each sale: tick to print automatically at checkout.
4. Click "Print Test Receipt" - it should print immediately.
5. Receipts can also be reprinted at any time from Transactions / Sales history.

BARCODE SCANNER (USB)
---------------------
- Any USB "keyboard wedge" barcode scanner (HENEX or similar) works with
  no driver or setup: plug it in, click inside the POS page, and scan.
- Selling: scan an item and it is added to the cart automatically. Scan
  the same item again to increase the quantity. A red message appears if
  the barcode is not registered.
- Adding a product: Inventory -> Add Product already puts the cursor in
  the Barcode field. Scan the item, press Enter, then type the name and
  price. If the barcode already exists a warning shows the existing
  product so you can update its stock instead of creating a duplicate.
- Updating stock: Inventory -> click "Scan" (or press "/"), scan the
  barcode and press Enter to find the product, then click Edit.

CUSTOMER DISPLAY SCREEN (optional second monitor)
-------------------------------------------------
Open this address on the display screen (no login needed):
   http://localhost:8787/pos/display.php?store=1

STOCK ALERTS & NOTIFICATIONS
----------------------------
- A bell icon at the top of every screen shows unread alerts. Click it to
  see what needs attention: products that are low in stock or finished,
  and stock changes made by staff.
- The bell checks for new alerts automatically, so it stays up to date
  while the POS is open. "Mark all read" clears the badge.
- Alerts appear when: a product drops to or below its Low Stock Alert
  level (set per product in Inventory), a product sells out, a sale is
  deleted and stock returned, or stock is added/adjusted.
- Optional SMS: Settings -> SMS -> tick "Send stock alerts by SMS" and
  enter the "Alert SMS number" (for example 2547XXXXXXXX). The shop
  phone entered in Company Info is used if the alert number is left empty.
  Click "Test Alert" to send a sample message and confirm it arrives.
  Weather and SMS balance are also shown on that tab.

LIVE OVERVIEW
-------------
- The Overview page updates itself every 20 seconds: today's sales,
  yesterday comparison, orders, stock value, low-stock list and recent
  sales all refresh without reloading the page.
- The "Live" dot turns grey if the connection drops and returns to green
  when the data refreshes again.
- Charts (sales for the last 7 days, payment mix) follow the light/dark
  switch at the top right.

REPORTS & PRINTING
------------------
- Admin -> Reports has two tabs: Sales and Inventory. Choose the dates
  and store, then click "Print Report".
- Printing opens a clean A4 statement (letterhead, reference number,
  summary tables, sign-off lines) in a new tab and the print dialog
  opens automatically. Use your normal A4 printer; no special settings
  are needed.
- Sales report: daily breakdown, payment methods, top products, staff
  and category performance, profit and peak hours.
- Inventory report: current stock valuation and a stock movement
  register (what was added or removed, by whom and when).
- Staff can print the Stock Control Statement from Inventory -> Reports.
- Cashiers: Sales shows daily totals, a live chart and payment mix for the
  chosen dates, always in the currency set in Settings. Transactions ->
  "Print Report" prints the selected sales as an A4 statement, and the
  Print button on any receipt prints a clean A4 receipt copy.

PRICE TAGS
----------
- Admin -> Print Price Tags: set the quantity on a product card and press
  Print, or tick several products and press "Print Selected Tags".
- Choose the label size (80 × 50 mm standard, 80 × 30 mm compact or
  58 × 40 mm small roll). Each label shows the shop name, product, price
  and a real Code 128 barcode that can be scanned at the till.
- In the print dialog choose your label/thermal printer, set Margins to
  None, Scale 100%, and switch off "Headers and footers" for clean labels.

BACKUPS & RESTORE
-----------------
- A backup is created automatically once a day when you start the system.
  Backups are stored in the  "backups"  folder and kept for 30 days.
- To make an extra backup at any time, double-click "Backup Now.bat".
- You can also back up and restore from inside the POS:
  Settings -> Backup & Restore.
  * "Download Backup Now" saves a complete .sql copy of everything:
    products, categories and brands, stock history, sales, users and settings.
    Do this before updating the POS or moving to a new computer.
  * To load a backup (for example after installing a new version): choose
    the downloaded .sql file under "Restore from a Backup" and click Restore.
    The current data is backed up automatically first, then replaced.
    You are signed out afterwards; log in with an account from the backup.
  * Older backups can be restored too: newer fields (such as product
    brands) are added to the database automatically.
- The backup contains the database (products, sales, users, settings).
  Product photos and the store logo are files in the  www\pos\uploads
  folder — copy that folder as well if you use them.

IMPORTANT
---------
- Do NOT delete or rename the  "data"  folder. It contains all your sales,
  products and staff information.
- When using the installed desktop application, never delete the
  %APPDATA%\TechShift POS\pos-data folder - that is where your data lives.
- Do NOT run two copies of the POS from two different folders.
- To move the system to a new computer: stop it, copy the whole folder
  (including "data" and "backups"), then run "Start POS.bat" on the new PC.
- Internet is only needed for M-Pesa, SMS or email features.

IF IT DOES NOT START
--------------------
1. Close any other program that may be using port 8787 or 55433.
2. Check the files inside the  "logs"  folder (postgres.log, setup.log,
   php_errors.log).
3. Every start writes errors there; send those files to your support person.

TROUBLESHOOTING PORTS
---------------------
The system uses port 8787 for the web interface and 55433 for its private
database. If another program already uses one of these ports:
- Open "Start POS.bat" and "Stop POS.bat" in Notepad.
- Change  APPPORT=8787  /  PGPORT=55433  to free numbers (for example
  APPPORT=8790 and PGPORT=55436), keeping the same values in both files.
- Your existing data is kept; it works with any port.

TECHSHIFT POS  |  Version 2.0.0
