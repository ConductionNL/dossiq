/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `@conduction/nextcloud-vue`.
 *
 * The published package ships a CJS bundle that does `require('foo.vue')`,
 * which Vite's transform pipeline cannot consume under the component
 * (jsdom-environment) unit tests (`@vitejs/plugin-vue2` is gated on Vite's
 * resolver, not Node's `require`). Component specs that mount things which
 * transitively import this package (e.g. `src/store/modules/object.js`'s
 * `createObjectStore`) do not need the real Pinia-store-factory behaviour —
 * they override the `objectStore`/`workflowStore` computed properties with
 * plain mocks — so only enough of the surface to satisfy `import` at module
 * load time is stubbed here. Extend as further component specs need more of
 * the real package's exports (mirrors launchpad/tests/vitest/stubs/
 * conduction-nextcloud-vue.js, which solved the same problem for its own
 * dashboard-widget usage of this package).
 */

import { h } from 'vue'

/**
 * Stand-in for `createObjectStore(id, options)` — returns a Pinia
 * `useStore()`-shaped function. Never actually called in tests that reach
 * this stub (they override the computed `objectStore`/`workflowStore`
 * before the component would call it), so a minimal placeholder is enough.
 *
 * @param {string} id Pinia store id (unused)
 * @param {object} [options] Store options (unused)
 * @return {Function} A no-op "useStore" function
 */
export function createObjectStore(_id, _options) {
	return function useStubObjectStore() {
		return {}
	}
}

export const filesPlugin = () => ({})
export const auditTrailsPlugin = () => ({})
export const relationsPlugin = () => ({})

/**
 * Stand-in for `CnLifecycleActions`.
 *
 * The real component fetches `/apps/openregister/api/objects/{id}/
 * available-actions`, renders one button per allowed transition, POSTs the
 * chosen one and emits `transitioned` — none of which a unit test can or
 * should reach. What a consumer needs to exercise is its OBSERVABLE surface,
 * and that surface is exactly three things: the `objectId` it was handed, the
 * `transitioned` event, and the `error` data it fills with the server's
 * message on a refused transition (it emits nothing in that case, which is
 * why a consumer has to watch the field). All three are reproduced here.
 */
export const CnLifecycleActions = {
	name: 'CnLifecycleActions',
	props: {
		objectId: { type: [String, Number], default: '' },
		object: { type: Object, default: null },
		config: { type: Object, default: () => ({}) },
	},
	emits: ['transitioned', 'reload'],
	data() {
		return { error: '' }
	},
	render() {
		// A render function rather than a `template`: the Vue build vitest
		// resolves is runtime-only, so a string template would never compile.
		return h('div', { 'data-testid': 'cn-lifecycle-actions' }, [
			String(this.objectId),
			this.error
				? h('p', { 'data-testid': 'cn-lifecycle-actions-error' }, this.error)
				: null,
		])
	},
}
