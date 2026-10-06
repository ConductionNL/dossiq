## ADDED Requirements

### Requirement: The grounds are settled against the law before they are seeded (REQ-WRG-001)

The list of Woo refusal grounds SHALL be settled by a person against the text of the Woo before
any ground is seeded. The settlement SHALL record the date, the source version of the law, every
ground with its code, article, paragraph, letter and label, and a mapping from each existing list
(opencatalogi 15, filinq 19, dossiq spec 12, dossiq code 10). The seed SHALL match the
settlement exactly.

#### Scenario: The seed is the settled list
- **GIVEN** the settlement in `design.md` D-2
- **WHEN** the register seed is imported on a fresh install
- **THEN** the `wooRefusalGround` objects SHALL be exactly the settled entries, with the settled codes and labels

### Requirement: One hierarchical list of grounds in dossiq's register (REQ-WRG-002)

dossiq SHALL hold the Woo refusal grounds as `wooRefusalGround` objects in its own register. Each
SHALL have `code`, `article`, `paragraph`, `letter`, `label`, `description`, `parent`, `status`
and `legalSource`. An entry SHALL be able to sit under a broader entry through `parent`, for
example 5.1.2.e under 5.1.2 under 5.1. A ground SHALL never be deleted. It SHALL be retired, and a
retired ground SHALL stay readable for the assessments and decisions that cite it.

#### Scenario: A narrower ground sits under a broader one
- **GIVEN** the seeded list
- **WHEN** the entry with code 5.1.2.e is read
- **THEN** its `parent` SHALL be the entry with code 5.1.2, whose `parent` is the entry with code 5.1

#### Scenario: A cited ground cannot be deleted
- **GIVEN** a ground cited on a stored `wooDocumentAssessment`
- **WHEN** an administrator tries to delete it
- **THEN** the delete SHALL be refused with the sentence that the ground can only be retired
- **AND** the ground SHALL still be readable

### Requirement: An administrator maintains the list (REQ-WRG-003)

Only a Nextcloud administrator, or a member of the group the dossiq admin names for it, SHALL
add, edit or retire a ground. Every authenticated user and the system context SHALL be able to
read the list. A dossiq settings page SHALL list the grounds as a tree and offer add, edit and
retire.

#### Scenario: An administrator adds a narrower ground
- **GIVEN** an administrator on the refusal grounds settings page
- **WHEN** they add a ground with code 5.1.2.e.1 and parent 5.1.2.e
- **THEN** it SHALL appear under 5.1.2.e in the tree

#### Scenario: A handler cannot change the list
- **GIVEN** a case handler who is not an administrator
- **WHEN** they try to edit a ground through the OpenRegister API
- **THEN** OpenRegister SHALL refuse the write with 403

### Requirement: Every change to the list is recorded (REQ-WRG-004)

Every creation, edit and retirement of a `wooRefusalGround` SHALL be recorded in OpenRegister's
audit trail with who, when, and the values before and after. The settings page SHALL show that
history per ground.

#### Scenario: A label edit is in the history
- **GIVEN** the ground 5.1.2.e
- **WHEN** an administrator changes its label and saves
- **THEN** the ground's history SHALL show that administrator, the time, the old label and the new label

### Requirement: dossiq validates assessments against the list (REQ-WRG-005)

`WOODocumentAssessmentService::validate()` SHALL accept a ground only when its code matches an
active `wooRefusalGround`. The constant `VALID_WEIGERINGSGRONDEN` and the `weigeringsgronden`
array in `lib/Settings/templates/woo-verzoek.json` SHALL be removed. When the list cannot be read,
validation SHALL refuse the assessment with a 503 sentence. It SHALL NOT accept any value, and it
SHALL NOT fall back to a constant.

#### Scenario: A settled ground is accepted
- **GIVEN** an active ground with code 5.1.2.e
- **WHEN** a handler assesses a document as deels_openbaar with ground 5.1.2.e
- **THEN** the assessment SHALL be stored

#### Scenario: An old code that no longer exists is refused
- **GIVEN** the settled list does not contain the code 5.2.5
- **WHEN** a handler assesses a document with ground 5.2.5
- **THEN** the assessment SHALL be refused, naming the unknown ground

#### Scenario: A retired ground cannot be cited on a new assessment
- **GIVEN** a retired ground
- **WHEN** a handler cites it on a new assessment
- **THEN** the assessment SHALL be refused, naming the ground as retired

### Requirement: Stored codes are mapped, never guessed (REQ-WRG-006)

A repair step SHALL map the grounds stored on existing `wooDocumentAssessment` and `decision`
objects to the settled codes, using the D-2 mapping. A stored code without an unambiguous mapping
SHALL be left as it is, flagged, and listed in the repair output and on the admin page. The step
SHALL be idempotent.

#### Scenario: An ambiguous code is reported
- **GIVEN** an assessment that stores the dossiq code 5.1.4, which D-2 maps to nothing unambiguous
- **WHEN** the repair step runs
- **THEN** the assessment SHALL keep 5.1.4, flagged as unmapped
- **AND** the repair output SHALL list that assessment

### Requirement: Other apps read the list through one named method (REQ-WRG-007)

dossiq SHALL expose `OCA\Dossiq\Woo\WooRefusalGrounds::list(bool $includeRetired = false)` and
`byCode(string $code)`, returning the keys `id, code, article, paragraph, letter, label,
description, parent, status, legalSource`. The read SHALL run as the system. When the register
cannot be read, the method SHALL throw `WooRefusalGroundsUnavailable` and SHALL NOT answer an
empty list.

#### Scenario: A consumer reads the active list
- **GIVEN** the seeded list and one retired ground
- **WHEN** `list()` is called from a background job with no user
- **THEN** it SHALL answer every active ground, in code order, with all ten keys
- **AND** the retired ground SHALL be absent unless `includeRetired` is true

#### Scenario: An unreadable register is not an empty list
- **GIVEN** OpenRegister answers an error for the grounds search
- **WHEN** `list()` is called
- **THEN** it SHALL throw `WooRefusalGroundsUnavailable`

### Requirement: A release-time snapshot ships for the redaction fallback (REQ-WRG-008)

dossiq SHALL ship `lib/Settings/woo-refusal-grounds.snapshot.json` holding the seeded active
list, in the shape of `list()`, with a version. A check in `check:strict` SHALL fail when the
snapshot and the seed differ. The snapshot SHALL be read-only data for other apps to vendor. It
SHALL be used only for citing a ground on a redacted passage when dossiq is absent (D12).

#### Scenario: A seed edit without a snapshot update fails the check
- **GIVEN** a change that edits a ground's label in the seed
- **WHEN** `composer check:refusal-grounds-snapshot` runs without the snapshot regenerated
- **THEN** it SHALL exit non-zero and name the ground that differs
