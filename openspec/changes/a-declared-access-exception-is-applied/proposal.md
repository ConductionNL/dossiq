# Proposal: a-declared-access-exception-is-applied

## Why

DqDocumentenBeheer: the board restricts Offerte herplant essen.pdf to "Alleen Pieter Jansen en team Vergunningen" (reason: Bedrijfsgevoelige prijzen). Only DocumentAccessExceptionWriter could apply an exception, because applying one is more than storing it: the history gains an entry, the readers OpenRegister tests (`restricted`, `accessUsers`, `accessGroups`, `accessHandlers`) are derived, and the file is restricted in OpenRegister. An example set or an import can store `accessException` (as a system write), but nothing applied it, so a set could not carry one.

## What changes

- When a document is stored with an exception that its history does not hold yet, dossiq applies it as the writer does: a `set` (or `change`) history entry by `setBy` at `setAt`, the derived readers saved as the background service account, and the file restricted.
- When such a document names its file later (the set writes the record before the file), the file is restricted then.
- A document whose readers drifted from its exception (for example a join added later, which adds the case's handler) gets them derived again.
- Nothing is lifted: applying a stored exception only narrows who reads the document and its file. Only the background service account and system writes can store `accessException` (property authorization), so this adds no new way to set one.
- The Zuiddrecht example set declares the exception on Offerte herplant essen.pdf.
