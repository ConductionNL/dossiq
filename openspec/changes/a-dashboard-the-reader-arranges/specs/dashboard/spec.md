## ADDED Requirements

### Requirement: REQ-DASH-020 A reader keeps their own arrangement of a dashboard

Every dashboard page in this app SHALL declare `config.userLayout: true`, so
a user keeps their own arrangement of it without changing what anybody else
sees.

It SHALL declare `config.appId` beside it. The key on its own does nothing:
`CnDashboardPage` returns early from both the load and the save unless an app
id is set, and the manifest renderer passes none of its own, so a page
carrying only `userLayout` reads and writes no arrangement and looks exactly
like one that works to a reader who never drags a widget.

It SHALL declare `config.pageId` as well, naming the page. Left out, the
stored arrangement is keyed on a slug of the page TITLE, so editing a heading
silently orphans every arrangement anybody had.

The manifest SHALL decide which widgets exist and the user SHALL decide
where they sit. A widget an administrator removes from the manifest SHALL
disappear for every user, stored arrangement or not, because an arrangement
that resurrects a widget an administrator deliberately took off the page
puts it back with nothing on screen to say why.

#### Scenario: One user's arrangement is their own

- **GIVEN** two handlers both open the Dashboard
- **WHEN** the first moves the Overdue card to the top row
- **AND** reloads the page
- **THEN** the first handler SHALL see the card where they left it
- **AND** the second handler SHALL see the arrangement the manifest ships

@e2e tests/e2e/a-dashboard-the-reader-arranges.spec.ts

#### Scenario: An administrator's removal wins

- **GIVEN** a handler has arranged a dashboard that includes a widget
- **WHEN** an administrator removes that widget from the manifest
- **THEN** the widget SHALL NOT appear for that handler

@e2e exclude The merge rule is a pure function in the library and is asserted at its edges there; this app declares the key and adds no code path.

#### Scenario: An instance with no preference route renders the manifest

- **GIVEN** an instance whose preference endpoint is unavailable
- **WHEN** a handler opens a dashboard
- **THEN** the page SHALL render the arrangement the manifest ships
- **AND** SHALL NOT show an error

@e2e exclude Failure path in the library; asserted by its own suite.

### Requirement: REQ-DASH-021 The two lists a handler wants are offered by name

The Dashboard page SHALL declare `config.userWidgets` carrying the case
lists a handler would ask for and cannot configure: the cases they follow,
and their team's shared queue.

A preset SHALL carry its own register, schema and filter. A user has neither
a register nor a schema in front of them, so a widget type that asks for one
is a widget they cannot finish, and the failure arrives as a blank card
rather than a refusal.

The second list is the SHARED QUEUE and not the reader's `assignedGroup`.
Measured 2026-09-18, nothing can express "assigned to my team": the library's
`resolveFilterTokens` resolves `@me`, `@now`, `@today`, `@today±Nd`,
`@monthStart`, `@quarterStart` and `@yearStart` and no group token, the
manifest schema's `sentinelFilterToken` pattern enumerates exactly those, and
OpenRegister reserves no group lens. A preset filtering on an unresolved token
sends the literal string, matches nothing, and arrives as a permanently empty
card, which is the failure this requirement was written to prevent. The
group-scoped variant waits on a `@myGroups` token in nextcloud-vue.

#### Scenario: A handler adds a list they did not have to configure

- **GIVEN** a handler on the Dashboard
- **WHEN** they open the add-widget modal
- **THEN** they SHALL be offered "Cases you follow" and "Your team's queue"
- **AND** adding one SHALL render the list without asking for a register or
  a schema

@e2e tests/e2e/a-dashboard-the-reader-arranges.spec.ts
