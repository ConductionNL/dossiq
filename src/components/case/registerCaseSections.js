// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { registerDashboardWidget } from '@conduction/nextcloud-vue'
import CaseSectionsWidget from './CaseSectionsWidget.vue'

/**
 * Register `case-sections`, the widget type that lets ONE tab hold more than
 * one panel.
 *
 * WHY THE SHARED CATALOG AND NOT `registry.js`. A renderer resolves from
 * either map, so `dossier-tab` and `case-task-pane` sit in `registry.js` and
 * work. This one cannot. `CnDetailWidgetHost.isContainer` reads
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
}
