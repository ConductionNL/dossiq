/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page's Archive this case dialog (`case-archive`, a CnActionDialog)
 * against board DqArchiveren: the eyebrow names the case number, the
 * retention facts end on the status the case gets, and the reason says how
 * the case comes back.
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

/**
 * The archive dialog's config.
 *
 * @return {object} The config.
 */
function archive() {
	const found = actionsWithId(manifest, 'case-archive')
	expect(found).toHaveLength(1)
	return found[0].props.config
}

describe('the Archive this case dialog against board DqArchiveren', () => {
	it('names the case number in the eyebrow ("Afronden · 2026-0082")', () => {
		expect(archive().eyebrow).toBe('Close off · {identifier}')
		expect(nl['Close off · {identifier}']).toBe('Afronden · {identifier}')
	})

	it('ends the retention facts on the status the case gets ("Gearchiveerd")', () => {
		const items = archive().facts[0].items
		expect(items.map((item) => item.label)).toEqual([
			'Archive nomination',
			'Retention period',
			'Destruction date',
			'Archive status afterwards',
		])
		expect(items[3].value).toBe('Archived')
		expect(nl.Archived).toBe('Gearchiveerd')
	})

	it('says under the reason how the case comes back', () => {
		const hint = 'Goes into the history of the case. To bring it back, use Restore from the archive under Next step.'
		expect(archive().reason.hint).toBe(hint)
		expect(nl[hint]).toBe('Komt in de historie van de zaak. Terughalen kan met Terughalen uit het archief, onder Volgende stap.')
	})
})
