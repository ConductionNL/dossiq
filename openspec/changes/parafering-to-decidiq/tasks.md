# Tasks: parafering-to-decidiq

> Depends on decidiq's `approval-route-events` (ConductionNL/decidiq#1023),
> which is itself stacked on `governance-body-events` (#1019).

## Implementation Tasks

### Task 1: The delegation service
- **spec_ref**: `openspec/changes/parafering-to-decidiq/specs/parafering-to-decidiq/spec.md#requirement-req-ptd-001-routes-are-held-in-the-decision-app`
- **files**: `lib/Service/Parafeer/ParaferingDelegationService.php`
- **acceptance_criteria**:
  - GIVEN a route WHEN held THEN the command carries sourceApp `dossiq`, the local id as externalReference, the name, subjectType and the ordered steps
  - GIVEN the local step types WHEN translated THEN advies/parafering/accordering become advisory/endorsement/decisive, and an unknown type becomes `endorsement` rather than nothing
  - GIVEN a step with no `order` WHEN mapped THEN it takes its array position, because order IS the sign-off sequence on the other side
  - GIVEN steps stored as a JSON string THEN they are accepted
  - GIVEN the decision app absent or refusing THEN it fails closed
- [x] Implement
- [x] Test — mutation-checked: dropping the order fallback and dropping the type translation each turn the suite red

### Task 2: The migration
- **spec_ref**: `.../spec.md#requirement-req-ptd-001-routes-are-held-in-the-decision-app`
- **files**: `lib/Repair/MigrateParafeerroutesToDecidiq.php`, `appinfo/info.xml`, `lib/Settings/register.d/74-parafering-to-decidiq.json`
- **acceptance_criteria**:
  - GIVEN routes WHEN the step runs THEN each is held and the local row records the id
  - GIVEN a route already carrying an id THEN it is skipped
  - GIVEN no decision app THEN the step reports a skip and changes nothing
  - GIVEN no `runAsSystem()` THEN the step FAILS rather than running as Anonymous — a repair step has no session and `$output->warning()` does not fail an upgrade
- [x] Implement
- [x] Test

### Task 3: Activation sends the route and starts the chain
- **spec_ref**: `.../spec.md#requirement-req-ptd-002-activating-a-voorstel-sends-its-route-and-starts-the-chain-there`
- **files**: `lib/Service/BesluitvormingParafeerService.php`, `lib/Service/Parafeer/ParafeerrouteDirectory.php`
- **acceptance_criteria**:
  - GIVEN a resolved route WHEN a voorstel is activated THEN the snapshot carries its steps and the command names the voorstel as subject
  - GIVEN the decision app absent or refusing THEN activation still SUCCEEDS and `approvalRouteId` stays empty, which is how an unmirrored voorstel is found
  - GIVEN the route is later edited THEN the voorstel's snapshot is unchanged
- [x] Implement
- [x] Test

### Task 4: A voorstel that cannot be routed is not put into parafering
- **spec_ref**: `.../spec.md#requirement-req-ptd-003-a-voorstel-that-cannot-be-routed-is-not-put-into-parafering`
- **files**: `lib/Service/BesluitvormingParafeerService.php`
- **acceptance_criteria**:
  - GIVEN no default route for the case type WHEN activation is attempted THEN it is refused and NO save happens
  - GIVEN a route whose steps are empty THEN it is refused for the same reason
  - GIVEN the refusal THEN the voorstel's status and currentStep are untouched
- [x] Implement
- [x] Test — mutation-checked: restoring the old carry-on-with-an-empty-snapshot behaviour turns the suite red

### Task 5: Retire the runtime chain in dossiq
- **spec_ref**: deferred
- **acceptance_criteria**:
  - Deliberately out of scope. `parafeeractie`, `currentStep`, `routeSnapshot` and the action pipeline stay. dossiq's pipeline owns a status vocabulary, a return notification, accordering effects and mandate validation that the decision app's engine does not; replacing it means reproducing all four or losing them, which is its own change with parity tests.
- [ ] Implement
- [ ] Test
