# Proposal: new-case-board-hints

## Why

The New case form on the Cases page showed the schema's data-model descriptions as helper lines ("Verwijzing naar het
zaaktype", "Nextcloud-gebruikers-id van de primaire behandelaar") and confirmed with "Aanmaken". The DqNieuweZaak board
draws helper lines written for the person filling the form in, none under Title, Requester, Description, Assigned to
and Confidentiality, and "Zaak aanmaken" on the button.

## What changes

The simple profile's Cases page `new-case` action sets `formConfirmLabel: "Create case"` and a `description` per field
in `fieldOverrides`: the board's line, or an empty one where the board draws none. The long explanations behind the
info toggletips stay. The navigation's New case form mirrors the manifest's My work action and is not changed here.
