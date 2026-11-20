# Proposal: the Woo request case type takes the five steps the boards draw, as a new version

## Why

Every dossiq case board draws a Woo request in five steps: Ontvangen, In behandeling, Besluit, Publiceren, Afgehandeld (DqZaak and all its tab and dialog boards, DqMijnWerk's stage chart, DqWerkbord's columns). The seeded Woo case type has eight. Changing its statuses in place would move the status of every running case, so the five steps come as a second version of the case type, and running cases keep theirs.

A second version touches more than configuration: a portal request is filed on the first version's fixed uuid, the Woo-only clarification checks that uuid, and the "Woo requests" view, quick filter and six Woo-only actions filter on it. A case on the new version would not be a Woo request to any of them.

## What changes (in three branches)

1. Generic (this change's first part):
   - A case carries `caseTypeIdentifier`, the identifier every version of its case type shares (the version chain is read by identifier), materialised from the case type. `BackfillCaseTypeIdentifier` fills it on existing cases, on the same base as the statusRole backfill (`BackfillReferencedCaseValue`).
   - The "Woo requests" view and quick filter and the six Woo-only header actions read `caseTypeIdentifier: woo-verzoek`.
   - A portal request is filed on the version nothing has superseded (`SystemCaseStore::currentCaseType`, following `supersededBy`).
   - A clarification may be asked on every version of the Woo case type.
2. Configuration: Woo case type version 2 with the five statuses, its own result, decision and property types and its flow; version 1 superseded.
3. Example data: the Zuiddrecht Woo cases on version 2.

Decisions (coordinator, 23 Oct): "In behandeling → Besluit" carries all of version 1's in-between guards; "Besluit → Publiceren" keeps `requiresSettled`; not admissible and withdrawn go straight to Afgehandeld; "Wacht op inwoner" is not a step; filters follow the whole version chain, generically.
