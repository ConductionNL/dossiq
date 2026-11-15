# Proposal: receipt-confirmation-card-reads

## Why

DqZaakContact draws the Receipt confirmation card as four facts in words: "Ontvangen op 3 okt 2026, 14:10", "Buiten
werktijd ontvangen", "Termijn start op 3 okt 2026" and "Uitgaande berichten: Ontvangstbevestiging via Mijn
Zuiddrecht, 3 okt 14:32". Live showed the stored values: "12-10-2026, 09:12:00", and the outbound messages as a
five-column table of raw codes (acknowledgement, 2026-10-14T18:36:12+00:00, sent, portal-inbox, ontvangstbevestiging).

## What changes

- The card reads its two dates through the library's `datetime` and `date` formatters, in the board's order.
- A new generic `outboundMessages` formatter writes each message a case sent as one line: what it was, the channel
  it went through and when ("Receipt confirmation via the portal, 3 Oct 14:32"); a message nothing took reads "not sent".
