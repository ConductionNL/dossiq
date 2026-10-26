/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakBesluiten Beschikking: the letter card reads the case's decision
 * letter through dossiq's letters route, the newest dossier document of the
 * type the case type declares under documentDrafts.decision, and is empty
 * while there is none. It no longer reads the flow's besluitDocument text.
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

/**
 * Find a widget by id anywhere in the layout.
 *
 * @param {unknown} node The layout or a part of it.
 * @return {object|null} The widget.
 */
function widgetById(node) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const hit = widgetById(item)
			if (hit) {
				return hit
			}
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === 'case-decision-letter' && node.type === 'facts') {
			return node
		}
		return widgetById(Object.values(node))
	}
	return null
}

const letter = widgetById(simple)

describe('the decision letter card', () => {
	it('reads the letters route for the decision purpose', () => {
		expect(letter.content.endpointSource.url).toBe('/apps/dossiq/api/cases/@objectId/letters/decision')
		expect(routes).toContain("'url' => '/api/cases/{id}/letters/{purpose}', 'verb' => 'GET'")
	})

	it('is empty while the case holds no letter, and no longer reads besluitDocument', () => {
		expect(letter.content.emptyWhen).toBe('empty')
		expect(JSON.stringify(letter.content.fields)).not.toContain('besluitDocument')
	})

	it('shows the letter, its status as the board words it, the template and the date', () => {
		expect(letter.content.fields.map((field) => field.path)).toEqual(['document.title', 'document.status', 'declaration.title', 'document.creatiedatum'])
		const status = letter.content.fields[1]
		expect(nl[status.map.draft]).toBe('Concept, nog niet ondertekend')
		expect(nl[letter.content.fields[2].label]).toBe('Sjabloon')
	})
})
