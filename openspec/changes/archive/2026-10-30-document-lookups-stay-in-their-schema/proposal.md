# Proposal: document-lookups-stay-in-their-schema

## Why

DqDocumentenBeheer r37: the Bestanden rows and the side cards of a set document read a second record of the same file, with no sender, "Geadresseerden —" and the install day as its date. On the dev instance 23 files each have two records, written one second apart. The example set writes a document record, then attaches its file to the record's own folder. That file event asks whether the folder's uuid is a case, and OpenRegister's find answers a uuid from any schema of the register when it is not in the one named. So the document passed for a case, and the projection made a second record of the file, joined to the document. When the file later moved into the real case, that second record joined the case as well.

## What changes

- The document record store answers a case, a record or a type only when the row OpenRegister found lives in the schema it asked for. A row that names another schema is not found.
- A repair step keeps one record per file: each later record of a file goes with its joins, once. A later record with a folder of its own stays and is named in the log.
