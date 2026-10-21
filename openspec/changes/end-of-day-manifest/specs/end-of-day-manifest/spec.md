# end-of-day-manifest Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: The day lists are worked out on the server

The end-of-day endpoint SHALL answer, for the signed-in reader and in the reader's time zone: the candidates (cases and
tasks on the reader's queue) the reader opened today according to OpenRegister's per-reader read state and has no note
of their own on today; the candidates with the reader's own note of today, with that note and its time; at most two
candidates not opened today whose term ends by tomorrow, soonest first; the figures; and the queue sources that could
not be read. A candidate changed today but not opened by the reader SHALL NOT count as opened.

#### Scenario: Recorded today after a reload

- **GIVEN** Pieter recorded a note on a case at 15:40 today
- **WHEN** he reloads "Close out your day"
- **THEN** the case is under "Recorded today" with the note and 15:40, and not under "Still open from today"

### Requirement: The page is manifest config and keeps what the screen held

"Close out your day" SHALL be a manifest dashboard on `/end-of-day` with: a note field per opened row recorded on the
row's own case or task, humaniq's hours leaf per row (none without humaniq, a sentence when humaniq is installed and the
leaf is not), a "ready to record" figure of typed notes kept in the browser, one button that records all, the recorded
list, the reader's figures, "First thing tomorrow" when something is due, how it works, and a breadcrumb to My work with
today's date. It SHALL have no time field of its own.

#### Scenario: Close out the day

- **GIVEN** two opened rows with a typed note each
- **WHEN** the reader presses "Close out your day"
- **THEN** each note lands on its own case or task and both rows move to "Recorded today"
