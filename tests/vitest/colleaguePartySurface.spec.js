/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakBetrokkenen draws "Partij toevoegen" on the case's parties card;
 * its picker offers residents, organisations and colleagues, so a colleague
 * (the colleague party schema) can be added to a case.
 *
 * @spec openspec/changes/colleague-party/specs/party-pages/spec.md#requirement-a-colleague-can-be-a-party-on-a-case
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

/**
 * The first widget with this id, anywhere in a JSON tree.
 *
 * @param {unknown} node The tree.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function widgetById(node, id) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const found = widgetById(child, id)
			if (found) {
				return found
			}
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.type === 'parties') {
			return node
		}
		for (const child of Object.values(node)) {
			const found = widgetById(child, id)
			if (found) {
				return found
			}
		}
	}
	return null
}

describe.each([
	['src/manifest.json', 'case-party-roles'],
	['src/menu-layout.simple.json', 'case-party-roles-card'],
])('%s parties card', (file, id) => {
	it('adds residents, organisations and colleagues', () => {
		const sources = widgetById(read(file), id).content.manage.add.sources
		expect(sources.map((source) => source.schema)).toEqual(['brpPerson', 'kvkCompany', 'colleague'])
		expect(sources[2]).toMatchObject({ register: 'dossiq', title: '{displayName}' })
	})
})
