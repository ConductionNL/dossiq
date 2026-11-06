# Proposal: a-document-without-a-checksum-gets-one

## Why

DqDocumentenBeheer r39: the board draws a Controlegetal (SHA-256) for Offerte herplant essen.pdf; live has none. Documents written before their file (example sets, imports, and every set document on instances where a second record of the file took the checksum) never got one, and the daily check leaves a document without a stored checksum alone, so they never will.

## What changes

- The daily integrity check gives a document with a file but no stored checksum its first checksum, dated that check, and records the outcome "unchanged" with the time of the check.
- A document without a file, or with a file too large to hash, stays without a checksum.
- A stored checksum is still never rewritten.
