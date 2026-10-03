// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { CnNavCardGrid, registerDashboardWidget } from '@conduction/nextcloud-vue'
import CaseSectionsWidget from './CaseSectionsWidget.vue'

/**
 * Register `case-sections`, the widget type that lets ONE tab hold more than
 * one panel.
 *
 * WHY THE SHARED CATALOG AND NOT `registry.js`. A renderer resolves from
 * either map, so `case-task-pane` sits in `registry.js` and works. This one
 * cannot. `CnDetailWidgetHost.isContainer` reads
 * `getWidgetTypeEntry()`, which is the SHARED catalog and only the shared
 * catalog, and only a container is handed `schemaObject`,
 * `integrationContext` and `cnRegistry`. Put this entry in `registry.js` and
 * the widget itself renders while every section that needs one of those three
 * renders blank, silently, which is the failure mode this page has already hit
 * twice.
 *
 * `container: true` is the library's own flag for a widget that renders other
 * widgets, and `registerDashboardWidget` is the documented way a consuming app
 * extends the catalog. This is the supported seam, not a way around a missing
 * one.
 *
 * `form: null` keeps the type out of the Add-widget picker: its sections are
 * authored in the manifest and no form could configure them.
 *
 * @return {void}
 */
export function registerCaseSections() {
	registerDashboardWidget('case-sections', {
		renderer: CaseSectionsWidget,
		form: null,
		container: true,
		surfaces: ['detail-page'],
		displayName: 'Case sections',
		icon: 'ViewAgendaOutline',
	})

	// `nav-card-grid` is a BUILT-IN widget for CnWidgetGrid, which resolves it
	// from its own map. CnDetailWidgetHost does not read that map: it resolves
	// a `type` from the shared catalog alone, so a case section naming
	// `nav-card-grid` rendered nothing and logged nothing. The Object types
	// section (data-model-link) is the first child that needs it. Not a
	// container: its cards are links and it reads no case context.
	registerDashboardWidget('nav-card-grid', {
		renderer: CnNavCardGrid,
		form: null,
		surfaces: ['detail-page'],
		displayName: 'Navigation cards',
		icon: 'ViewGridOutline',
	})
}
