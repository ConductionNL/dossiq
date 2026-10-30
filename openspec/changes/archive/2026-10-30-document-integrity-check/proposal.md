# Stored document checksums are checked again

## Why

DqDocumentenBeheer's "Controlegetal" card says when the file was last
checked ("Laatst gecontroleerd: vandaag 06:00"). Dossiq hashed each file
once, when it was stored, and nothing checked it again, so the card could
only say when the hash was computed. The coordinator decided (24 Oct) to make
the board true. Neither OpenRegister nor Files has a checksum verifier to
reuse: OpenRegister's e-depot PackagedFileChecksum hashes a file only while
packaging it.

## What changes

- DocumentIntegrityVerifier: a batch of documents per run, each file hashed
  again the way it was hashed when stored (DocumentDefaults::integrityOf) and
  compared with `integrity.value`; `integrity.status` (unchanged, changed,
  missing, unchecked) and `integrity.checkedAt` are written on the document;
  the stored hash and its date are never rewritten. A cursor in the app
  config walks all documents and starts over.
- DocumentIntegrityCheckJob: daily, 500 documents, as the background service
  account.
- informatieobject schema 1.3.0: `integrity.status`, `integrity.checkedAt`.
- The checksum side card shows "Last checked" and the outcome, in the error
  tone when the file changed or is gone.
