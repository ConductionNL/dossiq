/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd's Gerelateerde zaken as the board draws them: the other
 * case's type initials on the primary tint, ruled rows, and a closed case as
 * "Afgehandeld". Read from the layout.
 *
 * @spec openspec/changes/record-list-avatar-tone/specs/record-list-avatar/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(root, 'src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations

/**
 * The first object at any depth whose id is `id`.
 *
 * @param {unknown} node A node.
 * @param {string} id The id.
 * @return {object|null}
 */
function byId(node, id) {
	if (node === null || typeof node !== 'object') {
		return null
	}
	if (!Array.isArray(node) && node.id === id) {
		return node
	}
	for (const child of Object.values(node)) {
		const hit = byId(child, id)
		if (hit !== null) {
			return hit
		}
	}
	return null
}

describe('the related cases card', () => {
	const content = byId(layout, 'case-related-cases-card').content

	it('leads each row with the type initials on the primary tint, ruled', () => {
		expect(content.avatar).toEqual({ text: '{caseTypeTitle}', size: 32, tone: 'primary' })
		expect(content.ruleAbove).toBe(true)
	})

	it('reads a closed case as Afgehandeld', () => {
		expect(nl[content.pill.labels.closed]).toBe('Afgehandeld')
	})
})
