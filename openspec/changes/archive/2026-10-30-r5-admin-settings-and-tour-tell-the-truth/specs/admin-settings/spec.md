# admin-settings Delta: r5-admin-settings-and-tour-tell-the-truth

## ADDED Requirements

### Requirement: The search index panel shows when maintenance last ran, readably

The search index section SHALL show each figure with its label above its
value, and a label SHALL wrap rather than run under the value. "Last run"
SHALL show the time OpenRegister's last stored run finished (or started, when
it has no finish time) as a date and time, and "Never" when OpenRegister
reports no run.

#### Scenario: Maintenance never ran
<!-- @e2e exclude Read-model normalisation; proven by tests/vitest/searchIndexApi.spec.js (fails on the old String() of the report) and live on :8099. -->
- **GIVEN** OpenRegister answers `lastRun: []`
- **WHEN** the admin opens the search index section
- **THEN** "Last run" reads "Never"

#### Scenario: Maintenance ran
<!-- @e2e exclude Read-model normalisation; proven by tests/vitest/searchIndexApi.spec.js. -->
- **GIVEN** OpenRegister answers `lastRun: {startedAt: "2026-10-01T02:00:00+00:00", finishedAt: "2026-10-01T02:03:00+00:00", state: "done"}`
- **WHEN** the admin opens the search index section
- **THEN** "Last run" shows 1 October 2026 at the finish time, and never "[object Object]" or an empty value

### Requirement: Status badges are readable in light and dark

A status badge or mark on the admin settings page SHALL take its colours from
Nextcloud's status variables in matching pairs: `--color-success`,
`--color-error` or `--color-warning` as fill with `--color-success-text`,
`--color-error-text` or `--color-warning-text` as ink, or the `-text`
variable alone on the page background. A badge SHALL NOT use a status fill
variable as ink, and SHALL NOT put white or `--color-main-background` ink on a
status fill.

#### Scenario: A present mark
<!-- @e2e exclude Style contract; proven by tests/vitest/adminSettingsTruth.spec.js, which scans the settings styles, and the live contrast read on :8099. -->
- **GIVEN** PHP is present
- **WHEN** the prerequisites section draws its row
- **THEN** the word "present" is drawn in `--color-success-text`

### Requirement: The Nextcloud prerequisite says whether this instance is in range

`Prerequisites::check()` SHALL report, beside the supported range, the major
version this instance runs and `present: true` only when it lies within the
range. The prerequisites section SHALL draw that row with a present or missing
mark like every other row.

#### Scenario: An instance inside the range
<!-- @e2e exclude Server read; proven by tests/Unit/PrerequisitesTest.php::testTheNextcloudRowSaysWhetherThisInstanceIsInRange and tests/vitest/prerequisitesBlock.spec.js. -->
- **GIVEN** the range 32 to 35 and an instance on 34
- **WHEN** the admin opens the prerequisites section
- **THEN** the Nextcloud row reads "present", and names 34

### Requirement: An optional app shows its current product name and keeps its lookup id

Every app row SHALL carry a display `name` beside its `id`. The `id` SHALL
stay the key passed to `IAppManager::isInstalled()` until that app's own
`<id>` moves. The section SHALL show the `name`.

#### Scenario: A renamed app whose id has not moved
<!-- @e2e exclude Server read; proven by tests/Unit/PrerequisitesTest.php::testARenamedAppShowsItsNewNameAndKeepsItsLookupId. -->
- **GIVEN** the optional app with id `docudesk`
- **WHEN** the admin opens the prerequisites section
- **THEN** the row reads "filinq"
- **AND** the instance is asked whether `docudesk` is installed

### Requirement: Case type management shows a small set of columns, title first

The case type list in the admin settings SHALL show title, identifier,
published or draft, processing deadline and valid from, in that order.

#### Scenario: A schema with 96 properties
<!-- @e2e exclude Column derivation; proven by tests/vitest/caseTypeColumns.spec.js. -->
- **GIVEN** the case type schema declares 96 properties
- **WHEN** the admin opens case type management
- **THEN** the table has those five columns and the first is the title

### Requirement: A section does not repeat its own name

A tab rendered inside a settings section SHALL NOT start with a heading that
repeats the section's name. The admin settings page SHALL show one "Case
email: shared mailbox" section.

#### Scenario: The checklists section
<!-- @e2e exclude Template contract; proven by tests/vitest/adminSettingsTruth.spec.js and live on :8099. -->
- **GIVEN** the admin opens the VTH inspection checklists section
- **THEN** the section name appears once

#### Scenario: The delegated email form
<!-- @e2e exclude Template contract; proven by tests/Unit/Settings/EmailSettingsTest.php and live on :8099. -->
- **GIVEN** the `EmailSettings` registration stays for delegated administration
- **WHEN** the settings page renders its form
- **THEN** the form draws nothing, and the mailbox settings appear once, in the main page

### Requirement: Headings are in sentence case

Every section name and heading on the admin settings page SHALL be in
sentence case in English and in Dutch: only the first word, acronyms and
proper names start with a capital.

#### Scenario: A Title Case heading
<!-- @e2e exclude Copy contract; proven by tests/vitest/adminSettingsTruth.spec.js. -->
- **GIVEN** a section named "Case Type Management"
- **THEN** `tests/vitest/adminSettingsTruth.spec.js` fails

### Requirement: Empty states carry text

An empty state on the admin settings page SHALL say what is empty.

#### Scenario: Tenant onboarding without a tenant
<!-- @e2e exclude Template contract; proven by tests/vitest/adminSettingsTruth.spec.js and live on :8099. -->
- **GIVEN** no tenant is selected
- **THEN** tenant onboarding reads "Select a tenant to view onboarding progress."

### Requirement: What shipped reports objects that did not come from a starter set

When the shipped ledger holds no rows for the chosen kind, "What shipped with
dossiq" SHALL count the objects of that kind in the register. When there are
any, it SHALL say how many there are and that none came from a starter set.
It SHALL say nothing has been seeded only when there are none.

#### Scenario: 24 case types from the register import
<!-- @e2e exclude Copy and count; proven by tests/vitest/shippedConfiguration.spec.js and live on :8099. -->
- **GIVEN** the ledger is empty and the register holds 24 case types
- **WHEN** the admin opens "What shipped with dossiq"
- **THEN** it says 24 are here and none came from a starter set
