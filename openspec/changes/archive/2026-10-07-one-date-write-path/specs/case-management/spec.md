## ADDED Requirements

### Requirement: Every date on a case is written through one path (REQ-CM-45)

Every write path that sets a date or a moment on a case, or on an object
linked to a case, SHALL normalise it through `CaseDateNormaliser`. A
calendar date SHALL be stored as `Y-m-d`. A moment SHALL be stored as
ATOM with an explicit offset. No other class in `lib/` SHALL parse or
format a date.

An unreadable value SHALL be refused at the write path. It SHALL NOT be
stored raw, and it SHALL NOT be replaced by today.

#### Scenario: the same date through every write path stores one value
@e2e tests/e2e/one-date-write-path.spec.ts

- **GIVEN** a tenant whose time zone is `Europe/Amsterdam`
- **WHEN** `2028-01-31` is submitted through each of the nine write paths that set a case date
- **THEN** every stored value SHALL be the same string
- **AND** each SHALL carry the offset the tenant zone gives that date

#### Scenario: an unreadable date is refused, not guessed
@e2e tests/e2e/one-date-write-path.spec.ts

- **GIVEN** an advice request with `deadline` set to `31-01-2028`
- **WHEN** it is submitted
- **THEN** the write SHALL be refused with a message naming the field
- **AND** no advice request SHALL be created
- **AND** the deadline SHALL NOT default to today

#### Scenario: a date written yesterday still reads the same today

- **GIVEN** a case whose `endDate` was written before this change
- **WHEN** the case is read
- **THEN** the stored value SHALL be returned unchanged

### Requirement: The time zone is administered and read once (REQ-CM-46)

The zone in which a case date is interpreted SHALL come from the
administered setting. `CaseDateNormaliser` SHALL be the only class that
resolves it. It SHALL read the engine calendar's zone when openregister
`calendar-time-zone` is available, and `tenantConfiguration.timezone`
otherwise. No class in `lib/` SHALL name an IANA zone as a literal.

#### Scenario: a Belgian tenant gets Belgian timestamps
@e2e tests/e2e/one-date-write-path.spec.ts

- **GIVEN** a tenant whose `tenantConfiguration.timezone` is `Europe/Brussels`
- **WHEN** a case is created and a StUF message is built for it
- **THEN** both SHALL carry the offset for `Europe/Brussels`

#### Scenario: the engine calendar wins over the tenant setting

- **GIVEN** a working calendar declaring `Europe/Amsterdam` and a tenant declaring `UTC`
- **WHEN** a case date is normalised
- **THEN** the calendar's zone SHALL be used

#### Scenario: no calendar falls back to the tenant

- **GIVEN** an instance with no working calendar configured
- **WHEN** a case date is normalised
- **THEN** the tenant zone SHALL be used
- **AND** the fallback SHALL be recorded in the log once per request, not per date

### Requirement: A second date path cannot be added unnoticed (REQ-CM-47)

A structural test SHALL fail when a class in `lib/` other than
`CaseDateNormaliser` parses or formats a date, and when a private method
in `lib/` normalises one. The failure SHALL name `CaseDateNormaliser` and
the method the author should call instead.

#### Scenario: a new private normaliser fails the build

- **GIVEN** a new private method in a service that parses a date string
- **WHEN** the structural test runs
- **THEN** it SHALL fail
- **AND** the message SHALL name the file, the method and `CaseDateNormaliser`

#### Scenario: a hard-coded zone fails the build

- **GIVEN** a new `new DateTimeZone('Europe/Amsterdam')` anywhere in `lib/`
- **WHEN** the structural test runs
- **THEN** it SHALL fail

#### Scenario: the normaliser itself is allowed

- **GIVEN** `lib/Service/CaseDateNormaliser.php` parsing and formatting dates
- **WHEN** the structural test runs
- **THEN** it SHALL pass
