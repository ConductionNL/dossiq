/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The archival reviews widget (DqMijnWerk) reads openregister's
 * /archival/reviews/pending, whose rows name the entry as `uuid` and its
 * destruction list as `list`. The widget's id and the decision URL are filled
 * from those row fields, in both manifests, or the answer is posted to a URL
 * with empty segments.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

/**
 * Every widget with this id anywhere in a manifest.
 *
 * @param {object} node The manifest or a part of it.
 * @param {string} id The widget id.
 * @param {Array<object>} found Collects the widgets.
 * @return {Array<object>} The widgets.
 */
function widgets(node, id, found = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => widgets(item, id, found))
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			found.push(node)
		}
		Object.values(node).forEach((value) => widgets(value, id, found))
	}
	return found
}

describe('the archival reviews widget', () => {
	it.each(['src/manifest.json', 'src/menu-layout.simple.json'])('%s fills its id and decision URL from the pending row', (file) => {
		const found = widgets(read(file), 'archival-reviews')
		expect(found.length).toBeGreaterThan(0)
		for (const widget of found) {
			expect(widget.content.idField).toBe('uuid')
			expect(widget.content.submit.request.url).toBe('/apps/openregister/api/archival/destruction-lists/{list}/entries/{uuid}/decision')
		}
	})
})
