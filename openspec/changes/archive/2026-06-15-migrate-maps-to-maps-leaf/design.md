# Design: migrate-maps-to-maps-leaf

## Context

OR's maps integration leaf (ADR-019 two-sided registry) ships a paired backend
`IntegrationProvider` + frontend `OCA.OpenRegister.integrations.register({ id: 'maps', ... })`
registration. Once enabled and whitelisted on the `case` schema's `configuration.linkedTypes`,
the leaf contributes a map tab + card widget rendered by `CnObjectSidebar` /
`@conduction/nextcloud-vue` on any page that hosts the OR object sidebar. The map marker is
derived from the object's geo-typed property. Procest consumes this leaf; it does not modify it.

## File-by-File Mapping

| Existing procest artifact | Disposition |
|---|---|
| `src/components/map/MapComponent.vue` | Removed — leaf renders the map |
| `src/components/map/CaseMap.vue` | Removed — replaced by maps leaf tab on case detail |
| `src/components/map/LocationPicker.vue` | Removed — leaf provides location input affordance; geo-field edit stays via OR form |
| `src/components/map/AddressSearch.vue` | Removed — address search is the PDOK shim (`migrate-pdok-to-openconnector`) |
| `src/components/map/MapLayerSwitcher.vue` | Removed — layer switching is leaf-owned |
| `src/components/map/MapLegend.vue` | Removed — leaf-owned |
| `src/components/map/SpatialFilter.vue` | Removed — see "Multi-object overview" risk below |
| `src/components/map/CasePopup.vue` | Removed — leaf marker popup |
| `lib/Service/WmsWfsService.php` | Removed — WMS/WFS layer config is leaf-owned |
| `lib/Service/WfsExportService.php` | Removed — leaf/OR export surface |
| `lib/Service/LocationService.php` | Removed — geo data is a plain OR object property |
| `case` schema `location` geo property | **Kept** — unchanged data contract |

## Data contract (kept in procest)

The `case` schema's `location` property remains a `geo`-typed field (GeoJSON, per OR's
`geo-metadata-kaart`). The leaf reads this property to place the marker. No procest service
writes map UI state; the geo value is edited through OR's standard object form and the leaf's
location affordance.

## Multi-object case-overview risk

The `case-map-overview` spec describes a clustered map of MANY cases (workload heat-map). The
maps leaf is an object-scoped tab/widget — it renders ONE object's geo. If the leaf cannot yet
render a multi-object overview surface, the overview map is **not** re-implemented in-app:
it is flagged as a follow-up against the OR maps leaf (open a GH issue at planning time per
the "always file issues" rule). Single-case map (the common path) is fully covered by the leaf.

## DEFERRED_QUESTIONS

- Confirm the exact leaf `id` (`maps` assumed) and that its frontend registration is shipped in
  the `@conduction/nextcloud-vue` version procest pins.
- Confirm whether the maps leaf supports a multi-object overview surface or whether
  `case-map-overview` needs an OR follow-up issue.
- Confirm `configuration.linkedTypes` whitelist entry name for the maps leaf on the `case` schema.
