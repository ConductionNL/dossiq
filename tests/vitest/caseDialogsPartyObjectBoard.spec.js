/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two case dialogs against board DqZaakDialogen: "Partij toevoegen" and
 * "Object koppelen" are titled by what they do (they opened as "Rol aanmaken"
 * and "Zaakobject aanmaken"), confirm with the board's verb, ask their fields
 * in the board's order and carry no data-model hints.
 *
 * @spec openspec/specs/case-dialogs/spec.md#requirement-a-case-dialog-is-titled-by-what-it-does
 */

import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'
import simple from '../../src/menu-layout.simple.json'

const actions = manifest.pages.find((p) => p.id === 'CaseDetail').config.headerActions
const action = (id) => actions.find((a) => a.id === id)
const dutch = (key) => nl.translations[key]

/**
 * The first object with an id, anywhere in a tree.
 *
 * @param {unknown} node The tree.
 * @param {string} id The id.
 * @return {object|null} The object.
 */
function withId(node, id) {
	if (node && typeof node === 'object') {
		if (node.id === id) {
			return node
		}
		for (const child of Object.values(node)) {
			const found = withId(child, id)
			if (found) {
				return found
			}
		}
	}
	return null
}

/**
 * The fields of an open-form action in the order the form asks them.
 *
 * @param {object} form The action.
 * @return {Array<string>} The field keys.
 */
function asked(form) {
	return [...form.includeFields].sort((a, b) => (form.fieldOverrides[a]?.order ?? 99) - (form.fieldOverrides[b]?.order ?? 99))
}

describe('case dialogs against DqZaakDialogen', () => {
	it('titles and confirms Partij toevoegen as the board does, fields in its order, no model hints', () => {
		const form = action('add-party')
		expect(dutch(form.formTitle)).toBe('Partij toevoegen')
		expect(dutch(form.formConfirmLabel)).toBe('Toevoegen')
		expect(asked(form).slice(0, 6)).toEqual(['name', 'roleType', 'participant', 'representedParty', 'delegateUntil', 'description'])
		for (const key of form.includeFields) {
			expect(form.fieldOverrides[key]?.description, key).toBe('')
		}
	})

	it('titles and confirms Object koppelen as the board does, fields in its order, no model hints', () => {
		const form = action('link-object')
		expect(dutch(form.formTitle)).toBe('Object koppelen')
		expect(dutch(form.formConfirmLabel)).toBe('Koppelen')
		expect(asked(form)).toEqual(['objectType', 'objectIdentification', 'objectUrl', 'description'])
		expect(dutch(form.fieldOverrides.objectIdentification.label)).toBe('Identificatie')
		expect(dutch(form.fieldOverrides.objectUrl.label)).toBe('Link naar het object')
		for (const key of form.includeFields) {
			expect(form.fieldOverrides[key]?.description, key).toBe('')
		}
	})

	it('draws the objects card\'s Object koppelen as the same form', () => {
		const from = withId(simple, 'link-object-from-card')
		const page = action('link-object')
		expect([from.formTitle, from.formConfirmLabel]).toEqual([page.formTitle, page.formConfirmLabel])
		expect(from.fieldOverrides).toEqual(page.fieldOverrides)
	})
})
