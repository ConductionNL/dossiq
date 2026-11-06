/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqArchiveren r34: the archive dialog draws the "let the requester know"
 * checkbox after the reason and the read-only note, and the destruction
 * date as a date, in both the full and the simple case page. The order needs
 * nextcloud-vue fix/r34-action-dialog-archive-rhythm (`fieldsAfterNote`);
 * before it the key is ignored.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * @param {object} node Any JSON node.
 * @param {Array<object>} found The archive dialog configs found so far.
 * @return {Array<object>} Every dialog config whose note is "Read only afterwards".
 */
function archiveConfigs(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => archiveConfigs(item, found))
	} else if (node && typeof node === 'object') {
		if (node.note && node.note.title === 'Read only afterwards' && Array.isArray(node.fields)) {
			found.push(node)
		}
		Object.values(node).forEach((value) => archiveConfigs(value, found))
	}
	return found
}

describe('the archive dialog in the board\'s order', () => {
	for (const file of ['src/manifest.json', 'src/menu-layout.simple.json']) {
		it(`puts the fields after the note and formats the destruction date (${file})`, () => {
			const configs = archiveConfigs(JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8')))
			expect(configs.length).toBeGreaterThan(0)
			for (const config of configs) {
				expect(config.fieldsAfterNote).toBe(true)
				const items = config.facts.flatMap((section) => section.items)
				expect(items.find((item) => item.label === 'Destruction date').value).toBe('{archiveActionDate|date}')
			}
		})
	}
})
