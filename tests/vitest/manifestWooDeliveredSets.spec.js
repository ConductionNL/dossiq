/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A delivered set is written by publishing only
 * (a-delivered-set-is-written-by-publishing-only).
 *
 * On 13 October a Woo coordinator looked for the publish step on a Woo
 * request and found "Aangeleverde Woo-set aanmaken": the library's default
 * Add on the delivered-sets list, opening the raw schema form that asks for
 * a SHA-256 hash and a state. A delivered set is the record of what the
 * publish wrote, so the list offers no Add, and the way to publish is the
 * Publish (Woo) action.
 *
 * @spec openspec/changes/a-delivered-set-is-written-by-publishing-only/specs/woo-publication-via-opencatalogi/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

/**
 * Every widget declaration with this id, wherever the manifest nests it.
 *
 * @param {*} node A manifest node.
 * @param {string} id The widget id.
 * @param {Array<object>} found The matches so far.
 * @return {Array<object>} The matches.
 */
function widgetsWithId(node, id, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => widgetsWithId(child, id, found))
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			found.push(node)
		}
		Object.values(node).forEach((child) => widgetsWithId(child, id, found))
	}
	return found
}

describe('the delivered sets of a Woo request', () => {
	it('offers no Add, so nobody hand-makes a delivery', () => {
		const lists = widgetsWithId(manifest, 'case-woo-delivered-sets')
		expect(lists).toHaveLength(1)
		expect(lists[0].type).toBe('object-list')
		expect(lists[0].content.schema).toBe('wooDeliveredSet')
		expect(lists[0].content.allowCreate).toBe(false)
	})

	it('names the publish action in its empty state, which exists', () => {
		const list = widgetsWithId(manifest, 'case-woo-delivered-sets')[0]
		expect(list.content.emptyText).toContain('Publish (Woo)')

		const publish = widgetsWithId(manifest, 'woo-publish')
		expect(publish).toHaveLength(1)
		expect(publish[0].label).toBe('Publish (Woo)')
		expect(publish[0].props.publishUrl).toBe('/apps/dossiq/api/cases/{caseId}/woo/publish')
	})

	it("shows its dates in the reader's locale and its state in words", () => {
		// Rehearsal R3: the table read `2026-10-16T08:44:21+00:00` and `frozen`.
		const list = widgetsWithId(manifest, 'case-woo-delivered-sets')[0]
		const column = (key) =>
			list.content.columns.find((entry) => entry.key === key)
		expect(column('deliveredAt').format).toBe('date-time')
		expect(column('withdrawnAt').format).toBe('date-time')

		const state = column('status')
		const schema = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'lib',
					'Settings',
					'register.d',
					'86-woo-delivered-set.json',
				),
				'utf8',
			),
		)
		const states =
			schema.components.schemas.wooDeliveredSet.properties.status.enum
		expect(Object.keys(state.enumLabels).sort()).toEqual([...states].sort())

		const nl = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8'),
		).translations
		for (const label of Object.values(state.enumLabels)) {
			expect(nl[label], label).toBeTruthy()
			expect(nl[label]).not.toBe(label)
		}
	})
})
