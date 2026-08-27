# Design: case-type-navigation

## Context

Objections (`bezwaar`), appeals (`beroep`) and subsidies are administrative-law flavours of a case — they persist to the shared `case` schema and are read through the shared `Cases` index, differing only by their `caseType` reference. The bundled manifest previously encoded them as dedicated menu groups (`BezwaarBeroepGroup`, `SubsidiesGroup`) plus standalone workflow index pages (`BezwaarDecisions`, `BezwaarAdviceRequests`) and a standalone `CaseMap` menu leaf. This duplicates navigation and freezes the case-type taxonomy into a static file.

## Decision

Resolve the case-type navigation from live data at request time and merge it into the existing "Cases" group.

1. A backend `ManifestController::manifest()` lists the `caseType` objects the current user may see (via OpenRegister `ObjectService`, RBAC-scoped) and returns a keyed menu **delta**: `{ menu: [{ id: 'CasesGroup', children: [ { id: 'ct-<uuid>', label: <name>, route: 'Cases', query: { caseType: <uuid> }, order } ] }] }`.
2. The frontend consumes it with `useAppManifest('dossiq', builtManifest, { mergeStrategy: 'delta' })`. `mergeManifestDelta` merges a menu entry's `children[]` **by child id**, so the delta ADDS one child per case type under the pre-existing `CasesGroup` without clobbering its other children.
3. Routes are still built from the static built manifest — the delta introduces no new pages, only children that point at the existing `Cases` route with a `query.caseType` filter.

## ADR-031 note — imperative resolution is justified here

ADR-031 restricts imperative object-notification/dispatch in a leaf app and favours declarative OpenRegister features. This controller is **not** a declarative derived field masquerading as code: it is an external/HTTP integration point — the app-shell's `/api/manifest` seam that `useAppManifest` fetches. Turning a live, RBAC-scoped set of OpenRegister objects into a navigation delta is a legitimate server-side read at an HTTP boundary, not business logic that belongs in an OpenRegister calculation/lifecycle declaration. The endpoint performs no writes and no notifications; it degrades to a no-op delta whenever the data isn't there, so it never becomes a hidden control-flow dependency.

## ADR-022 note — apps consume OR abstractions server-side

Per ADR-022, apps consume OpenRegister abstractions rather than reimplementing storage. The controller reads case types through the shared `SearchesObjects` trait (the canonical `ObjectService::searchObjects` / `searchObjectsBySlug` bridge) under the user session, so RBAC and register/schema resolution stay in OpenRegister. dossiq adds no table and no bespoke query path — it reuses the same abstraction the frontend index pages use, just from the server side to shape a nav delta.

## Cases map view

Cases carry a `geometry` field (GeoJSON, per `dossiq_register.json`). The `Cases` page opts into `viewModes: ["table","cards","map"]` and a `mapConfig` mirroring the row shape: `geoField: "geometry"` (GeoJSON Point `coordinates: [lng, lat]`), `popupField: "title"`, `center` fallback over the Netherlands. The standalone `CaseMap`/`CasesOnMapView` page/route is retained for deep links but dropped from the menu, since the Cases index now covers the map surface.

## Alternatives considered

- **Static per-case-type menu entries in the bundled manifest** — rejected: freezes the taxonomy and drifts on every case-type add/rename.
- **A declarative OpenRegister-derived menu** — no such mechanism exists for app-shell navigation; the `/api/manifest` delta is the sanctioned seam.
- **Deep-merge instead of delta-merge** — a plain deep-merge replaces arrays wholesale and would clobber `CasesGroup`'s existing children; the keyed `children[]` delta is required.
