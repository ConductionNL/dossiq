## MODIFIED Requirements

### Requirement: stored checksums are checked again and the check is recorded

A daily job MUST hash each stored document's file again, a batch per run, compare it with the stored `integrity.value`, and record `integrity.status` (unchanged, changed, missing, unchecked) and `integrity.checkedAt` on the document. It MUST NOT rewrite the stored hash or its date.

#### Scenario: a file changed after it was stored
- GIVEN a document whose file no longer hashes to its stored value
- WHEN the check runs
- THEN the document reads status `changed` with the time of the check, and the stored hash is the original

## ADDED Requirements

### Requirement: a document without a stored checksum gets one on its first check

When the daily check meets a document that has a file but no stored `integrity.value`, it MUST hash the file and store that checksum with the time it was taken as `integrity.date`, status unchanged and `integrity.checkedAt`. A document without a file, or whose file is too large to hash, MUST stay without a checksum.

#### Scenario: an example set document
- GIVEN "Offerte herplant essen.pdf" was written by the example set and has no checksum
- WHEN the daily check runs
- THEN the document carries its SHA-256 checksum, dated that check, with outcome unchanged
- AND the Controlegetal card shows it
