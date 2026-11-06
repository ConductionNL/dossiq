/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqArchiveren: the archive dialog's summary names the case's result in a
 * teal pill, as the board draws "Deels openbaar gemaakt".
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

/**
 * Every action-dialog config titled "Archive this case" in a layout.
 *
 * @param {unknown} node The layout or a part of it.
 * @param {Array<object>} found The configs found so far.
 * @return {Array<object>} The configs.
 */
function archiveDialogs(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => archiveDialogs(item, found))
	} else if (node && typeof node === 'object') {
		if (node.title === 'Archive this case' && node.summary) {
			found.push(node)
		}
		Object.values(node).forEach((value) => archiveDialogs(value, found))
	}
	return found
}

describe('the archive dialog summary', () => {
	it.each(['menu-layout.simple.json', 'manifest.json'])('draws the result as a teal pill (%s)', (file) => {
		const layout = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src', file), 'utf8'))
		const dialogs = archiveDialogs(layout)
		expect(dialogs.length).toBeGreaterThan(0)
		for (const dialog of dialogs) {
			expect(dialog.summary.badge).toBe('{resultPublicLabel}')
			expect(dialog.summary.badgeVariant).toBe('teal')
		}
	})
})
