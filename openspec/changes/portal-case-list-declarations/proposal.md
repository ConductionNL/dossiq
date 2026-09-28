---
kind: code
depends_on: []
---

# Proposal: portal-case-list-declarations

Owner-moves pass of 2026-09-28. Three halves that portaliq's OpenSpec-pass
lane handed to dossiq after dossiq's own lane had finished. None has a matrix
row of its own; each is the dossiq side of an open portaliq change. They share
one declaration: the `mijnZaken` collection in dossiq's portal contribution,
`lib/Portal/PortalContributionProvider.php`.

| requesting change (ConductionNL/portaliq) | what it asks of dossiq |
| --- | --- |
| `cases-my-cases-page` | "dossiq declares `kind: 'cases'` and `closedField: 'endDate'` on its `mijnZaken` collection. Without it the page lists no dossiq case." |
| `signin-eherkenning-branch` | "dossiq (and any case app with business cases) stores the branch number on a case filed by a business and declares `branchField` on its collection. Until it does, a branch-restricted session sees none of its cases, which is the safe answer." |
| `identity-ways-in-screens` | "A case app that admits the `reference` identity kind (dossiq) declares which field of its case collection holds the case number, so the reference session can read that one case." |

dossiq#3152 records the first half as a live defect: portaliq's
`GET /portal/api/my-cases` skips `mijnZaken` because it carries no
`kind: 'cases'` (portaliq `lib/Service/PortalCaseListReader.php:95`).

## Why

A resident who signs in to the portal cannot find their dossiq cases on "My
cases", cannot tell an open case from a closed one, and a company that signs
in for one branch sees none of them. Reading the code for this change found a
reason that sits in front of all three: **dossiq serves its cases to no
audience a portal login carries.** `getAudiences()` answers `supplier`,
`citizen` and `inspector` (`PortalContributionProvider.php:245`), and
`getContribution()` gives the case collection only to `citizen` (:288). A
portaliq session from DigiD or eIDAS carries audience `client`, and one from
eHerkenning carries `supplier` (portaliq
`lib/Service/OidcClaimMapperService.php`, presets `digid`, `eidas`,
`eherkenning`). So the declarations below reach nobody until the case
collection is served to those audiences too, and this change does both.

Decision `build`: each half is one a merged portaliq change depends on.

## What changes

- The case collection is served to `client` (DigiD and eIDAS residents) and
  to `supplier` (companies signing in with eHerkenning), as well as to
  `citizen` as today.
- `mijnZaken` declares `kind: 'cases'` and `closedField: 'endDate'`, so it is
  listed on "My cases" and sorted into open and closed.
- A case carries the branch number (vestigingsnummer) of the company that
  filed it, and `mijnZaken` declares it as `branchField`.
- `mijnZaken` declares `referenceField: 'identifier'`, and a case type can
  say whether a resident may reach its cases with a case number and an
  e-mail address instead of an account. No case type admits that until
  portaliq checks the address against the case (see design D4).

## Capabilities

- Modified: `portal-contribution`.

## Out of scope

- The "My cases" page, the open and closed tabs and the branch switcher:
  portaliq's.
- Writes on a case from the portal: `portal-citizen-writes-on-the-case`.
