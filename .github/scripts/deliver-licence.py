#!/usr/bin/env python3
import json
import os
import re
import smtplib
import ssl
import sys
from email.message import EmailMessage
from pathlib import Path
from urllib.request import Request, urlopen


def note(message):
    print(message)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write(message + "\n")


def normalise_phone(value):
    digits = re.sub(r"\D", "", value)
    if digits.startswith("0") and len(digits) == 10:
        digits = "254" + digits[1:]
    elif len(digits) == 9 and digits.startswith("7"):
        digits = "254" + digits
    if not re.fullmatch(r"254\d{9}", digits):
        raise ValueError("use 07XXXXXXXX, 7XXXXXXXX or 254XXXXXXXXX")
    return digits


def mask_phone(value):
    return "*" * max(0, len(value) - 3) + value[-3:]


def mask_email(value):
    if "@" not in value:
        return "invalid address"
    name, domain = value.rsplit("@", 1)
    return name[:1] + "***@" + domain


def send_sms(phone, customer, machine, key):
    api_key = os.environ.get("BLESSEDTEXTS_API_KEY", "").strip()
    sender = os.environ.get("BLESSEDTEXTS_SENDER_ID", "").strip() or "BLESSEDTEXT"
    if not api_key:
        raise RuntimeError("BLESSEDTEXTS_API_KEY is not configured")

    destination = normalise_phone(phone)
    message = (
        f"TechShift POS licence for {customer}. Machine {machine}. "
        f"Paste this key into the activation screen:\n{key}\nKeep it private."
    )
    payload = json.dumps({
        "api_key": api_key,
        "sender_id": sender,
        "message": message,
        "phone": destination,
    }).encode("utf-8")
    request = Request(
        "https://sms.blessedtexts.com/api/sms/v1/sendsms",
        data=payload,
        headers={"Content-Type": "application/json", "Accept": "application/json", "User-Agent": "TechShift-POS-Licence-Delivery/1.0"},
        method="POST",
    )
    with urlopen(request, timeout=20) as response:
        data = json.loads(response.read().decode("utf-8"))
    results = data if isinstance(data, list) else [data]
    if not results or any(
        not isinstance(item, dict) or str(item.get("status_code", "")) != "1000"
        for item in results
    ):
        codes = [
            str(item.get("status_code", "unknown"))
            for item in results if isinstance(item, dict)
        ]
        raise RuntimeError("provider rejected it (status " + ", ".join(codes) + ")")
    return destination


def send_email(address, customer, record):
    host = os.environ.get("SMTP_HOST", "").strip()
    username = os.environ.get("SMTP_USER", "").strip()
    password = os.environ.get("SMTP_PASS", "")
    sender = os.environ.get("LICENCE_FROM_EMAIL", "").strip() or username
    sender_name = os.environ.get("LICENCE_FROM_NAME", "").strip() or "TechShift POS"
    if not host or not username or not sender:
        raise RuntimeError("SMTP_HOST, SMTP_USER and LICENCE_FROM_EMAIL are required")
    if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", address):
        raise ValueError("enter one valid email address")
    if not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", sender):
        raise ValueError("LICENCE_FROM_EMAIL is not a valid address")

    port = int(os.environ.get("SMTP_PORT", "").strip() or "587")
    security = (os.environ.get("SMTP_SECURITY", "").strip() or ("ssl" if port == 465 else "tls")).lower()
    if security not in ("tls", "ssl"):
        raise ValueError("SMTP_SECURITY must be tls or ssl")
    message = EmailMessage()
    message["From"] = f"{sender_name} <{sender}>"
    message["To"] = address
    message["Subject"] = "Your TechShift POS licence"
    message.set_content(
        f"Hello {customer},\n\n"
        "Your TechShift POS licence is ready.\n\n"
        f"{record}\n\n"
        "Open TechShift POS and paste the key into the licence activation screen. "
        "Keep the key private; it is bound to the machine shown above.\n\n"
        "TechShift\n"
    )

    if security == "ssl":
        server = smtplib.SMTP_SSL(host, port, timeout=20, context=ssl.create_default_context())
    else:
        server = smtplib.SMTP(host, port, timeout=20)
    try:
        if security == "tls":
            server.starttls(context=ssl.create_default_context())
        server.login(username, password)
        server.send_message(message)
    finally:
        server.quit()


def main():
    record_path = os.environ.get("LICENCE_FILE", "")
    record = Path(record_path).read_text(encoding="utf-8") if record_path else ""
    match = re.search(r"^TS-[A-Za-z0-9._-]+$", record, re.MULTILINE)
    if not match:
        note("### Licence delivery\n\n- Failed: the signed licence record does not contain a key.")
        return 1

    key = match.group(0)
    customer = os.environ.get("CUSTOMER", "Customer")
    machine = os.environ.get("MACHINE", "")
    phone = os.environ.get("PHONE", "").strip()
    email = os.environ.get("EMAIL", "").strip()
    note("### Licence delivery")
    if not phone and not email:
        note("\n- Skipped: no client phone or email was supplied. The key remains in the run summary and artifact.")
        return 0

    failures = 0
    if phone:
        try:
            destination = send_sms(phone, customer, machine, key)
            note(f"\n- SMS to {mask_phone(destination)}: sent.")
        except Exception as exc:
            failures += 1
            note(f"\n- SMS to {mask_phone(phone)}: failed — {exc}.")
    if email:
        try:
            send_email(email, customer, record)
            note(f"\n- Email to {mask_email(email)}: sent.")
        except Exception as exc:
            failures += 1
            note(f"\n- Email to {mask_email(email)}: failed — {exc}.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
