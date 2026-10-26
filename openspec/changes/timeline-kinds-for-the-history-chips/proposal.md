# Timeline kinds for the history chips

## Why
DqZaakHistorie draws the chips Status, Documenten, Berichten, Taken and Toewijzing. dossiq declared no kind for a document act, a logged message, a task act or an assignment, so neither a writer nor a seed could put an entry under those chips. The status kind read "Statuswijziging".

## What changes
- Four generic kinds:
  - `document` (Documenten; `act` linked/reviewed/removed/added);
  - `bericht` (Berichten; `direction` inbound/outbound, optional channel, subject, counterpart);
  - `taak` (Taken; `act` created/completed/reassigned/cancelled);
  - `toewijzing` (Toewijzing; `act` picked-up/handed-over/team).
- None carries a follow-up.
- `statuswijziging` is titled "Status", the chip's word.
- A declaration reaches an instance when the declaring repair step runs again, so the release that carries this moves the app version.
