// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The New case form's confirm button reads "Zaak aanmaken", as the
 * DqNieuweZaak board draws it, wherever the form opens.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'

/**
 * Every `new-case` open-form header action in the manifest.
 *
 * @return {Array<object>} The action entries.
 */
function newCaseActions() {
	return manifest.pages
		.flatMap((page) => (page.config && Array.isArray(page.config.headerActions) ? page.config.headerActions : []))
		.filter((action) => action.id === 'new-case' && action.type === 'open-form')
}

describe('New case, confirm button', () => {
	it('labels the create button on every New case form', () => {
		const actions = newCaseActions()
		expect(actions.length).toBeGreaterThan(0)
		for (const action of actions) {
			expect(action.formConfirmLabel).toBe('Create case')
		}
	})

	it('reads Zaak aanmaken in Dutch', () => {
		expect(nl.translations['Create case']).toBe('Zaak aanmaken')
	})
})
