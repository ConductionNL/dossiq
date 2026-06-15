// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Leaf-tab resolver — ADR-022 (apps consume OR integration leaves).
//
// OpenRegister ships integration *providers* (PHP) under
// `lib/Service/Integration/Providers/` (CalendarProvider, FormsProvider,
// PhotosProvider, MapsProvider, …). Their matching Vue surfaces
// (CnCalendarTab, CnFormsTab, CnPhotosTab, CnMapsTab) live in
// `@conduction/nextcloud-vue`'s built-in integration registry and fetch
// from `/apps/openregister/api/objects/{register}/{schema}/{id}/integrations/{id}`.
//
// procest's manifest is `sidebarTabs[]` + `component:` driven (the
// CaseEmailTab pattern), so to surface a leaf tab we resolve its bespoke
// Vue component out of the lib's `builtinIntegrations` descriptor array
// and register it under a registry key. CnObjectSidebar injects
// `objectId` / `register` / `schema` / `apiBase` via `sharedTabProps`,
// which is exactly the contract the leaf tab components expect — so the
// leaf does its own OR fetch, list, link/unlink and create with zero
// per-app glue. This is consumption, not re-implementation.

import { builtinIntegrations } from '@conduction/nextcloud-vue'

/**
 * Resolve the bespoke Vue tab component for an OR integration leaf id.
 *
 * @param {string} id Stable leaf/provider id (e.g. 'calendar', 'forms', 'maps', 'photos').
 * @return {object|undefined} The leaf tab Vue component, or undefined if the
 *   library does not ship a bespoke tab for that id.
 */
export function leafTab(id) {
	const descriptor = (builtinIntegrations || []).find((entry) => entry && entry.id === id)
	return descriptor ? descriptor.tab : undefined
}
