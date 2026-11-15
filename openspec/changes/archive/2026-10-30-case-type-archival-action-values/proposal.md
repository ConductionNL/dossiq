# Map stored legacy archival actions onto the enum

## Why

The retired admin settings case type editor wrote `retain` and `destroy` to a
result type's `archivalAction`. The resultType schema declares
`bewaren | vernietigen | blijvend_bewaren`, so those rows hold values the
schema refuses on their next save and no archival consumer recognises.
Ruben decided a one-time repair step maps them.

## What changes

- Repair step MapLegacyArchivalActions, post-migration after the register
  import: `retain` becomes `bewaren`, `destroy` becomes `vernietigen`.
- It reads and writes as the system user, leaves every other value alone,
  runs once behind `legacy_archival_actions_mapped`, and leaves the gate open
  when a write fails, so the next upgrade retries.
