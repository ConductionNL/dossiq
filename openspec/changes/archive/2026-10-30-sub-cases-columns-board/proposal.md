# The sub-cases table as DqZaakGerelateerd draws it

## Why
r29 (25 Oct, example set reinstalled) drew the sub-cases' status as the status type's uuid ("3c0f5a00...") and the handler as a uid ("pieter") under "Toegewezen aan". It also had no footnote. The board draws the status name as a toned pill, the handler under "Behandelaar", and "Een deelzaak is een zaak, ..." under the table.

## What changes
This is config on the simple profile's sub-cases card:
- the status column uses the case list's statusTypeName formatter with a badge resolved to the status type's colour;
- the handler column is labelled "Handler" and shows the person as an avatar with their name;
- the board's footnote goes under the table.

The completion summary stays. en/nl keys added.
