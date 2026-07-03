// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	fieldInspectionIntegration,
	registerIntegration,
	registerIcons,
	registerTranslations,
	registerDashboardWidget,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import customComponents from './customComponents.js'
import registry from './registry.js'
import mapFormatters from './services/mapFormatters.js'
import formatters from './services/formatters.js'
import AuditTrailWidget from './components/widgets/AuditTrailWidget.vue'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

// Register `audit-trail` into the shared widget-type catalog so CnDetailPage's
// config-grid body (which resolves widget `type` via the catalog, not the
// app registry) can render it as a body widget, mirroring the app-registry
// entry used by the slot CnWidgetGrid path.
registerDashboardWidget('audit-trail', {
	renderer: AuditTrailWidget,
	form: null,
	defaultContent: {},
	displayName: 'Audit trail',
	icon: 'History',
	surfaces: ['detail-page'],
})

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Register library-side icon set + lib translations once at bootstrap.
registerIcons()
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[procest] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs (including
// this repo's standard dev container) only allow the JS/CSS allowlist
// through Apache and rewrite everything else to index.php — there's no
// route for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback meant boot silently failed when translations couldn't
// load. Strings just fall back to their English source on miss; boot
// MUST not depend on this resolving.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('procest', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op
	}
}

// Surface the generic `field-inspection` OpenRegister integration leaf with
// procest's own offline schema mapping. The leaf (a nc-vue builtin, registered
// live by OpenRegister's `integration-global` bundle) owns the offline planning
// list, checklist completion, mutation queue and reconnect-replay; procest only
// supplies its `offlineConfig` so the generic core points at procest's schemas.
//
// Bootstrap-order safety: procest's bundle may load before OpenRegister's, so
// install a minimal `_queue` stub that buffers the registration and replays it
// once OR's registry attaches. Registering procest's mapping FIRST means the
// AD-13 first-wins collision policy keeps procest's `offlineConfig` even when
// OR later registers the leaf with its canonical defaults. The mapping mirrors
// `DailySyncService` exactly (fieldInspection / inspectionChecklist /
// checklistResult, filtered by inspectorRef + scheduledAt).
//
// @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
registerIntegration({
	...fieldInspectionIntegration,
	offlineConfig: {
		// Schema holding today's planned inspections.
		plannedSchema: 'fieldInspection',
		// Schema holding the checklist templates referenced by planned items.
		referenceSchema: 'inspectionChecklist',
		// Property on a planned item that references its checklist template.
		templateRefField: 'checklistTemplateRef',
		// Schema the completed checklist result is written back to.
		resultSchema: 'checklistResult',
		// Planning filter: assignee uid property + the scheduled-date field.
		assigneeField: 'inspectorRef',
		dateField: 'scheduledAt',
		// Property on a planned item used as its display title.
		titleField: 'caseRef',
	},
})

// Apply ADR-037 manifest fragments before routes/app consume the manifest.
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
const manifest = buildManifest(bundledManifest, fragments, menuLayout)

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()`
// adds an internal `_Ctor` cache to the component definition; mutating
// a non-extensible export throws "Cannot add property _Ctor, object is
// not extensible". Cloning gives Vue Router an extensible
// component-options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest contract).
 * Routes whose path declares a `:` parameter receive `props: true` so the
 * underlying detail/custom component receives the route param.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	routes.push({ path: '*', redirect: '/' })
	return routes
}

const router = new VueRouter({
	mode: 'history',
	base: generateUrl('/apps/procest'),
	routes: routesFromManifest(manifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and consumers' `customComponents`) as frozen module
// objects in some bundle shapes — Vue 2's `Vue.extend()` mutates component
// definitions to attach an internal `_Ctor` cache, which throws
// "Cannot add property _Ctor, object is not extensible" against a frozen
// source map. Cloning here yields extensible objects without changing
// the values the lib resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }
const registryProp = { ...registry }
const mapFormattersProp = { ...mapFormatters }
const formattersProp = { ...formatters }

// Expose the map formatter registry as a Vue global so `CnMapPage`
// (and any future map-type pages) can resolve named formatters from
// `config.marker.formatter`. Mirrors the existing customComponents
// resolution pattern — see customComponents.js for context.
Vue.prototype.$mapFormatters = mapFormattersProp

new Vue({
	pinia,
	router,
	render: (h) => h(App, {
		props: {
			manifest,
			customComponents: customComponentsProp,
			registry: registryProp,
			pageTypes: pageTypesProp,
			mapFormatters: mapFormattersProp,
			formatters: formattersProp,
		},
	}),
}).$mount('#content')

// Register the mobiel-inspectie-offline Service Worker (PWA offline shell).
// Fire-and-forget: registration failure must never block app boot, and the
// app degrades gracefully to online-only when the worker is unavailable.
// @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
	window.addEventListener('load', () => {
		navigator.serviceWorker
			.register(generateUrl('/apps/procest/service-worker.js'), { scope: generateUrl('/apps/procest/') })
			// eslint-disable-next-line no-console
			.catch((e) => console.warn('[procest] service worker registration failed', e))
	})
}
