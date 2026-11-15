## ADDED Requirements

### Requirement: stored checksums are checked again and the check is recorded

A daily job MUST hash each stored document's file again, a batch per run, compare it with the stored `integrity.value`, and record `integrity.status` (unchanged, changed, missing, unchecked) and `integrity.checkedAt` on the document. It MUST NOT rewrite the stored hash or its date, and MUST leave a document without a stored hash alone.

#### Scenario: a file changed after it was stored
- GIVEN a document whose file no longer hashes to its stored value
- WHEN the check runs
- THEN the document reads status `changed` with the time of the check, and the stored hash is the original

### Requirement: the checksum card says when and how the file was last checked

The file's checksum card MUST show the time of the last check and its outcome in words, the outcome in the error tone when the file changed or is gone.

#### Scenario: DqDocumentenBeheer
- GIVEN a document checked this morning at 06:00 and unchanged
- THEN the card reads "Laatst gecontroleerd: vandaag, 06.00" and "Ongewijzigd"
