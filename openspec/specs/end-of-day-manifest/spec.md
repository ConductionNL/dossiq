# end-of-day-manifest Specification

## Purpose
What dossiq does for end of day manifest. The requirements were first written by the change `end-of-day-manifest` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-end-of-day-manifest/`) and cover: The day lists are worked out on the server; Each row names its case; The page is manifest config and keeps what the screen held.

## Requirements

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

### Requirement: Each row names its case

Each open and recorded row SHALL carry a meta line naming its case: for a case its number and the name of its status
("2026-4836 · In behandeling"), for a task the number and title of the case it is on ("bij 2026-4839 Kapvergunning essen
Parkweg"). A recorded row's meta line SHALL carry the case number and the time of the note ("2026-0110 · 15:40", for a
task "bij 2026-4836 · 11:15"). What cannot be read is left out of the line; the row stays.

#### Scenario: A task names its case

- **GIVEN** Pieter opened the task "Advies boomdeskundige beoordelen" on case 2026-4839 "Kapvergunning essen Parkweg" today
- **WHEN** he opens "Close out your day"
- **THEN** its row reads "bij 2026-4839 Kapvergunning essen Parkweg" under the title

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
