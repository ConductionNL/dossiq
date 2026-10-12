# Linked case with a role (C11)

## Why

ADR-118 (decisions 182, 184, 185) says a procedure is configuration and code is generic. Five dossiq classes link one case to an earlier case or decision for one procedure only: `BezwaarCreationHook`, `BezwaarObjectionController`, `BeroepEscalationListener`, `Bezwaar\BeroepService` and `DwangsomBezwaarService`. Each one hard-codes a role (the case contests a decision, the appeal follows an objection), a marker it derives and an outcome it cascades.

This change builds the generic capability those classes become configuration of. It is capability C11 in hydra `openspec/changes/procedures-are-configuration/design.md`.

## What changes

- A case type declares its relation roles in `relations[]`: the role, what it points at (a case, or an object that belongs to a case), the relation type it writes, whether intake needs it, and the record it opens.
- `RoleLinkService` links a case with a declared role: it writes the typed case relation through `CaseRelationService` and opens the link record, with the actor taken from the session.
- `GET /api/cases/{caseId}/links` lists the roles the case type declares and says which required ones are still missing. `POST /api/cases/{caseId}/links/{role}` links.
- Marker rules (app config `link_marker_rules`) derive a flag on a referenced object when the object that references it changes. `LinkMarkerListener` applies them.
- Outcome cascades (app config `link_cascades`) run named steps on a target object, its dependents and its case: set fields, set fields on the objects that reference it, run a transition on its case. `POST /api/links/cascades/{name}/{targetId}` runs one.

The procedure classes do not move in this change. They move when their configuration ships as the bezwaar package (R1's installer), and each move deletes its class.

## Impact

- New: `lib/Service/Relation/RoleLinkDefinition.php`, `RoleLinkService.php`, `LinkMarkerRule.php`, `OutcomeCascade.php`, `lib/Listener/LinkMarkerListener.php`, `lib/Controller/CaseLinkController.php`, `lib/AppInfo/Registrar/CaseLinkListenerRegistrar.php`.
- `caseType` schema gains `relations`.
- No class is deleted here, so `.hydra/procedure-names.json` does not shrink yet.
