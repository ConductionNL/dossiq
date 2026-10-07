---
kind: code
depends_on: [case-type-rebind]
---

# Proposal: case-type-rebind-property-impact

Ruben, 2026-10-07: "for dossiq we should also develop a change case type
modal that shows which properties will get dropped, which will be ported
where, and which additional properties must now be supplied."

## Why

`case-type-rebind` asks for a target type, a landing status and a reason.
It does not say what happens to the answers the case already carries.
Three things go wrong today:

- A coordinator cannot see which answers the target type has no field
  for. Those answers stay on the case under names nothing reads.
- An answer whose field exists on both types carries over without a word,
  even when the target field has another type (text onto a number).
- The fields the target requires are asked by name only, in a plain text
  box, and the confirm button stays disabled while they are missing, so
  the dialog could never confirm a rebind that needed an answer.

Underneath sits a shape bug. The register stores `case.properties` as a
LIST of `{propertyDefinition, name, value}` entries (the schema, the
`FoldCasePropertiesOntoCase` repair, `RequiredFieldGuard`, the Woo intake).
`CaseRebindGate` read and wrote it as a name-keyed MAP, so every live case
looked like it answered nothing, and a rebind wrote a map onto an array
property.

## What changes

- One computation, `CaseRebindImpact`, answers three groups for a case, a
  target type, a landing status, an optional remap and the answers given:
  - **dropped**: answers on this case the target has no compatible field
    for, with their current value and the target fields they could be
    moved onto;
  - **ported**: answers that carry over, `source name -> target name`, the
    value before and after, and how it maps (same type, converted, or
    remapped by the coordinator);
  - **required**: target fields that are required (`isRequired`, or
    `requiredAtStatus` equal to the landing status) and still empty, with
    their definition so the dialog renders a typed widget, plus whether
    the given answer is valid.
- The preview endpoint (`GET /api/case/{id}/rebind?target=…&status=…`)
  returns that `impact` block and accepts `remap[...]` and
  `properties[...]`, so the dialog and an API caller read the same answer.
  `canRebind` is true only when a status is chosen, the remap is valid and
  every required field holds a valid answer.
- The rebind (`POST`) recomputes the same impact and applies exactly it in
  the one case write it already makes: ported values move to the target
  field, required answers are saved, dropped values leave the case. The
  POST must carry `confirmDropped`, the list of dropped names the
  coordinator saw; a missing or different list is refused, so a case that
  changed after the preview is not rebound blind.
- Dropped values are kept on the case's own journal (`activity`), the
  mechanism the rebind already writes its entry to: the
  `case-type-rebind` entry gains `droppedProperties` (name and value),
  `portedProperties` and `answeredProperties`. OpenRegister's audit trail
  records the same write.
- `CaseRebindDialog` shows the three groups. Dropped rows show the value
  and a picker to move it onto a compatible field; a checkbox confirms the
  loss. Required rows render a field per type (text, number, date, choice,
  yes/no). The confirm button stays disabled until the server says the
  impact is complete.
- `CaseRebindGate` reads and writes the list shape, and keeps reading a
  legacy map.

## Capabilities

- Modified: `zaaktype-versioning` (REQ-ZV-07 gains the impact).

## Impact

`lib/Service/Cases/CaseRebindImpact.php`,
`lib/Service/Cases/RebindValueConverter.php` (new);
`lib/Service/Cases/CaseRebindGate.php`, `lib/Service/CaseRebindService.php`,
`lib/Controller/CaseRebindController.php`;
`src/dialogs/CaseRebindDialog.vue`,
`src/components/case/CaseRebindImpact.vue`,
`src/components/case/RebindPropertyField.vue` (new); `l10n/en.json`,
`l10n/nl.json`; unit and component tests.
