/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Ontvangst bevestigd vastleggen" asks what DqZaakDialogen asks: whether the
 * confirmation really went outside dossiq, and that the Awb 4:3a term then
 * counts as met, and confirms with "Vastleggen" and a check, where it asked
 * "Weet je zeker dat je wilt doorgaan?" and confirmed with "Bevestigen".
 *
 * @spec openspec/specs/case-dialogs/spec.md#requirement-a-case-dialog-is-titled-by-what-it-does
 */

import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'

/**
 * The first object with an id, anywhere in a tree.
 *
 * @param {unknown} node The tree.
 * @param {string} id The id.
 * @return {object|null} The object.
 */
function withId(node, id) {
	if (node && typeof node === 'object') {
		if (node.id === id && node.type) {
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

describe('Ontvangst bevestigd vastleggen against DqZaakDialogen', () => {
	it('asks the board\'s question and confirms with Vastleggen and a check', () => {
		const action = withId(manifest, 'case-acknowledgement-met')
		expect(action.confirm).toBe(true)
		expect(nl.translations[action.confirmMessage]).toBe('Weet u het zeker? De bevestiging ging buiten dossiq om, bijvoorbeeld per brief. De termijn van Awb 4:3a geldt dan als gehaald.')
		expect(nl.translations[action.confirmLabel]).toBe('Vastleggen')
		expect(action.confirmIcon).toBe('Check')
	})
})
