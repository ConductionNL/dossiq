/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's Hand to another team dialog (`case-hand-over`, a
 * CnAssignDialog) against board DqOverdragen: the eyebrow names the case
 * number, as every other case dialog's eyebrow does ("Overdragen · 2026-0082").
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

/**
 * Every action in the manifest with the given id, wherever it is declared.
 *
 * @param {object} node A manifest node.
 * @param {string} id The action id.
 * @param {Array<object>} found The actions found so far.
 * @return {Array<object>} The actions.
 */
function actionsWithId(node, id, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => actionsWithId(child, id, found))
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.target) {
			found.push(node)
		}
		Object.values(node).forEach((child) => actionsWithId(child, id, found))
	}
	return found
}

describe('the Hand to another team dialog against board DqOverdragen', () => {
	it('names the case number in the eyebrow', () => {
		const found = actionsWithId(manifest, 'case-hand-over')
		expect(found).toHaveLength(1)
		expect(found[0].props.config.eyebrow).toBe('Hand over · {identifier}')
		expect(nl['Hand over · {identifier}']).toBe('Overdragen · {identifier}')
	})
})
