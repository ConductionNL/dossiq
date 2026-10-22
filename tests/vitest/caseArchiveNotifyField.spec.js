/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Archive this case dialog offers to tell the requester, as board
 * DqArchiveren draws it ("Sanne de Vries laten weten dat de zaak is
 * afgehandeld", ticked). CnActionDialog posts each field beside the reason, so
 * the checkbox reaches the archive act as `notifyRequester`.
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

describe('the Archive this case dialog tells the requester', () => {
	it('offers a ticked checkbox that posts notifyRequester to the archive act', () => {
		const found = actionsWithId(manifest, 'case-archive')
		expect(found).toHaveLength(1)
		const config = found[0].props.config

		expect(config.confirm.request.url).toBe('/apps/dossiq/api/case/@objectId/archive')
		expect(config.fields).toEqual([
			{
				field: 'notifyRequester',
				type: 'checkbox',
				label: 'Let {initiatorDisplayName} know the case is completed',
				hint: 'A message with the result, in the portal, the Berichtenbox or by e-mail',
				default: true,
				required: false,
			},
		])
		expect(nl['Let {initiatorDisplayName} know the case is completed']).toBe('{initiatorDisplayName} laten weten dat de zaak is afgehandeld')
		expect(nl['A message with the result, in the portal, the Berichtenbox or by e-mail']).toBe('Bericht met het resultaat, in het portaal, de Berichtenbox of per e-mail')
	})
})
