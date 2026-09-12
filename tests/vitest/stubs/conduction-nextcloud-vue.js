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

/**
 * Stand-in for `CnRelatedObjectsWidget`.
 *
 * Only the part a dossiq consumer owns is reproduced: the `extraSections`
 * prop, which is how a host hands the widget rows the widget cannot fetch
 * for itself. `CasePlannedWidget` is the one such host, and what a test can
 * honestly assert about it is that the planned follow-ups it read reach this
 * boundary — the real widget's own rendering belongs to the library and is
 * not this suite's to check.
 *
 * The sections are rendered rather than merely recorded, so a spec asserts on
 * output the way a reader meets it instead of reaching into component
 * internals.
 */
export const CnRelatedObjectsWidget = {
	name: 'CnRelatedObjectsWidget',
	props: {
		bare: { type: Boolean, default: false },
		objectId: { type: [String, Number], default: '' },
		objectData: { type: Object, default: null },
		objectType: { type: String, default: '' },
		register: { type: [String, Object], default: '' },
		schema: { type: [String, Object], default: '' },
		store: { type: Object, default: null },
		extraSections: { type: Array, default: () => [] },
	},
	render() {
		return h(
			'div',
			{ 'data-testid': 'cn-related-objects-widget' },
			this.extraSections.map((section) =>
				h('section', { class: 'cn-related-objects-widget__group' }, [
					(section.items || []).length
						? h('h4', {}, String(section.label))
						: null,
					h(
						'ul',
						{},
						(section.items || []).map((item) =>
							h('li', { key: item.key }, String(item.label ?? '')),
						),
					),
				]),
			),
		)
	},
}

/**
 * Stand-in for `CnDataTable`.
 *
 * Reproduces the contract a host actually depends on, and nothing else:
 * one row element per `rows` entry, one cell per `columns` entry read by
 * `col.key`, the `rowClass(row)` function applied to the row, the
 * `emptyText` shown in place of the body when there are no rows, the
 * `#footer` slot, and the `row-click` event carrying the clicked row.
 *
 * 🔴 THE EVENT IS `row-click`, NOT `rowClick`. Vue 3 resolves a camelCase
 * listener against a kebab-case emit (`componentEmits` retries with
 * `camelize`), so a host writing `@rowClick` is correct and a stub emitting
 * `rowClick` would ALSO satisfy it. Emitting the real name is what keeps
 * that from being an accident.
 */
export const CnDataTable = {
	name: 'CnDataTable',
	props: {
		rows: { type: Array, default: () => [] },
		columns: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		rowClass: { type: Function, default: null },
		cellClass: { type: Function, default: null },
		emptyText: { type: String, default: '' },
		borderless: { type: Boolean, default: false },
		hideHeader: { type: Boolean, default: false },
	},
	emits: ['row-click'],
	render() {
		const head = this.hideHeader
			? null
			: h('thead', {}, [
					h(
						'tr',
						{},
						this.columns.map((col) =>
							h('th', { key: col.key }, String(col.label ?? col.key)),
						),
					),
				])

		const body = this.rows.length
			? h(
					'tbody',
					{},
					this.rows.map((row, index) =>
						h(
							'tr',
							{
								key: row.id ?? index,
								class: this.rowClass
									? this.rowClass(row)
									: undefined,
								onClick: () => this.$emit('row-click', row),
							},
							this.columns.map((col) =>
								h(
									'td',
									{ key: col.key, class: col.cellClass },
									String(row[col.key] ?? ''),
								),
							),
						),
					),
				)
			: h('tbody', {}, [
					h('tr', { class: 'cn-data-table__empty' }, [
						h('td', {}, String(this.emptyText)),
					]),
				])

		return h('div', { 'data-testid': 'cn-data-table' }, [
			h('table', {}, [head, body]),
			this.$slots.footer ? this.$slots.footer() : null,
		])
	},
}
