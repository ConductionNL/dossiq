# Buildiq consumer halves (handed over 25 Nov 2026)

What buildiq serves to dossiq, for task 15. Both are app-local leaves
(ADR-066): dossiq asks, buildiq answers, buildiq never writes the case. When
buildiq is not installed or answers nothing, dossiq renders its manifest as it
does today.

## `buildiq-page-layout`: what a case's detail page shows

Asked with the case's register, schema and id; pass the case itself as the
`object` filter when dossiq already holds it (saves buildiq a read). The
audience is never sent: buildiq resolves it from the caller's own session
(everyone, the caller's groups and teams, the caller), so the desk channel asks
as the signed-in handler and nothing else. At most one item comes back.

```json
{
  "items": [{
    "id": "7f0c…",
    "targetApp": "dossiq",
    "register": "dossiq",
    "schema": "case",
    "typeProperty": "caseType",
    "typeValue": "bouwvergunning",
    "header": {"fields": ["identifier", "status", "deadline"]},
    "tabs": [{"id": "overview", "label": "Overzicht"}, {"id": "documents", "label": "Documenten"}],
    "widgets": [{"id": "permit-map", "component": "CnMapWidget", "tab": "overview"}],
    "taskList": {"panel": {"placement": "side", "tabs": [], "collapsed": false}},
    "uploadFields": [{"key": "documentType", "required": true}]
  }],
  "total": 1,
  "nextCursor": null,
  "appliedLayers": [{"id": "7f0c…", "name": "Bouwvergunning", "audience": {"kind": "everyone", "ref": null}}],
  "withheld": []
}
```

- `taskList.panel.placement` is `side`, `tab` (then `tabs` names the tabs it
  sits beside) or `none`.
- A widget whose display conditions do not hold is not sent at all.
- `items: []` means: render the manifest unchanged.

## `buildiq-registration-form`: which form a case type opens with

Asked on the case type object, with optional `audience`, `name` and `channel`
filters. The channel's own forms come first, then the forms without a channel.
`drafts` are the caller's own saved drafts of these forms.

```json
{
  "items": [{
    "id": "c1d2…",
    "name": "Aanvraag bouwvergunning (balie)",
    "audience": "employee",
    "channel": "desk",
    "isDefault": true,
    "isPublic": false,
    "minTrust": null,
    "fee": null,
    "confirmationText": "",
    "allowSaveForLater": true,
    "sections": [{"id": "applicant", "label": "Aanvrager"}],
    "fields": [{"key": "applicantName", "section": "applicant", "required": true}],
    "steps": [],
    "formLogic": [],
    "presets": [{"key": "intakeChannel", "value": "desk"}]
  }],
  "total": 1,
  "nextCursor": null,
  "drafts": [{"form": "c1d2…", "id": "d9e8…", "updated": "2026-11-24T10:12:00Z"}]
}
```

- A hidden preset is removed from `fields[]` and travels only in `presets[]`;
  write it on save, never render it.
- Fields come in the order the administrator arranged, grouped by `sections`.
- Write the served `channel` to `case.intakeChannel` on create (15.5).
