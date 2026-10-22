#!/usr/bin/env python3
"""Opt-in, unprivileged Zebra workstation transport. Uses Python stdlib only.

No scanning, no external downloads, no shell commands from server, and no
server-requested destinations. The ERP queues an immutable ZPL snapshot and the
operator supplies a numeric private LAN printer IPv4 address on this workstation.
"""
import argparse
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


def config():
    url = os.environ.get("EXPO_SERVER_URL", "").rstrip("/")
    parsed = urllib.parse.urlsplit(url)
    if (parsed.scheme != "https" or not parsed.hostname or parsed.username or
            parsed.password or parsed.query or parsed.fragment or parsed.port not in (None, 443)):
        raise ValueError("EXPO_SERVER_URL must be an HTTPS origin with no credentials or custom port.")
    agent = os.environ.get("EXPO_AGENT_ID", "")
    token = os.environ.get("EXPO_AGENT_TOKEN", "")
    if not agent.isdecimal() or int(agent) <= 0:
        raise ValueError("EXPO_AGENT_ID must be an existing positive device ID.")
    if len(token) != 64 or not token.isalnum():
        raise ValueError("EXPO_AGENT_TOKEN must be the 64-character one-time device secret.")
    addr = ipaddress.ip_address(os.environ.get("EXPO_ZEBRA_PRINTER_IPV4", ""))
    networks = (
        ipaddress.ip_network("10.0.0.0/8"),
        ipaddress.ip_network("172.16.0.0/12"),
        ipaddress.ip_network("192.168.0.0/16"),
    )
    if addr.version != 4 or not any(addr in network for network in networks):
        raise ValueError("The Zebra printer must use an RFC1918 private IPv4 LAN address.")
    return url, agent, token, str(addr)


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


def save_receipt(job_id, sha):
    path = receipt_path()
    data = receipts()
    data[str(job_id)] = sha
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


def transfer_one(url, agent, token, printer_ip):
    packet = request(url, agent, token, "/next")
    job = packet.get("job")
    if not job:
        return False
    job_id = int(job["job_id"])
    raw = job["zpl"].encode("utf-8")
    sha = hashlib.sha256(raw).hexdigest()
    if (len(raw) > 65536 or not raw.startswith(b"^XA\n") or
            not raw.rstrip().endswith(b"^XZ") or sha != job["sha256"]):
        raise RuntimeError("Received label failed fingerprint/ZPL validation.")
    key = str(job_id)
    previously_sent = receipts().get(key) == sha
    if not previously_sent:
        send_zpl(printer_ip, raw)
        # Save the local receipt before acknowledging to reduce duplicate
        # printing after a lost response. Still not exactly-once physical print.
        save_receipt(job_id, sha)
    result = request(url, agent, token, "/jobs/" + key + "/ack",
                     {"lease": job["lease"], "transferred": True})
    if result.get("status") != "transferred":
        raise RuntimeError("ERP did not accept workstation socket delivery acknowledgement.")
    print("Job", job_id, "reported delivered to printer socket; paper output not verified.")
    return True


def main():
    parser = argparse.ArgumentParser(description="Boosted Export ERP safe local Zebra print transport")
    parser.add_argument("--check-config", action="store_true",
                        help="Validate settings without connecting to ERP or printer")
    parser.add_argument("--enable-physical-print", action="store_true",
                        help="Explicitly enable sending queued ZPL to the local printer")
    parser.add_argument("--once", action="store_true", help="Poll once, then exit")
    args = parser.parse_args()
    try:
        url, agent, token, addr = config()
        if args.check_config:
            print("Configuration checked. No network connection or print attempted.")
            return 0
        if not args.enable_physical_print:
            parser.error("Printing is opt-in; pass --enable-physical-print after testing configuration.")
        while True:
            try:
                transfer_one(url, agent, token, addr)
            except (urllib.error.URLError, OSError, ValueError, RuntimeError) as exc:
                print("Print-agent delivery requires attention:", type(exc).__name__, file=sys.stderr)
            if args.once:
                break
            time.sleep(5)
    except (ValueError, OSError) as exc:
        print("Configuration error:", type(exc).__name__, file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
