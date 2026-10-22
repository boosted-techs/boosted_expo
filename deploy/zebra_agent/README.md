# Boosted Export ERP - Local Zebra Print Agent

This is a manually enabled workstation-side transport. It does not expose a
printer to the public ERP server. It requires a Zebra network printer supporting
ZPL through its TCP 9100 socket, accessible only over the warehouse's private
RFC1918 LAN. Raw socket printing may be unencrypted; never expose port 9100 to
the public Internet or untrusted Wi-Fi.

## Setup

1. On an approved workstation, install Python 3.10+.
2. In ERP > Print Workstations register the device. Save the one-time 64-
   character token privately. It will not be shown again.
3. Configure the following environment variables on the workstation:
   - EXPO_SERVER_URL: real production HTTPS origin, no path or custom port
   - EXPO_AGENT_ID: numerical device identifier in ERP
   - EXPO_AGENT_TOKEN: one-time workstation secret
   - EXPO_ZEBRA_PRINTER_IPV4: local numerical IPv4 address in 10/8,
     172.16/12, or 192.168/16; for example, 192.168.1.55
4. In a terminal in this folder run:

       python3 zebra_agent.py --check-config

   This mode never connects to the network or prints.
5. In ERP > Sticker Templates generate a carton ZPL label. In ERP > Print
   Workstations queue the saved label to that workstation.
6. After personally confirming the printer, labels and warehouse LAN are
   correct, perform one explicitly enabled print transfer:

       python3 zebra_agent.py --enable-physical-print --once

   Omit --once only with approval for an ongoing supervised print operator.

## Print status limitations

QUEUED: pending authenticated workstation pickup.
LEASED: workstation received job but has not acknowledged.
TRANSFERRED: workstation claims it sent bytes to the printer socket.
FAILED: printer or acknowledgements require operator inspection.
CANCELLED: revoked workstation or manually cancelled job.

TRANSFERRED IS NOT PHYSICAL PRINT CONFIRMATION. Socket writes may succeed
without a good physical label. A crashed workstation may print but fail to
acknowledge; the lease can later be picked up again. The local agent records
job ID and ZPL SHA-256 at its private path
~/.boosted-export-erp/zebra-transfers.json (0600 permissions) before
acknowledgement to reduce repeat delivery. Exactly-once physical printing is
not guaranteed. Operators should inspect existing carton stickers before
requeueing, including on network interruptions.

The agent never runs commands from the ERP, connects to arbitrary web URLs,
or accepts the printer destination from any remote response. A device token
can be revoked from the ERP. Rotate by revoking and registering a new device.

For unsupported Zebra models, use the original label ZPL download from
Label History until a model-specific print protocol is developed.

## Physical acceptance gate

No real printer has been connected during this development pass. Before the
warehouse uses this workflow, test the actual Zebra model and DPI, QR scan,
Code 128 read, media calibration, Unicode/font handling, paper stock, correct
carton matching, duplicate prevention after lost acknowledgement, LAN
security and printer-offline behaviour. This is not equivalent to a certified
device driver and does not automatically confirm stickers were applied.
