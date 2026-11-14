# Case actions placed on the case page

## Why

Portaliq places a create action on the page of the case it is about when the action declares `onCase` (portaliq `case-page-objection-and-complaint`, PR #1843). It lists the action under "Meer acties voor deze zaak", fills in the case itself and keeps the cross-reference guard. A window on the placement closes the action once a term has run out, computed on the server.

Dossiq's bezwaar and klacht already name the case they are about (`contestedCase`, guarded through `crossRefs`). They reach the case page today as calls to action with `withRecord: true`. Declaring `onCase` replaces those calls to action, and the bezwaar closes once the six weeks after the decision went out have passed. The window needs a date on the case: no case field holds the day a decision was sent, so this change adds one.

This is configuration of generic actions (decision 182): no class is named after a procedure.

## What changes

- `createBezwaar` declares `onCase: {crossRef: contestedCase, order: 10, window: {from: decisionSentOn, days: 42}, closedText}` and a `successMessage`.
- `createKlacht` declares `onCase: {crossRef: contestedCase, order: 20}` and a `successMessage`. The "Klacht indienen" page keeps the complaint that is about no case.
- The case page drops its `cta` blocks for both. The overview declares neither, as before.
- The case gets `decisionSentOn` (a date, set by the server). Sending a decision writes the day it went out on its case.

## Impact

- `lib/Portal/CitizenManifest.php`, `lib/Portal/PortalPages.php`.
- `lib/Service/Beschikking/CaseRemedy.php` and the send in `lib/Service/BeschikkingService.php`.
- `lib/Settings/register.d/99-case-decision-sent-on.json` (case 1.61.0), register 0.20.87, schema digests, l10n keys.
- Pairs with portaliq #1843: until that lands, portaliq drops the unknown `onCase` key and the case page shows no bezwaar or klacht button.
