// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { CnNavCardGrid, registerDashboardWidget } from '@conduction/nextcloud-vue'

/**
 * Register `nav-card-grid` in the shared catalog, for the case page.
 *
 * The `sections` container the case tabs use comes from nextcloud-vue itself
 * (CnSectionsWidget, which replaced this app's own `case-sections`).
 *
 * @return {void}
 */
export function registerNavCardGrid() {
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
