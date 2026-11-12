# address-postcode-with-space

## Why
Contacts and organisations keep their postcode as the registers store it ("2999AA"). The contact and organisation pages wrote it that way; people, and the boards (DqContact, DqOrganisatie), read "2999 AA".

## What changes
- Every address part on the contact and organisation pages reads its postcode through the facts `postcode` format (nextcloud-vue facts-postcode-format).
- Stored data does not change.
