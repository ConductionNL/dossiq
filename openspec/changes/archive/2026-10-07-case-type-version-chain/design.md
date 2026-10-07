# Design: case-type-version-chain

## D-1. The chain is a filter, not a relation walk

Every version of a case type shares its `identifier`. The Version chain panel
is an `object-list` over `caseType` with `filter.identifier: @object.identifier`
and `sort: version desc`. No recursion over `previousVersion`, which stays
the audit link REQ-ZV-02 reads.

## D-2. New version is the endpoint that exists

`api-call` to `POST /api/case-definitions/{id}/new-version`, then navigate to
the returned id. The copy service already sets `previousVersion` and
`isDraft`.

## D-3. Deprecate is a field write with a guard

Deprecate writes `validUntil = @today` through the object store. It is
visible only when `supersededBy` is set and `isDraft` is false, so you cannot
retire the only live version.

## D-4. The index shows one row per type

`#CaseTypes` filters `supersededBy IS NULL` by default; the chip All versions
drops the filter. The existing `folderSidebar` on `category` is unchanged.
