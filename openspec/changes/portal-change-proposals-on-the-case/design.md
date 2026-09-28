# Design: portal-change-proposals-on-the-case

Read at dossiq `development` `c8ac7427e` and portaliq `development`
`8934e73`.

## Context

- portaliq `change-proposal-queue` design, section 2: "a contribution action
  `propose-change` on a scoped object [...] The server derives `proposedBy`
  from the portal session, never from the body. Only properties the
  contribution's field projection lists as `proposable` may be named."
  Section 3: two leaves, `portaliq-change-proposals` (data-provider) and
  `portaliq-change-proposal-queue` (render-surface, widget and tab); tasks T05
  and T06, which register them, are open. Section 4: accept writes the
  proposed values to the subject as the reviewer; "The owning app is never
  called; it sees an object update it already handles."
- portaliq `lib/Controller/ProposalController.php:468` `proposableFor()`
  finds the subject's `type: propose-change` action on the register and
  schema and reads its `proposable` list.
- portaliq `src/portal/components/PageView.jsx:336` renders the resident's
  propose form in the detail card when the collection's `rowActions` name a
  `propose-change` action.
- portaliq `lib/Contribution/CollectionConfigNormaliser.php:109`
  `resolveRowActions()` keeps only ids of `type: update` actions, so a
  `propose-change` id named in `rowActions` is dropped before it reaches the
  SPA (read in code, not checked live).
- dossiq `lib/Portal/PortalContributionProvider.php:544` `citizenActions()`
  declares no `propose-change`. dossiq `case.linkedTypes`
  (`lib/Settings/dossiq_register.json`, and fragments such as
  `register.d/68-pipelinq-leaves.json`) and the `CaseDetail` page in
  `src/manifest.json` place other apps' leaves as `type: integration`
  widgets (for example `humaniq-hours`, `shillinq-payment-requests`).

## D1. What a resident may propose

`citizenActions()` gains `proposeCaseChange`: `type: propose-change`,
register `dossiq`, schema `case`, `scopeField: portalSubject`,
`minTrust: low`, label "Wijziging voorstellen", `proposable`
`['title', 'description']`. `mijnZaken` names it in `rowActions`.

These are the fields the applicant supplied and can know to be wrong. Status,
result, deadlines, the assignee and the case type are the organisation's and
are never proposable.

## D2. The handler decides on the case page

`case.linkedTypes` gains `portaliq-change-proposal-queue` in a fragment
`lib/Settings/register.d/77-portaliq-change-proposals.json`, and `CaseDetail`
places it as a `type: integration` widget titled "Voorgestelde wijzigingen".
A leaf whose app has not registered it is never mounted, so until portaliq
lands T06 the panel is absent rather than empty.

## D3. The portal half that is not there yet

The resident's propose button depends on portaliq keeping a `propose-change`
id in `rowActions`, which its normaliser does not do today (Context). dossiq
declares it the way `PageView.jsx` reads it; the live check in tasks.md
confirms the button, and the gap is reported to the coordinator for portaliq.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `proposeCaseChange` and its `proposable` list | Declarative, the contribution manifest | Data portaliq reads. |
| The queue on the case page | Declarative, `linkedTypes` and a manifest placement | A leaf placement. |
| Accepting a proposal | None in dossiq | portaliq writes the case as the reviewer; dossiq sees an update it already handles. |

## Risks

- **A proposal accepted onto a closed case.** portaliq's accept writes as the
  reviewer with RBAC on, so it is refused wherever the reviewer could not
  edit the case by hand.
