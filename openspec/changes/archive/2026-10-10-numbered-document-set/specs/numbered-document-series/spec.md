# Delta: numbered-document-series

## ADDED Requirements

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
