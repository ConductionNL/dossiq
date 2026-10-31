/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type and organisation pages draw the folder tabs of the case page
 * (boards DqZaaktypeOverzicht and DqTenant), not a segmented switch.
 *
 * @spec openspec/specs/admin-detail-folder-tabs/spec.md#requirement-the-case-type-and-organisation-pages-draw-folder-tabs
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {string} file Path under the repository root.
 * @return {object} The parsed document.
 */
function readJson(file) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
}

/**
 * Every widget with this id anywhere in a document.
 *
 * @param {object} node The document or a part of it.
 * @param {string} id The widget id.
 * @param {object[]} found Collected widgets.
 * @return {object[]} The widgets.
 */
function widgetsById(node, id, found = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => widgetsById(item, id, found))
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type === 'tabs') {
			found.push(node)
		}
		Object.values(node).forEach((value) => widgetsById(value, id, found))
	}
	return found
}

describe('admin record pages draw folder tabs', () => {
	it.each([
		['src/menu-layout.simple.json', 'case-type-panels'],
		['src/manifest.d/67-environment-organisations.json', 'organisation-panels'],
	])('%s %s is a folder strip', (file, id) => {
		const widgets = widgetsById(readJson(file), id)
		expect(widgets).toHaveLength(1)
		expect(widgets[0].content.variant ?? 'line').toBe('line')
	})
})
