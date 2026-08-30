# Design: related-case-linking

## Storage

No new schema. The existing `case.relatedCases` array (already declared in the case-management field table, ZGW name `relevanteAndereZaken`) gets a specified element shape:

```json
{ "caseId": "<uuid>", "aardRelatie": "vervolg" | "onderwerp" | "bijdrage", "toelichting": "<optional string>" }
```

The three `aardRelatie` values follow ZRC `relevanteAndereZaken.aardRelatie`. Semantics (from RGBZ):
- `vervolg` — this case is a follow-up of the related case (toezicht na vergunning);
- `onderwerp` — the related case is the subject of this case (bezwaar over het besluit in die zaak);
- `bijdrage` — this case contributes to the related case (advies-deelproces aan hoofdbehandeling).

## Bidirectionality

The relation is stored **symmetrically**: adding a relation writes an entry into `relatedCases` of *both* cases (the inverse entry points back with the same `aardRelatie` — the type names the link, the UI renders direction-aware labels: "Vervolg op" vs "Heeft vervolg"). Symmetric storage keeps the ZGW outbound mapping a pure field read on either case and avoids a join at render time; the cost is that `CaseRelationService` is the only writer and must keep both sides consistent (add, remove, and delete-cleanup are two-sided operations). Direct PATCHes of `relatedCases` outside the service (including inbound ZGW writes) are normalised by the service to restore symmetry.

## Guards

- No self-relation (`caseId == own id`).
- No duplicate `{caseId, aardRelatie}` pair.
- A case that is already the parent or a direct sub-case (deelzaak) of the target cannot additionally be peer-linked to it — the hierarchy already expresses the relation; the API returns a validation error pointing at the existing hierarchy link.
- Linking requires read access (OR RBAC) to *both* cases at link time.

## RBAC at render time

The related-cases section lists each relation; entries whose target the viewer cannot read under OR RBAC render as a masked stub (zaaknummer only, no title, no navigation). The relation's *existence* is not hidden — mirroring how ZGW exposes the URL reference — but no content leaks.

## ZGW Mapping

Outbound: `relatedCases[*]` → `relevanteAndereZaken: [{url: <zaak-url from caseId>, aardRelatie}]` using the existing URL-reference translation requirement of `zgw-api-mapping`. Inbound (POST/PATCH zaak): `relevanteAndereZaken` URLs are resolved to local case UUIDs (unresolvable URLs rejected per existing URL-translation error semantics), then routed through `CaseRelationService` so guards and symmetry hold. `toelichting` is procest-local and not emitted in the ZGW shape (ZRC has no such field).
