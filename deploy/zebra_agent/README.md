# Boosted One sticker printing — GK888t and other printers

The first supported preset is **Zebra GK888t, 203 dpi, 100 × 50 mm stickers**. The application also accepts separate profiles for other ZPL printers or PDF printing through an installed system driver. Profile configuration is not a claim of physical compatibility with every device.

## Easiest first print: PDF

1. Install the GK888t in Windows or macOS and load the correct ribbon/media. Complete the printer's feed/media calibration. Use the manufacturer's instructions for the actual hardware: [Zebra GK888t guide](https://docs.zebra.com/us/en/printers/desktop/gk888t-desktop-thermal-printer-user-guide.html).
2. Open **Sticker templates → Printer setup**. Save the prefilled GK888t 100 × 50 profile.
3. Save a template using that profile. Private carton QR and the revision barcode work without publishing business information. Select any required customer/flight/AWB/weight fields in the advanced section.
4. Open **Print stickers** from a carton, packing job, order or shipment. Select cartons and copies, then create the preview batch. Each carton has its own saved artwork and revision identity.
5. Download PDF. The default is one 100 × 50 mm sticker per PDF page. Print at **actual size / 100%**, with no fit-to-page scaling or headers/footers. Sheet printers can specify sheet dimensions, rows, columns, gaps and offsets explicitly.
6. Measure a physical sticker and scan both codes. Record that evidence under the profile. Generation/download/queue status never proves that paper was printed or that a sticker was applied.

On Android, PDF printing needs a compatible installed printer service. Direct USB/raw ZPL from any Android browser is not promised. Android can always submit jobs to a configured shared Windows/Mac workstation while online.

## Shared Windows/Mac workstation, including USB

The helper uses the printer already installed on that computer. USB does **not** need a printer IP address.

1. Install Python 3.10 or later on the workstation if absent.
2. In **Queue & recovery**, register one workstation for the printer/media profile. Download the helper there. Save the one-time device secret privately; it cannot be displayed again.
3. Run `python zebra_agent.py --setup` on Windows, or `python3 zebra_agent.py --setup` on Mac. Enter the ERP's HTTPS origin, the workstation ID and the secret when prompted. Select the exact installed printer queue name. The secret is not echoed or placed on the command line.
4. Run with `--check-config` to check saved settings without contacting the ERP or printer.
5. Run with `--enable-physical-print --once` for a supervised first transfer. Omit `--once` to keep the explicitly enabled helper polling.
6. Queue a previewed batch from the ERP. The selected workstation must match its media profile. Resolve failures/uncertainty in **Queue & recovery** after checking paper and the OS queue.

Windows uses the native RAW print spooler. Mac uses the local CUPS queue with raw ZPL; its queue/driver must support that mode. If raw output is rejected or treated as text, use the PDF path and a working manufacturer/system driver. These native transport paths still require testing on the actual Windows/Mac installations.

The self-contained downloadable helper is bundled at `backend/resources/printing/zebra_agent.py`. The script in this deployment folder is a repository launcher for the same implementation. No new Python package is required.

### Advanced network printer settings

Existing environment-based setup remains available:

- `EXPO_SERVER_URL`: HTTPS origin, without credentials/path/custom port.
- `EXPO_AGENT_ID` and `EXPO_AGENT_TOKEN`: registered device identity and secret.
- `EXPO_PRINT_TRANSPORT=system` with `EXPO_PRINTER_NAME`: use an installed local printer queue.
- `EXPO_PRINT_TRANSPORT=tcp` with `EXPO_ZEBRA_PRINTER_IPV4`: numeric RFC1918 IPv4 on the trusted LAN; sends raw ZPL to port 9100. This is optional for printers that support it, not a GK888t USB prerequisite.

The ERP never supplies a command or printer destination for the workstation to execute. Mac invokes a fixed local print program without a shell. Device tokens are revocable; register a new workstation to rotate them. Settings and transfer receipts live in the user's `.boosted-export-erp` directory; protect that user account and its per-user files.

## Recovery, copies and applied verification

- **Queued:** waiting for pickup.
- **Leased:** a workstation received the job and has a bounded acknowledgement window.
- **Transferred:** workstation reported acceptance by its OS print queue or printer connection. Physical output remains unconfirmed.
- **Uncertain:** acknowledgement expired or transfer may be partial. The server never automatically resends it.
- **Failed:** source/integrity checks prevented delivery; inspect the stated reason.
- **Cancelled/resolved:** further delivery was stopped, or an operator recorded a recovery decision.

A partial/lost transfer can produce some, all or none of the requested copies. Check the printer and OS queue first. Recovery requires a reason and inspection confirmation. Reprints are separate linked jobs, preserving the original record. A local started-transfer receipt is saved before writing bytes; the helper refuses to automatically repeat an uncertain transfer. Exactly-once physical printing is not guaranteed.

Scan the current private carton QR or revision barcode, check the actual carton and record applied verification. The barcode encodes `L` plus this sticker's revision token; its human caption is the carton code. A carton name alone cannot prove the sticker revision. Scanners that act as keyboards can fill the scan field; a phone can open its QR URL using the phone's scanner and paste the scanned value for confirmation. There is no in-app camera scanner in this phase.

Owners can enable **Require a current, scan-verified sticker before dispatch** under Printer setup. Changed weights, customer/transport data, content or template versions make older output stale. Generate and verify replacement stickers; the previous artwork and audit events stay available. Historical ZPL downloads are marked as archived output, not an assertion that they remain suitable for printing. Older templates must be saved in the preview layout before batch printing/verification.

## Acceptance still required on real equipment

Software tests and local PDF inspection do not replace these checks:

- GK888t 100 × 50 mm: calibration, margins, ribbon/media, long names/required fields, barcode and QR readability, correct carton/revision.
- Windows USB: installed queue and RAW transport; unavailable printer and OS queue recovery.
- Mac: PDF print at 100%, and raw transport only if the installed queue supports it.
- Android: shared-workstation submission and PDF through the available printer service.
- Lost acknowledgement, partial copy run, reprint, stale replacement and dispatch verification.

Material/consumable stock, damaged-sticker stock accounting and full offline work belong to later ERP phases. This phase records copy requests, transfer attempts, reasons and applied verification, not a complete consumables inventory.
