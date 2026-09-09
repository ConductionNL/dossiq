## ADDED Requirements

### Requirement: An anchor names the capability, not where its spec currently lives

A `@spec` or `@e2e` tag in code or in a test SHALL name a capability in its
canonical spelling, `openspec/specs/<capability>/spec.md`, optionally with a
`#fragment` naming a requirement in it.

A tag SHALL NOT name a change directory. `openspec/changes/<change>/tasks.md`,
`proposal.md` and `design.md` name no capability at all, so nothing can carry
them forward when the change is archived and its directory moves.

An anchor is written once and never rewritten. Archiving moves a spec's file and
must not move its anchors, because an anchor that has to be rewritten on archive
turns every archive into a breakage of the same kind.

#### Scenario: A tag survives its change being archived
- **GIVEN** code tagged `@spec openspec/specs/case-types/spec.md`
- **WHEN** the change that introduced that requirement is archived
- **THEN** the tag SHALL still resolve
- **AND** no edit to the tagged file SHALL be required

#### Scenario: A tag naming a change directory is not the convention
- **WHEN** a new tag is written
- **THEN** it SHALL name `openspec/specs/<capability>/spec.md`
- **AND** it SHALL NOT name `openspec/changes/<change>/tasks.md` or that change's proposal or design

### Requirement: An anchor may name a capability whose canonical spec does not exist yet

When the governing requirement lives in a change that has not been archived, the
anchor SHALL still name `openspec/specs/<capability>/spec.md`, even though no
file stands at that path yet. The anchor is early, not wrong: archiving is the
moment the content arrives at the path the anchor already named.

Resolution SHALL therefore be by capability across the three homes a spec has in
its life, and SHALL be satisfied by any of them:

1. in flight, `openspec/changes/<change>/specs/<capability>/spec.md`
2. archived, `openspec/changes/archive/<date>-<change>/specs/<capability>/spec.md`
3. canonical, `openspec/specs/<capability>/spec.md`

An anchor naming a capability with none of the three SHALL be reported. That is
the real dangling case, and widening resolution MUST NOT widen what counts as
resolved: a fragment still has to name a requirement somebody wrote.

#### Scenario: A pending capability resolves through the open change
- **GIVEN** a capability declared only in an open change's delta
- **WHEN** an anchor names its canonical `openspec/specs/<capability>/spec.md` path
- **THEN** the anchor SHALL resolve through the change's delta
- **AND** it SHALL keep resolving after that change is archived, unedited

#### Scenario: A capability with no home at all is reported
- **GIVEN** an anchor naming a capability that appears in no open change, no archived change and no canonical spec
- **WHEN** anchors are checked
- **THEN** the anchor SHALL be reported as unresolved

### Requirement: An `@e2e` anchor is held to the same rule as `@spec`

`@e2e` tags SHALL follow the same convention and SHALL be checked the same way.
An anchor nobody checks is an anchor that rots without telling anyone: all six
dangling anchors found in this repo were `@e2e`, and every one of them named a
capability that has never existed in any of the three homes.

#### Scenario: A dangling `@e2e` anchor is caught
- **GIVEN** a test tagged `@e2e openspec/specs/<capability>/spec.md` for a capability with no home
- **WHEN** anchors are checked
- **THEN** the anchor SHALL be reported, exactly as the `@spec` spelling would be
