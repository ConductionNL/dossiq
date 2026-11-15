# Numbered document series

## Purpose

Documents a case issues to the outside world, such as a beschikking, carry a number a reader can
cite. This capability issues those numbers for any kind of document: one running number per
series, per organisation, per calendar year. It knows nothing about any procedure. The issuing
code names the series; the case type configures the prefix (decisions 167 and 182).

## Requirements

### Requirement: A document series numbers per organisation per year (REQ-NDS-001)

The system SHALL issue the next number of a named document series as
`<prefix>-<year>-<six digits>`, counting per series, per organisation and per calendar year. The
organisation SHALL be the one the case belongs to; a case that names none SHALL count in one
instance-wide row. A number SHALL never be issued twice; a gap left by a failed save is allowed.
When no number can be reserved, the system SHALL refuse with HTTP 503 and the rule
`document-number-unavailable`, and the issuing code SHALL NOT save the document.

#### Scenario: Each organisation numbers its own year

- **GIVEN** organisation A has issued `B-2026-000041` and organisation B `B-2026-000007` in the series `beschikking`
- **WHEN** the next document of that series is issued on a case of organisation A
- **THEN** it SHALL be numbered `B-2026-000042`
- **AND** organisation B's next SHALL be numbered `B-2026-000008`
- **AND** on 1 January the next of either SHALL be numbered `B-<new year>-000001`

#### Scenario: Two series keep two counters

- **GIVEN** an organisation that has issued `B-2026-000003` in one series
- **WHEN** it issues the first document of another series
- **THEN** that document SHALL be numbered `<its prefix>-2026-000001`

#### Scenario: No number, no document

- **GIVEN** the counter cannot be reached
- **WHEN** a document is issued
- **THEN** the response SHALL be HTTP 503 naming `document-number-unavailable`
- **AND** nothing SHALL be saved

### Requirement: The case type configures a series' prefix (REQ-NDS-002)

A case type SHALL be able to configure, per series, the prefix printed before the number, under
`documentSeries.<series>.prefix`. A series the case type does not configure, or a case type that
cannot be read, SHALL print the issuing code's default prefix. The prefix SHALL NOT affect the
count: the series is counted, not the prefix.

#### Scenario: A case type prints its own prefix

- **GIVEN** a case type that configures `documentSeries.beschikking.prefix` as `BES`
- **WHEN** a beschikking is issued on a case of that type
- **THEN** its number SHALL start with `BES-`
- **AND** it SHALL take the next number of the organisation's `beschikking` series for that year

### Requirement: A correction is a new number pointing at its predecessor (REQ-NDS-003)

A document of a series that is corrected or withdrawn after it was issued SHALL be followed by a
new document with its own next number of the same series. The new document SHALL record which
document it replaces, and the replaced document SHALL record which one replaced it. A replaced
document SHALL NOT be replaced a second time. How each kind of document freezes and which kinds
of successor it allows is the kind's own requirement (for a beschikking, REQ-BES-012).

#### Scenario: The correction takes the next number

- **GIVEN** a document numbered `B-2026-000123`
- **WHEN** it is corrected
- **THEN** the correction SHALL carry the series' next number, not `B-2026-000123` with a suffix
- **AND** the two SHALL point at each other

### Requirement: A set of documents is numbered in a stable, configured order (REQ-NDS-004)

The system SHALL number the documents of one set (the documents of a dossier, an inventory list,
an export) in the order the series configures under `documentSeries.<series>.order`: a list of
sort keys, each a field and a direction (`asc` or `desc`), first key first. Documents equal on
every key SHALL keep the order in which they were read, and a document without a value for a key
SHALL sort after every document that has one. Numeric values SHALL compare as numbers. The
printed number SHALL be the sequence zero-padded to `documentSeries.<series>.width` digits
(default 2, at most 9), preceded by the series' prefix and a hyphen when it has one. A series
value outside what the schema allows SHALL be refused, never replaced by a default. The
capability SHALL NOT know what kind of document it numbers.

#### Scenario: The same set prints the same numbers

- **GIVEN** a case type whose series `exhibit` orders by `filedOn` ascending, then `title`, with prefix `X` and width 3
- **WHEN** three documents filed on 1 March, 1 January (`Beta`) and 1 January (`Alpha`) are numbered
- **THEN** they SHALL be numbered `X-001` (Alpha), `X-002` (Beta) and `X-003` (the March one)
- **AND** numbering the same set again SHALL print the same numbers

#### Scenario: A filename sorts like the number

- **GIVEN** a document numbered 1 in a series of width 2, titled `First letter`, stored as a `.docx`
- **WHEN** its export filename is made
- **THEN** it SHALL be `01-first-letter.docx`

### Requirement: A series declares what a removed document does to the numbers (REQ-NDS-005)

A series SHALL declare its gap policy under `documentSeries.<series>.gapPolicy`. Under `renumber`
(the default) every listing SHALL number the set 1 to n, so a removed document leaves no gap.
Under `keep` a document that already carries a number in the series' number field (default
`sequence`) SHALL keep it whatever the order says, a document without one SHALL take the next
numbers after the highest kept number, in order, and a removed document SHALL leave its gap. A
number SHALL never be printed twice in one set: when two documents carry the same number, the
first in order keeps it and the other is numbered as new.

#### Scenario: A cited number stays with its document

- **GIVEN** a series with gap policy `keep` and documents numbered 1 and 3, number 2 having been removed
- **WHEN** two new documents are added and the set is numbered
- **THEN** the documents numbered 1 and 3 SHALL keep those numbers
- **AND** the new documents SHALL be numbered 4 and 5

#### Scenario: Renumbering closes the gap

- **GIVEN** a series with gap policy `renumber` and documents that were numbered 1 and 3
- **WHEN** the set is numbered
- **THEN** they SHALL be numbered 1 and 2
