---
kind: code
---

# Proposal: letters-print-every-date-the-same-way

## Summary

A resident's receipt in the portal read "Wij hebben uw aanvraag ontvangen op 15-06-2026 en de beslistermijn start op 15-06-2026. Wij nemen uiterlijk op 2026-08-10 een besluit". `TermLetters` printed the received and start dates through `dayOf()` (dd-mm-yyyy) and every other date (the end of the term, the end of the grace period, the new end date, the date by which missing information must arrive) as the store holds it. Every date in every letter now goes through one formatter and reads the way the drawn message boards write dates: "10 augustus 2026", or "10 August 2026" in an English letter.

## Why

Found in the live test of the Zuiddrecht demo portal on 13 and 14 October 2026: every Ontvangstbevestiging in Sanne's messages showed two formats side by side. The boards BerichtDetail and Berichten write dates in full ("Ontvangen op 7 oktober 2026", "Bezwaar maken kan tot en met 18 november 2026", "U hoort uiterlijk 1 november van ons").

## What changes

- `TermLetters::dayOf()` writes "j <month> Y" with the month spelled out in the letter's language (Dutch, or English for an English letter). As before, it does not go through `IL10N`, because the language is the case type's and not the reader's interface.
- `TermLetters::shownDay()` (new) runs every printed date through `dayOf()` and keeps a placeholder ("–") as it is. It covers the term end (acknowledgement, extension), the grace end (ingebrekestelling), the new end date (extension) and the pause deadline (information request and reminder).

## Screens

BerichtDetail and Berichten (`screens-src/zuiddrecht/`): the message text follows their date style. No layout change.
