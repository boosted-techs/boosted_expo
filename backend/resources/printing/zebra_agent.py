#!/usr/bin/env python3
"""Opt-in, unprivileged Zebra workstation transport. Uses Python stdlib only.

No external downloads or server-supplied commands/destinations. The operator
selects an installed Windows/Mac printer queue (including USB), or a private
LAN printer. Lost/partial transfers need inspection before any reprint.
"""
import argparse
import getpass
import subprocess
import hashlib
import ipaddress
import json
import os
import pathlib
import socket
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


def config(saved_override=None):
    saved = saved_override if saved_override is not None else (json.loads(settings_path().read_text(encoding="utf8")) if settings_path().exists() else {})
    environment = {} if saved_override is not None else os.environ
    url = environment.get("EXPO_SERVER_URL", saved.get("server", "")).rstrip("/")
    parsed = urllib.parse.urlsplit(url)
    if (parsed.scheme != "https" or not parsed.hostname or parsed.username or
            parsed.password or parsed.query or parsed.fragment or parsed.port not in (None, 443)):
        raise ValueError("EXPO_SERVER_URL must be an HTTPS origin with no credentials or custom port.")
    agent = str(environment.get("EXPO_AGENT_ID", saved.get("agent", "")))
    token = environment.get("EXPO_AGENT_TOKEN", saved.get("token", ""))
    if not agent.isdecimal() or int(agent) <= 0:
        raise ValueError("EXPO_AGENT_ID must be an existing positive device ID.")
    if len(token) != 64 or not token.isalnum():
        raise ValueError("EXPO_AGENT_TOKEN must be the 64-character one-time device secret.")
    transport = environment.get("EXPO_PRINT_TRANSPORT", saved.get("transport", "tcp"))
    printer = environment.get("EXPO_PRINTER_NAME", saved.get("printer", ""))
    if transport == "system":
        if not printer or len(printer) > 200 or any(c in printer for c in "\r\n\x00"):
            raise ValueError("Select the installed printer queue name on this computer.")
        return url, agent, token, {"transport": "system", "printer": printer}
    if transport != "tcp":
        raise ValueError("Choose system or tcp printer transport.")
    addr = ipaddress.ip_address(environment.get("EXPO_ZEBRA_PRINTER_IPV4", saved.get("printer_ip", "")))
    networks = (
        ipaddress.ip_network("10.0.0.0/8"),
        ipaddress.ip_network("172.16.0.0/12"),
        ipaddress.ip_network("192.168.0.0/16"),
    )
    if addr.version != 4 or not any(addr in network for network in networks):
        raise ValueError("The Zebra printer must use an RFC1918 private IPv4 LAN address.")
    return url, agent, token, {"transport": "tcp", "printer": str(addr)}


def request(url, agent, token, path, payload=None):
    body = json.dumps(payload if payload is not None else {}).encode("utf-8")
    req = urllib.request.Request(
        url + "/print-agent/" + agent + path,
        data=body,
        headers={"Authorization": "Bearer " + token,
                 "Content-Type": "application/json", "Accept": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=12, context=ssl.create_default_context()) as response:
        raw = response.read(100000)
        if response.status != 200:
            raise RuntimeError("Unexpected HTTPS response from ERP")
        return json.loads(raw)


def settings_path():
    base = pathlib.Path.home() / ".boosted-export-erp"
    base.mkdir(mode=0o700, parents=True, exist_ok=True)
    return base / "printer-settings.json"


def setup():
    print("Connect Boosted One to an installed printer. No test sticker is sent during setup.")
    server = input("ERP address (https://...): ").strip().rstrip("/")
    agent = input("Workstation / agent ID from ERP: ").strip()
    token = getpass.getpass("One-time device secret (hidden): ").strip()
    print("Installed printers:")
    if os.name == "nt":
        result = subprocess.run(["powershell", "-NoProfile", "-Command", "Get-Printer | Select-Object -ExpandProperty Name"], capture_output=True, text=True, timeout=15, check=True)
    else:
        result = subprocess.run(["lpstat", "-p"], capture_output=True, text=True, timeout=15, check=True)
    print(result.stdout)
    print("Enter the exact printer name above. On Mac use the queue name after 'printer'.")
    printer = input("Installed printer name: ").strip()
    data = {"server": server, "agent": agent, "token": token, "transport": "system", "printer": printer}
    config(data)
    path = settings_path()
    temporary = path.with_name(path.name + ".tmp")
    with os.fdopen(os.open(temporary, os.O_CREAT | os.O_TRUNC | os.O_WRONLY, 0o600), "w") as stream:
        json.dump(data, stream)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)
    print("Settings saved privately. Run with --check-config, then --enable-physical-print when ready.")


def receipt_path():
    base = pathlib.Path.home() / ".boosted-export-erp"
    base.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(base, 0o700)
    return base / "zebra-transfers.json"


def receipts():
    path = receipt_path()
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf8"))


def save_receipt(job_id, sha, state="transferred"):
    path = receipt_path()
    data = receipts()
    data[str(job_id)] = {"sha256": sha, "state": state}
    temp = path.with_name(path.name + ".tmp")
    with os.fdopen(os.open(temp, os.O_CREAT | os.O_TRUNC | os.O_WRONLY, 0o600), "w") as f:
        json.dump(data, f)
        f.flush()
        os.fsync(f.fileno())
    os.replace(temp, path)


def send_zpl(ip, raw):
    # 9100 is Zebra's common raw socket print channel: local LAN only.
    with socket.create_connection((ip, 9100), timeout=6) as channel:
        channel.settimeout(6)
        channel.sendall(raw)


def send_system(printer, raw):
    if os.name != "nt":
        # No shell and no server-supplied command or destination. Use the local configured queue.
        subprocess.run(["lp", "-d", printer, "-o", "raw"], input=raw, capture_output=True, timeout=30, check=True)
        return
    import ctypes
    from ctypes import wintypes
    spool = ctypes.WinDLL("winspool.drv", use_last_error=True)
    class DocInfo(ctypes.Structure):
        _fields_ = [("name", wintypes.LPWSTR), ("output", wintypes.LPWSTR), ("datatype", wintypes.LPWSTR)]
    spool.OpenPrinterW.argtypes = [wintypes.LPWSTR, ctypes.POINTER(wintypes.HANDLE), wintypes.LPVOID]
    spool.StartDocPrinterW.argtypes = [wintypes.HANDLE, wintypes.DWORD, ctypes.POINTER(DocInfo)]
    spool.StartDocPrinterW.restype = wintypes.DWORD
    spool.StartPagePrinter.argtypes = [wintypes.HANDLE]
    spool.WritePrinter.argtypes = [wintypes.HANDLE, wintypes.LPVOID, wintypes.DWORD, ctypes.POINTER(wintypes.DWORD)]
    for method in ["EndPagePrinter", "EndDocPrinter", "ClosePrinter"]:
        getattr(spool, method).argtypes = [wintypes.HANDLE]
    handle = wintypes.HANDLE()
    if not spool.OpenPrinterW(printer, ctypes.byref(handle), None):
        raise ctypes.WinError(ctypes.get_last_error())
    started = False
    try:
        doc = DocInfo("Boosted One stickers", None, "RAW")
        if not spool.StartDocPrinterW(handle, 1, ctypes.byref(doc)):
            raise ctypes.WinError(ctypes.get_last_error())
        started = True
        if not spool.StartPagePrinter(handle):
            raise ctypes.WinError(ctypes.get_last_error())
        written = wintypes.DWORD()
        buffer = ctypes.create_string_buffer(raw)
        if not spool.WritePrinter(handle, buffer, len(raw), ctypes.byref(written)) or written.value != len(raw):
            raise RuntimeError("Printer queue did not accept the complete job; inspect it before retrying.")
        if not spool.EndPagePrinter(handle) or not spool.EndDocPrinter(handle):
            raise RuntimeError("Printer queue completion was not confirmed; inspect before retrying.")
        started = False
    finally:
        if started:
            spool.EndDocPrinter(handle)
        spool.ClosePrinter(handle)


def transfer_one(url, agent, token, printer_ip):
    packet = request(url, agent, token, "/next")
    job = packet.get("job")
    if not job:
        return False
    job_id = int(job["job_id"])
    raw = job["zpl"].encode("utf-8")
    sha = hashlib.sha256(raw).hexdigest()
    if (len(raw) > 1048576 or not raw.startswith(b"^XA\n") or
            not raw.rstrip().endswith(b"^XZ") or sha != job["sha256"]):
        raise RuntimeError("Received label failed fingerprint/ZPL validation.")
    copies = int(job.get("copies", 1))
    if copies < 1 or copies > 10:
        raise RuntimeError("Invalid sticker copy count.")
    key = hashlib.sha256((url + ":" + agent + ":" + str(job_id)).encode()).hexdigest()
    receipt = receipts().get(key)
    if receipt and (receipt.get("sha256") != sha or receipt.get("state") != "transferred"):
        raise RuntimeError("Prior transfer is uncertain. Inspect the printer and resolve it in the ERP; no automatic resend.")
    if not receipt:
        save_receipt(key, sha, "started")
        if printer_ip["transport"] == "system":
            send_system(printer_ip["printer"], raw * copies)
        else:
            send_zpl(printer_ip["printer"], raw * copies)
        save_receipt(key, sha)
    result = request(url, agent, token, "/jobs/" + str(job_id) + "/ack",
                     {"lease": job["lease"], "transferred": True})
    if result.get("status") != "transferred":
        raise RuntimeError("ERP did not accept workstation socket delivery acknowledgement.")
    print("Job", job_id, "reported transferred to printer connection/queue; paper output not verified.")
    return True


def main():
    parser = argparse.ArgumentParser(description="Boosted Export ERP safe local Zebra print transport")
    parser.add_argument("--setup", action="store_true", help="Guided setup for an installed Windows or Mac printer")
    parser.add_argument("--check-config", action="store_true",
                        help="Validate settings without connecting to ERP or printer")
    parser.add_argument("--enable-physical-print", action="store_true",
                        help="Explicitly enable sending queued ZPL to the local printer")
    parser.add_argument("--once", action="store_true", help="Poll once, then exit")
    args = parser.parse_args()
    try:
        if args.setup:
            setup()
            return 0
        url, agent, token, addr = config()
        if args.check_config:
            print("Configuration checked. No network connection or print attempted.")
            return 0
        if not args.enable_physical_print:
            parser.error("Printing is opt-in; pass --enable-physical-print after testing configuration.")
        while True:
            try:
                transfer_one(url, agent, token, addr)
            except (urllib.error.URLError, OSError, ValueError, RuntimeError, subprocess.SubprocessError) as exc:
                print("Print-agent delivery requires attention:", type(exc).__name__, file=sys.stderr)
            if args.once:
                break
            time.sleep(5)
    except (ValueError, OSError, subprocess.SubprocessError) as exc:
        print("Configuration error:", type(exc).__name__, file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
