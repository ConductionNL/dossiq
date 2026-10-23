# Proposal: the Objects index looks like All cases

## Why

Ruben (23 Oct) opened Objects on :8080 as admin and got a list card holding only "All objects", "Er is een fout opgetreden" beside it and an add button reading "Onderdeel toevoegen" (the library's generic "Add Item"). The page is an ordinary `index` over `dossiq`/`caseObject`; what he saw was the error state while OpenRegister was not answering (the EXSET-LAND window), and the add button falls back to the generic word whenever the schema has not been read. Measured afterwards, the page loads its 65 rows as admin and as pieter. What remains is that it is plainer than All cases.

## What changes

- A fixed add label, "Add case object", so the button never reads "Add Item".
- The All cases look: a search placeholder that names what is searched, the sidebar's count line, export, and a table-only view switch (an object has no status for a board and no place for a map).

## Not changed

The columns, the object-type folder list, saved views, the View case row action and the Data model card.
