## ADDED Requirements

### Requirement: The result template presets the text on the form a handler can reach (REQ-TPL-06)

You close a case with a standard result and the standard words are already
there. `CaseLifecycleMenuDialog` SHALL offer result templates scoped to the
case type on every act that carries a result, and choosing one SHALL preset
the outcome text. The template SHALL be offered on the surface that closes a
case, not on a component no manifest action opens.

#### Scenario: a standard refusal is not retyped
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a result template for a niet-ontvankelijkverklaring, scoped to
  bezwaar case types
- **WHEN** a handler closes a bezwaar with that result and picks the template
- **THEN** the outcome text SHALL be preset from the template body

#### Scenario: a template for other case types is not offered
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a result template scoped to bezwaar case types
- **WHEN** a handler closes a vergunning case
- **THEN** that template SHALL NOT be offered

### Requirement: A template never overwrites what the handler wrote (REQ-TPL-07)

Picking a template SHALL NOT replace text the handler has already typed. A
handler who has written two paragraphs and then picks a template SHALL keep
their paragraphs.

This is the assertion that matters and it is stated as its own requirement so
it cannot be dropped as a detail of the one above. Losing typed text to a
convenience is worse than having no templates at all: the handler cannot get
it back, and the gesture that destroyed it looked like help.

#### Scenario: typed text survives a template
@e2e tests/e2e/starter-content-and-templates.spec.ts

- **GIVEN** a handler who has typed an outcome text on the close form
- **WHEN** they pick a result template
- **THEN** the text they typed SHALL still be there

#### Scenario: an empty form takes the template
@e2e exclude The empty-form half is covered by the vitest beside the guard above.

- **GIVEN** a close form whose outcome text is empty
- **WHEN** a handler picks a result template
- **THEN** the outcome text SHALL be the template body

### Requirement: The unreachable close form is retired once this one works (REQ-TPL-08)

`CaseTransitionConfirmDialog` SHALL be retired once the requirements above are
implemented and tested, and SHALL NOT be retired before. It is the only
component that mounts `TemplatePicker` today, so deleting it first would
remove the only implementation of a requirement that is still written down.

#### Scenario: the picker has one home, and it is reachable
@e2e exclude Structural, covered by tests/vitest/registryOrphans.spec.js.

- **WHEN** the retirement lands
- **THEN** exactly one component in `src/` SHALL mount `TemplatePicker`
- **AND** a manifest action SHALL name the surface that mounts it
