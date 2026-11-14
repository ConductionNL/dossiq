/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case's stages strip leaves a side status (offPath) off unless the case
 * is in it, as boards DqZaak and DqSociaalDomein draw the main path only.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-a-case-type-marks-its-side-statuses
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages

/**
 * Every node with this key, anywhere.
 *
 * @param {unknown} node A node.
 * @param {string} key The key.
 * @param {Array<object>} out The matches.
 * @return {Array<object>}
 */
function withKey(node, key, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => withKey(child, key, out))
	} else if (node && typeof node === 'object') {
		if (key in node) {
			out.push(node[key])
		}
		Object.values(node).forEach((child) => withKey(child, key, out))
	}
	return out
}

describe('case stages strip', () => {
	it('names offPath on the simple layout\'s stage source', () => {
		const stages = withKey(layout, 'case-stages').find((entry) => entry && entry.content && entry.content.stagesSource)
		expect(stages.content.stagesSource.offPathField).toBe('offPath')
	})

	it('names offPath on the case page\'s stage endpoint', () => {
		const widget = pages.find((page) => page.id === 'CaseDetail').config.widgets.find((entry) => entry.id === 'case-stages')
		expect(widget.content.stagesEndpoint.offPathField).toBe('offPath')
	})
})
