/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The boards open the Aanvrager card with a lead row: the requester's
 * initials, name and kind ("SV Sanne de Vries"). nextcloud-vue's facts lead
 * reads its text from `title` and leaves out a lead whose title is empty, so
 * a lead that names its field as `path` never draws. Every lead declares
 * `title`.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const files = ['src/manifest.json', 'src/menu-layout.simple.json']
	.concat(fs.readdirSync(path.join(ROOT, 'src/manifest.d')).filter((name) => name.endsWith('.json')).map((name) => `src/manifest.d/${name}`))

/**
 * Every facts entry with `as: lead` in a document.
 *
 * @param {unknown} node A JSON node.
 * @param {Array<object>} found Collects the leads.
 * @return {Array<object>} The leads.
 */
function leads(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => leads(child, found))
	} else if (node && typeof node === 'object') {
		if (node.as === 'lead') {
			found.push(node)
		}
		Object.values(node).forEach((child) => leads(child, found))
	}
	return found
}

describe('facts leads', () => {
	it('every lead declares the title it draws', () => {
		for (const file of files) {
			for (const lead of leads(JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8')))) {
				expect(lead.title, `${file}: ${JSON.stringify(lead)}`).toBeTruthy()
			}
		}
	})

	it('opens the case page Aanvrager card with the requester\'s initials, name, how they logged in or else their kind, and a link by kind', () => {
		const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
		const page = simple.pages.find((entry) => entry.id === 'CaseDetail')
		const card = page.config.sideColumn.find((entry) => typeof entry === 'object' && entry.id === 'side-requester')
		expect(card.content.fields[0]).toMatchObject({ as: 'lead', title: 'initiatorDisplayName', initialsPath: 'initiatorDisplayName' })
		expect(card.content.fields[0].subtitle.firstOf.map((option) => option.path)).toEqual(['intakeIdentification', 'initiatorType'])
		expect(card.content.fields).toHaveLength(1)
		expect(card.content.link.route.nameMap).toEqual({ person: 'ContactDetail', company: 'OrganisationDetail' })
		expect(page.config.headerMeta).toBe('via {intakeSourceName}')
	})
})
