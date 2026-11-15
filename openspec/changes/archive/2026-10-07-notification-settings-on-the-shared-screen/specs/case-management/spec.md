# Spec delta: case management

## ADDED Requirements

### Requirement: dossiq's notification settings are the shared screen

dossiq's notification settings SHALL render `CnNotificationMatrix` rather
than a second implementation of the same screen, so that a handler sees which
of their preferences an administrator has overridden and why.

#### Scenario: an administrator has forced a channel

- **GIVEN** a handler has switched a notice off for themselves
- **AND** an administrator has forced that notice on
- **WHEN** the handler opens their notification settings
- **THEN** the row SHALL be locked
- **AND** it SHALL name who forced it and the reason they gave
- **AND** it SHALL NOT read as a setting the handler made

#### Scenario: an administrator has forced a channel off

- **GIVEN** an administrator has forced a notice OFF
- **WHEN** the handler opens their notification settings
- **THEN** the row SHALL render as off and locked
- **AND** it SHALL NOT be switched on, because a forced row read as "always on"
  would switch on a channel an administrator forbade

#### Scenario: the platform refuses a channel for this recipient

- **GIVEN** the platform refuses a notice for this recipient with a reason
- **WHEN** the handler opens their notification settings
- **THEN** the row SHALL state the rule
- **AND** it SHALL NOT read as a channel the instance has not configured, which
  would send somebody to change a configuration that is not the cause

#### Scenario: a layer nobody answered for

- **GIVEN** the platform returns an effective value and the source that decided it
- **AND** the source is the handler's own override
- **WHEN** the screen renders
- **THEN** the shipped default underneath SHALL NOT be inferred from the
  effective value, because a made-up value would render on a layer the screen
  displays as fact

#### Scenario: the platform has no channel axis

- **GIVEN** the platform answers with one value per notification and no channels
- **WHEN** the screen renders
- **THEN** it SHALL render exactly one column, named for what it is
- **AND** it SHALL NOT fabricate a column per channel, because a handler's
  click would be collapsed onto the single value that exists

#### Scenario: a write the platform refuses

- **GIVEN** a handler changes a setting
- **AND** the platform refuses the write
- **WHEN** the refusal comes back
- **THEN** the screen SHALL say so
- **AND** it SHALL re-read, so the switch shows what the platform stored rather
  than where the click put it
