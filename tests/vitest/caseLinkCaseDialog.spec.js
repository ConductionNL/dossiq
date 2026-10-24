/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Link case on the Related tab (board DqZaakGerelateerd, "Zaak koppelen"): a
 * CnActionDialog in which the handler picks a case and the kind of relation,
 * posted to dossiq's relations API. The API needs `targetId` and
 * `aardRelatie`, and refuses a relation type it does not know, so the dialog
 * offers exactly the types CaseRelationService accepts.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const relationService = fs.readFileSync(path.join(ROOT, 'lib', 'Service', 'CaseRelationService.php'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')

/**
 * The first widget with the given id, wherever it is declared.
 *
 * @param {object} node A manifest node.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function widgetWithId(node, id) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const found = widgetWithId(child, id)
			if (found) {
				return found
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
			return node
		}
		for (const child of Object.values(node)) {
			const found = widgetWithId(child, id)
			if (found) {
				return found
			}
		}
	}
	return null
}

/**
 * The Link case action of the Related cases widget.
 *
 * @return {object} The action.
 */
function linkCase() {
	const related = widgetWithId(manifest, 'case-related')
	const action = (related.content.footerActions || []).find((entry) => entry.label === 'Link case')
	expect(action).toBeDefined()
	return action
}

describe('Link case on the Related tab', () => {
	it('opens a CnActionDialog for the case on the page, before Plan follow-up', () => {
		const related = widgetWithId(manifest, 'case-related')
		expect(related.content.footerActions.map((entry) => entry.label)).toEqual(['Link case', 'Plan follow-up'])

		const action = linkCase()
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CnActionDialog')
		expect(action.icon).toBe('LinkVariant')
		expect(action.props.objectId).toBe('@objectId')
		expect(nl['Link case']).toBe('Zaak koppelen')
	})

	it('asks for a case through a case search-select and for the kind of relation', () => {
		const { config } = linkCase().props
		expect(config.title).toBe('Link case')
		expect(config.eyebrow).toBe('Related · {identifier}')

		const [target, kind, notes] = config.fields
		expect(target).toMatchObject({
			field: 'targetId',
			type: 'search',
			label: 'Case',
			required: true,
			source: { register: 'dossiq', schema: 'case' },
			optionLabel: '{title}',
			optionSecondary: '{identifier}',
			excludeSelf: true,
		})
		expect(kind).toMatchObject({ field: 'aardRelatie', type: 'select', label: 'Relation type', required: true })
		expect(notes).toMatchObject({ field: 'notes', type: 'text', required: false })
	})

	it('offers exactly the relation types the relations API accepts', () => {
		const declared = /RELATION_TYPES = \[([^\]]*)\]/.exec(relationService)[1]
			.split(',').map((entry) => entry.trim().replace(/'/g, ''))
		const kind = linkCase().props.config.fields.find((field) => field.field === 'aardRelatie')
		expect(kind.options.map((option) => option.value)).toEqual(declared)
		for (const option of kind.options) {
			expect(nl[option.label], option.label).toBeTruthy()
		}
	})

	it('is offered in the header of the simple profile\'s Related cases card, the same dialog', () => {
		const card = widgetWithId(simple, 'case-related-cases-card')
		const [entry] = card.headerActions
		const { label, icon, ...action } = linkCase()
		expect({ label: entry.label, icon: entry.icon }).toEqual({ label, icon })
		expect(entry.action).toEqual(action)
	})

	it('posts to the relations API and needs no reason', () => {
		const { config } = linkCase().props
		expect(config.confirm.request).toEqual({ method: 'POST', url: '/apps/dossiq/api/cases/@objectId/relations' })
		expect(routes).toContain("'url' => '/api/cases/{caseId}/relations',                          'verb' => 'POST'")
		expect(config.reason).toBeUndefined()
		expect(nl[config.confirm.label]).toBe('Koppelen')
	})
})
