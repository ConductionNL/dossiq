/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A message in the mailbox is filed on a case the intake worker picks, not only
 * on the one its subject suggests (capability-rows-say-the-whole-capability
 * 2.7, REQ-CRS-18). The row action opens the Link to case dialog (board
 * DqKoppelAanZaak), which proposes the case the subject names, searches any
 * other case and asks why when another is picked, and posts to the intake
 * log's file-on-case route (linkToCaseDialog.spec.js), the route that holds the intake role and the read check
 * on the picked case. A filed message has outcome `case`, so the queue, which
 * lists inbox and quarantined messages, no longer carries it.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/intake-from-a-channel/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.d/88-postvak.json'), 'utf8'))
const config = fragment.pages[0].config
const action = config.actions.find((candidate) => candidate.id === 'file-on-case')
const routes = fs.readFileSync(path.join(root, 'appinfo/routes.php'), 'utf8')
const registry = fs.readFileSync(path.join(root, 'src/registry.js'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations

describe('filing a mailbox message on a case', () => {
	it('is offered on every row as the one Link to case, before New case', () => {
		const cell = config.columns.find((column) => column.key === 'actions')
		expect(cell.widgetProps.actions).toEqual(['file-on-case', 'new-case-from-message'])
		expect(action.visibleWhen).toBeUndefined()
		expect(action.label).toBe('Link to case')
	})

	it('opens the Link to case dialog the board draws', () => {
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('LinkToCaseDialog')
		expect(registry).toMatch(/LinkToCaseDialog: \{\n\t\tkind: 'modal'/)
	})

	it('posts to the intake log route that files by hand', () => {
		const dialog = fs.readFileSync(path.join(root, 'src/modals/LinkToCaseDialog.vue'), 'utf8')
		expect(dialog).toContain('/apps/dossiq/api/mail-intake/log/${this.objectId}/file-on-case')
		expect(routes).toMatch(/'name' => 'mailIntake#fileOnCase', 'url' => '\/api\/mail-intake\/log\/\{entryId\}\/file-on-case', 'verb' => 'POST'/)
	})

	it('reads in Dutch', () => {
		for (const text of [action.label, 'When linking', 'Link to case {number}', 'Or search another case']) {
			expect(nl[text], text).toBeTruthy()
		}
	})
})
