# Social domain consent and safeguards

## Why

Row 14.2 (handle Wmo, Jeugdwet and Participatiewet cases with consent and special-category data safeguards). The case types ship as packages (`jeugdwet`, `wmo-melding`, `bijstandsaanvraag`, `re-integratietraject`; `social-domain-procedures-are-case-types` is fully ticked) and the register declares `toestemming` (consent) and `gdprClassification` (`lib/Settings/register.d/50-sociaal-domein.json`). `privacy-and-social-domain-groups` gave the groups. What no screen does on development `a5c9b4153`:

- record, show or revoke a consent on the case (`toestemming` has no writer under `src/`);
- set or show the special-category classification of a case;
- keep a social domain case to its neighbourhood team when the case type says so;
- export a case set anonymised for research or accountability.

## What changes

All four are generic capabilities, switched on per case type (decision 182):

- A consent widget on the case: who consented, to sharing with whom, for which purpose, until when; revoke as a row action. Sharing with an external party (`CaseShareOrganisationDialog`, `CrossDomainLookupDialog`) is refused without a valid consent when the case type requires one.
- A special-category classification on the case (the categories of AVG art. 9), required at intake when the case type says so, shown as a pill and written to the processing log.
- "Only the handling team" access: a case type flag that narrows read access to the case type's handling teams (`case-type-handling-teams`) plus the parties' own portal access.
- An anonymised export of a filtered case list, with the fields the case type marks as identifying removed or generalised.

## Impact

- `src/manifest.json` CaseDetail widgets `consents` and the classification pill; `src/dialogs/ConsentRecordDialog.vue`.
- Case type properties `requiresConsentForSharing`, `requiresSpecialCategory`, `handlingTeamOnly`; the social packages set them.
- Export: an `anonymised` option on the list export (`export` header action) reading the case type's identifying-field list.
