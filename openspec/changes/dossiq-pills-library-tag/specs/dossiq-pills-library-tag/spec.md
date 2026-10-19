# dossiq-pills-library-tag Delta

## ADDED Requirements

### Requirement: dossiq tags are the library tag

A status, priority or label pill in dossiq SHALL render as nextcloud-vue's tag (`cn-status-badge` with a variant) and
SHALL declare no shape of its own (padding, corner, size, weight, line height); a local rule MAY colour it and then SHALL
set `--cn-status-badge-ink` to its text colour.

#### Scenario: The Cases status column in the board look

- **GIVEN** the cases index in the board look
- **THEN** each status tag is the board tag (13px at 600, 12px corner, 21px) in its status colour
