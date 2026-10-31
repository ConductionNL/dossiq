/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakBetrokkenen: "Partijen" first, the people on the case grouped by role
 * with Contact koppelen and Nieuw contact, then "Rollen" from OpenRegister's
 * party model, then the seats. Each card carries the board's line under its
 * title.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const nl = read('l10n/nl.json').translations
const en = read('l10n/en.json').translations
const card = (id) => simple.configAppend.widgets.find((widget) => widget.id === id)

function findTab (node) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const found = findTab(child)
			if (found) {
				return found
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.label === 'People' && Array.isArray(node.widgetIds)) {
			return node
		}
		for (const child of Object.values(node)) {
			const found = findTab(child)
			if (found) {
				return found
			}
		}
	}
	return null
}

describe('the People tab', () => {
	it('opens with Partijen, then Rollen, then the seats', () => {
		expect(findTab(simple).widgetIds).toEqual(['case-parties-card', 'case-party-roles-card', 'case-seats-card'])
	})

	it('lists the people by role in the Partijen card, as the contacts tab', () => {
		const parties = card('case-parties-card')
		expect(nl[parties.title]).toBe('Partijen')
		expect(nl[parties.subtitle]).toBe('Iedereen die bij deze zaak betrokken is, per rol')
		expect(en[parties.subtitle]).toBe(parties.subtitle)
		expect(parties.content.view).toBe('tab')
	})

	it('reads the party model in the Rollen card', () => {
		const roles = card('case-party-roles-card')
		expect(nl[roles.title]).toBe('Rollen')
		expect(nl[roles.subtitle]).toBe('Uit het partijmodel van OpenRegister, ook voor wie geen account heeft')
		expect(en[roles.subtitle]).toBe(roles.subtitle)
	})

	it('puts Betrokkene toevoegen in the Rollen title row, so the accepted kinds come first under the title', () => {
		expect(card('case-party-roles-card').content.manage.add.placement).toBe('title')
	})
})

describe('the Partijen button row', () => {
	it('ends with Partij toevoegen, the same form as the page action', () => {
		const manifest = read('src/manifest.json')
		const pageAction = (function find(node) {
			if (Array.isArray(node)) {
				for (const child of node) {
					const found = find(child)
					if (found) {
						return found
					}
				}
			} else if (node && typeof node === 'object') {
				if (node.id === 'add-party' && node.type === 'open-form') {
					return node
				}
				for (const child of Object.values(node)) {
					const found = find(child)
					if (found) {
						return found
					}
				}
			}
			return null
		})(manifest)
		const [entry] = card('case-parties-card').bodyActions
		expect(nl[entry.label]).toBe('Partij toevoegen')
		expect(entry.visibleWhen).toEqual(pageAction.visibleWhen)
		const { id, label, icon, visibleWhen, ...action } = pageAction
		expect(entry.icon).toBe(icon)
		expect(entry.action).toEqual(action)
	})
})
