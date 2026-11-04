# bezwaar-advisory-committee Delta: the-advice-checks-fail-closed-on-every-path

**Status**: draft
**Scope**: the panel check without an object store, and the id the advice transition marks its save under.

## ADDED Requirements

### Requirement: the panel check and the advice transition fail closed on every path

The panel check MUST answer unverifiable, never independent, when no object store is available. The advice transition MUST mark its save under the stored advice request's own id, read after the request is loaded, so the guard recognises the transition whatever id form the caller used.

#### Scenario: no object store
@e2e exclude needs an instance without OpenRegister; covered by PanelIndependenceCheckerGoldenTest
- **GIVEN** no object store is available
- **WHEN** the panel check runs for a panel
- **THEN** the verdict is unverifiable, with no conflicting member

#### Scenario: the committee issues the advice through another id form
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation` that the store also finds by its slug
- **WHEN** `transitionAdviceStatus()` is called with the slug and moves it to `advice-issued`
- **THEN** the guard lets the save through
- **AND** the request is stored in `advice-issued`
