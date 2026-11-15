// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The component registry as the renderer receives it.
 *
 * Other specs read `src/registry.js` as text. This one imports it, so it
 * checks what CnAppRoot actually gets: every entry carries a known kind and
 * the payload that kind needs, every grid widget carries the ADR-036 metadata
 * the registry validator demands, and every component the manifest names by
 * key resolves to an entry. A manifest key with no registry entry renders an
 * empty panel without logging anything, so the last check is the one that
 * catches a rename done on one side only.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import registry from '../../src/registry.js'

// @nextcloud/dialogs is not inlined by the Vitest config and loads
// @nextcloud/vue's stylesheets directly, which Node cannot import. The
// registry only needs the components to load, not to show a toast.
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	showInfo: vi.fn(),
	showMessage: vi.fn(),
	getFilePickerBuilder: vi.fn(),
	spawnDialog: vi.fn(),
	FilePickerType: {},
	DialogBuilder: vi.fn(),
	getDialogBuilder: vi.fn(),
}))

const ROOT = path.resolve(__dirname, '..', '..')

const KINDS = ['page', 'widget', 'modal', 'handler', 'create-override']
const SLOTS = ['body', 'sidebar', 'header-actions', 'footer', 'modal']

/**
 * Every string value under one of `keys`, anywhere in a JSON tree.
 *
 * @param {*} node The tree.
 * @param {string[]} keys The keys to collect.
 * @param {Set<string>} found Collected values.
 * @return {Set<string>} The values.
 */
function collect(node, keys, found = new Set()) {
	if (Array.isArray(node)) {
		node.forEach((child) => collect(child, keys, found))
	} else if (node !== null && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (keys.includes(key) && typeof value === 'string') {
				found.add(value)
			}
			collect(value, keys, found)
		}
	}
	return found
}

/**
 * The manifest and its fragments, parsed.
 *
 * @return {object[]} The documents.
 */
function manifests() {
	const dir = path.join(ROOT, 'src', 'manifest.d')
	const fragments = fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.map((name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8')))
	return [JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8')), ...fragments]
}

describe('the component registry', () => {
	const entries = Object.entries(registry)

	it('is not empty', () => {
		expect(entries.length).toBeGreaterThan(50)
	})

	it('gives every entry a known kind', () => {
		const unknown = entries.filter(([, entry]) => !KINDS.includes(entry.kind)).map(([key]) => key)
		expect(unknown).toEqual([])
	})

	it('gives every handler and create override a function and nothing else a handler', () => {
		for (const [key, entry] of entries) {
			if (['handler', 'create-override'].includes(entry.kind)) {
				expect(typeof entry.handler, key).toBe('function')
				expect(entry.component, key).toBeUndefined()
			} else {
				expect(entry.handler, key).toBeUndefined()
			}
		}
	})

	it('gives every page, widget and modal a component object', () => {
		// The *LeafTab entries take their component from the library's
		// builtinIntegrations through leafTab(), which the test stub of
		// @conduction/nextcloud-vue does not ship, so here they resolve to
		// undefined. Only this app's own components are checked.
		const missing = entries
			.filter(([key]) => !key.endsWith('LeafTab'))
			.filter(([, entry]) => ['page', 'widget', 'modal'].includes(entry.kind))
			.filter(([, entry]) => entry.component === null || typeof entry.component !== 'object')
			.map(([key]) => key)
		expect(missing).toEqual([])
	})

	it('gives every widget the grid metadata the registry validator requires', () => {
		const widgets = entries.filter(([, entry]) => entry.kind === 'widget')
		expect(widgets.length).toBeGreaterThan(0)

		for (const [key, entry] of widgets) {
			for (const size of ['defaultSize', 'minSize', 'maxSize']) {
				expect(Number.isInteger(entry[size]?.w), `${key}.${size}.w`).toBe(true)
				expect(Number.isInteger(entry[size]?.h), `${key}.${size}.h`).toBe(true)
			}
			expect(entry.minSize.w, key).toBeLessThanOrEqual(entry.defaultSize.w)
			expect(entry.defaultSize.w, key).toBeLessThanOrEqual(entry.maxSize.w)
			expect(entry.minSize.h, key).toBeLessThanOrEqual(entry.defaultSize.h)
			expect(entry.defaultSize.h, key).toBeLessThanOrEqual(entry.maxSize.h)
			expect(entry.allowedSlots.length, key).toBeGreaterThan(0)
			expect(entry.allowedSlots.filter((slot) => !SLOTS.includes(slot)), key).toEqual([])
			expect(entry, key).toHaveProperty('propsSchema')
		}
	})

	it('resolves every component and actions component the manifest names', () => {
		const named = new Set()
		for (const document of manifests()) {
			collect(document, ['component', 'actionsComponent'], named)
		}
		expect(named.size).toBeGreaterThan(0)

		const unresolved = [...named].filter((key) => registry[key] === undefined)
		expect(unresolved).toEqual([])
	})
})
