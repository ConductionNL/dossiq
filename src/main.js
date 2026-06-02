// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import customComponents from './customComponents.js'
import registry from './registry.js'
import mapFormatters from './services/mapFormatters.js'
import formatters from './services/formatters.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

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

/**
 * ADR-037: Merge modular manifest fragments onto the bundled manifest.
 *
 * Every `*.json` file under `src/manifest.d/` is merged (in sorted filename
 * order) onto the bundled manifest. This lets concurrent same-app builds add
 * pages/menu entries via isolated fragment files instead of all editing
 * `src/manifest.json` and conflicting. `pages` and `menu` arrays are
 * concatenated; any other key on a fragment overrides the base value.
 *
 * @param {object} base The bundled manifest.
 * @return {object} The merged manifest.
 */
function mergeManifestFragments(base) {
	// `require.context` is resolved at build time by webpack; the
	// `manifest.d/_placeholder.json` keeps the context non-empty so this
	// never throws when no real fragments exist yet.
	const context = require.context('./manifest.d', false, /\.json$/)
	const merged = { ...base }
	// Defensive copies so fragments never mutate the imported manifest.
	merged.pages = Array.isArray(base.pages) ? [...base.pages] : []
	merged.menu = Array.isArray(base.menu) ? [...base.menu] : []

	context.keys().sort().forEach((key) => {
		const fragment = context(key)
		if (!fragment || typeof fragment !== 'object') {
			return
		}
		Object.keys(fragment).forEach((prop) => {
			if (prop === 'pages' && Array.isArray(fragment.pages)) {
				merged.pages = merged.pages.concat(fragment.pages)
			} else if (prop === 'menu' && Array.isArray(fragment.menu)) {
				merged.menu = merged.menu.concat(fragment.menu)
			} else {
				merged[prop] = fragment[prop]
			}
		})
	})

	return merged
}

// Apply ADR-037 manifest fragments before routes/app consume the manifest.
const manifest = mergeManifestFragments(bundledManifest)

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
