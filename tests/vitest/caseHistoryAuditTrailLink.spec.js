/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakHistorie: under the case history, beside the event count, "Volledige
 * audit trail in de zijbalk" opens the sidebar's audit trail. The timeline
 * widget asks for that link with `auditTrailLink`.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))

/**
 * Find a widget definition by id anywhere in the manifest.
 *
 * @param {*} node The subtree to search.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = findWidget(item, id)
			if (hit) return hit
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.type) return node
		for (const value of Object.values(node)) {
			const hit = findWidget(value, id)
			if (hit) return hit
		}
	}
	return null
}

describe('DqZaakHistorie audit trail link', () => {
	it('the case history asks for the link to the sidebar audit trail', () => {
		const timeline = findWidget(manifest, 'case-timeline-panel')
		expect(timeline?.type).toBe('timeline')
		expect(timeline.content.manage).toBe(true)
		expect(timeline.content.auditTrailLink).toBe(true)
	})

	it('the link opens the audit trail tab the case page declares', () => {
		const timeline = findWidget(manifest, 'case-timeline-panel')
		const page = manifest.pages.find((entry) => entry.id === 'CaseDetail')
		const tabIds = (page?.config?.sidebar?.tabs ?? []).map((tab) => tab.id)
		expect(tabIds).toContain(timeline.content.auditTrailTab)
	})
})
