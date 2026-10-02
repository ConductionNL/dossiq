---
kind: code
depends_on: [portal-case-page-withdraws, portal-pages-in-resident-groups, aanvullingsverzoek-as-a-record, citizen-status-labels]
---

# Proposal: site-resident-portal-design

Part of the portal-design programme (2026-10-02). Ruben approved three mockups for a resident
on portaliq's `/site`: `DossiqHome.dc.html` (signed out), `DossiqOverview.dc.html` (signed in,
overview) and `DossiqCase.dc.html` (one case). Source:
`~/memcap-work/portal-design/canvas/project/`, design canvas
https://claude.ai/artifact/3Jy3r5e5f9v9ktCLxisNG6. This change says what dossiq declares so
portaliq can render those screens. Portaliq owns the page shell, the widgets and the
components. Dossiq owns the data, the words and the page declarations.

## Why

A resident who opens dossiq on the site today reads a table. Three things are missing.

- **What the resident must do is invisible.** A handler who asks for a missing document
  writes an `aanvullingsverzoek` (shipped, `aanvullingsverzoek-as-a-record`) and sets
  `waitingOnApplicant` on the case. Neither reaches the portal: neither is in
  `CITIZEN_CASE_FIELDS`, and no collection serves the request. The mockup puts that question
  on top of the overview and on top of the case.
- **Where a case stands is a word, not a step.** The resident sees `statusPublicLabel`
  (shipped, `citizen-status-labels`) as a badge. The mockup shows "Stap 2 van 4" and a process
  steps list with the case type's public labels. Nothing hands portaliq the ordered steps.
- **The menu repeats itself.** With no pages declared, portaliq builds one menu entry per
  listable collection, beside its own "Mijn zaken" and inbox. Open PR dossiq#3245
  (`portal-pages-in-resident-groups`) renames dossiq's entries so the names no longer clash.
  The mockups go one step further: one "Zaken" and one "Berichten", and nothing else from
  dossiq in the menu.

## What changes

Builds on two open, unmerged changes. `portal-case-page-withdraws` (dossiq#3247) declares the
`mijnZaken` page with a `citizenCase` block and `statusLabelField`. `portal-pages-in-resident-groups`
(dossiq#3245) gives every page a `group`. This change keeps both and adds:

- **New: the question to the resident reaches the portal.** An `aanvullingsverzoek` carries
  the `portalSubject` of its case, stamped when it is asked. A new citizen collection
  `vragenAanU` serves the open requests, field-projected to `summary`, `missingItems`,
  `hersteltermijn`, `state` and `case`. The case projection gains `waitingOnApplicant` and
  `waitingOn`, so a case card can say who must act.
- **New: the steps a case passes through.** A provider method `caseSteps(caseId)` returns the
  case type's statuses folded by public label, in order, each marked done, current or to do.
  The case collection declares it as `steps`, the way it declares `timeline` and `documents`.
- **New: the case says who handles it, in public words.** A calculated `assignedGroupPublicName`
  carries the name of the handling team, or nothing when the team declares no public name.
- **Page declarations for the three screens.** The resident overview, the case page and the
  start points for the signed-out home, with the blocks portaliq's mijn-omgeving components
  render (design D4).
- **One "Zaken" and one "Berichten".** Every dossiq resident page declares `menu: false`.
  The case page opens from portaliq's case list and from notices, the reply lives on the case
  page, and new requests start from the overview and the home page.
- **Words from the mockups.** "Documenten" replaces "Stukken" as the documents label.
  "Wat er is gebeurd" stays.

## What this change does not do

- It does not build any component, widget or page shell. Those are portaliq's, in the changes
  the portaliq lane of this programme writes: `site-mijn-omgeving-components` (case card,
  process steps, action row, file item, contact timeline, side navigation, data badge) and
  `site-nlds-widget-palette` (the widgets an editor places on the signed-out home).
- It does not change how a withdrawal works. The withdraw side action is portaliq's
  `citizenCase` block (`citizen-case-withdraw-screen`) under dossiq's `portalWithdrawal`.
- It does not build the signed-out home. That page is authored in portaliq's site editor; this
  change declares what dossiq offers it.

## Not in this change

- "Taken" as a count in the side navigation. Portaliq's "Mijn taken" lists engine portal
  tasks (`portal-task-delivery`). Making every `aanvullingsverzoek` a portal task as well is a
  larger choice; it is an open decision for Ruben (tasks 0.1). This change shows the open
  questions through `vragenAanU` instead.
- "Mijn gegevens" and "Machtigingen" in the account menu. Portaliq owns both
  (`portal-profile`, `portal-access-requests`); see `site-business-and-authorisation`.
- The appointment and phone details in the footer. Those are site content.

## Capabilities

- Modified: `portal-contribution`.

## Impact

`lib/Portal/PortalContributionProvider.php` (fields, collection, `steps`, pages),
`lib/Portal/CaseSteps.php` (new), `lib/Settings/register.d/63-aanvullingsverzoek.json`
(`portalSubject` on `aanvullingsverzoek`), the case schema (`assignedGroupPublicName`),
the code that asks (`InformationRequestService::ask()`), `l10n/`.
