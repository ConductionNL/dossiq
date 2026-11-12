# Proposal: a-homed-document-keeps-its-record

## Why

DqDocumentenBeheer r39: the Controlegetal card of Offerte herplant essen.pdf is empty. The example set writes a document record, attaches its file to the record's own folder, then joins the record to its case, which moves the file into the case folder. The record named its file only after the move, so the move's own file event did not find it and projected a second record of the file. That second record got the checksum; the set's record never did.

## What changes

- When a join moves a document's file into its case, the record names its file before the move. The move's file event then finds that record, and the projection refreshes it (size, format, checksum) instead of writing a second one.
- When the storage gives the moved file a new id, the record is read again and saved with that id, so the refresh is kept.
