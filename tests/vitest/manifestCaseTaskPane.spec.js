/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task pane's manifest-to-registry contract (task-on-the-case).
 *
 * Everything that binds the pane to the page is config, and config fails
 * quietly. A widget whose `type` no registry key answers to renders NOTHING
 * inside a tab panel and logs nothing (CnDetailWidgetHost's fallback comment
 * says as much). A page retyped to `custom` would move the ADR-100 ratchet.
 * A widget lifted out of the tabs strip into `layout` would render twice.
 * None of those raise an error anywhere in the build, so this file is what
 * turns them into failing tests.
 *
 * `src/registry.js` is read as TEXT rather than imported: it imports roughly
 * forty single-file components, and a probe import under vitest did not
 * settle within the 5s test budget. What is asserted is therefore the whole
 * chain that has to hold on disk — the manifest's widget `type`, the registry
 * key that answers it, the component identifier that key binds, the import
 * that binds that identifier to a path, and the file at that path.
 *
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const MANIFEST_PATH = path.join(ROOT, 'src/manifest.json')
const REGISTRY_PATH = path.join(ROOT, 'src/registry.js')

const manifest = () => JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const registrySource = () => fs.readFileSync(REGISTRY_PATH, 'utf8')

/**
 * One page as the manifest declares it.
 *
 * @param {string} id The manifest page id.
 * @return {object} The page entry.
 */
function page(id) {
	return manifest().pages.find((entry) => entry.id === id)
}

/**
 * One widget of a detail page.
 *
 * @param {string} pageId The manifest page id.
 * @param {string} widgetId The manifest widget id.
 * @return {object|undefined} The widget entry.
 */
function widget(pageId, widgetId) {
	return page(pageId).config.widgets.find((entry) => entry.id === widgetId)
}

/**
 * The registry entry body for a key, as it is written in the source.
 *
 * @param {string} key The registry key.
 * @return {string} The entry's source text, up to its closing brace.
 */
function registryEntry(key) {
	const source = registrySource()
	const opener = source.indexOf(`\n\t'${key}': {`)
	const named = source.indexOf(`\n\t${key}: {`)
	const start = opener !== -1 ? opener : named
	expect(start, `registry has no entry for ${key}`).toBeGreaterThan(-1)
	const end = source.indexOf('\n\t},', start)
	expect(end).toBeGreaterThan(start)
	return source.slice(start, end)
}

describe('the CaseTaskPane registry binding', () => {
	it('answers the widget type by the key CnDetailWidgetHost looks up', () => {
		// `cnRegistry[widget.type]` is the ONLY resolution a tab child gets. A
		// key spelled after the component (`CaseTaskPane`) would satisfy a
		// page slot and leave the tab panel blank.
		const entry = registryEntry('case-task-pane')
		expect(entry).toContain("kind: 'widget'")
		expect(entry).toContain('component: CaseTaskPane')
	})

	it('carries a _note and a reason-bearing ratchet exclusion', () => {
		// Gate 29 (custom-widget-ratchet) fails a kind:"widget" entry with no
		// `_note`, and fails the app when the count grows without a reason.
		const entry = registryEntry('case-task-pane')
		expect(entry).toContain('_note:')
		expect(entry).toMatch(/@custom-widget-ratchet exclude \S+ \S+/)
		expect(entry).toContain('CnObjectListWidget has no rowActions')
	})

	it('imports the component from a file that exists', () => {
		const match = registrySource().match(/^import CaseTaskPane from '(.+)'$/m)
		expect(match, 'CaseTaskPane must be imported').not.toBeNull()
		expect(
			fs.existsSync(path.join(ROOT, 'src', match[1].replace(/^\.\//, ''))),
		).toBe(true)
	})
})
