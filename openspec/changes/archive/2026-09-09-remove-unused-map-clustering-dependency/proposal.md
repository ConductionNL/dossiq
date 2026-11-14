# Proposal: remove-unused-map-clustering-dependency

kind: code — dependency hygiene / dead-code cleanup.

## Why

`package.json` declares three Leaflet-family runtime dependencies:

```
"leaflet": "^1.9.4",
"leaflet-draw": "^1.0.4",
"leaflet.markercluster": "^1.5.3",
```

Only two of the three are actually imported anywhere in the app. `src/components/map/LocationPicker.vue`
(the only file that imports Leaflet at all, after the cases-on-map view was migrated to the OR Maps
leaf per the archived `2026-06-15-migrate-cases-on-map-to-maps-overview-leaf` and
`2026-06-15-migrate-maps-to-maps-leaf` changes) imports `leaflet` and `leaflet-draw`:

```
lib/... (n/a — this is frontend)
src/components/map/LocationPicker.vue:57-60:
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet-draw'
import 'leaflet-draw/dist/leaflet.draw.css'
```

`leaflet.markercluster` has zero references anywhere in `src/`:

```
$ grep -rn "markercluster\|MarkerCluster" src --include=*.vue --include=*.js
(no output)
```

It is dead weight shipped in every build — installed, resolved by webpack's dependency graph
tooling, and reported by license/dependency audits, for a clustering feature the app never
renders (map marker clustering was never built; the single remaining Leaflet consumer,
`LocationPicker`, draws a single point/polygon input control, not a marker-dense overview — that
overview now lives in the Maps leaf). This is the same class of finding as the fleet-wide
"340KB dead validator/bundle" observed in the 2026-07-06 manifest fleet audit: an unused package
that inflates the dependency tree, the license report, and the `npm audit` surface for no
functional benefit.

## What Changes

- **BREAKING: none** (dependency removal only; no functional code changes since nothing consumes
  the package).
- Remove `leaflet.markercluster` from `package.json` dependencies and delete
  `package-lock.json`'s corresponding subtree on next `npm install`.
- Sweep `src/components/map/` and any remaining map-related components once more for a stray
  `require`/dynamic import of `leaflet.markercluster` that a plain grep might miss (e.g. behind a
  string-built path) before removing, so the change is provably safe.
- Re-run `npm run license-report` / composer-equivalent JS license and security audit scripts so
  the generated `license-report-npm` / `result-security-npm` artifacts reflect the smaller
  dependency set.
