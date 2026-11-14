/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A message in the mailbox is filed on a case the intake worker picks, not only
 * on the one its subject suggests (capability-rows-say-the-whole-capability
 * 2.7, REQ-CRS-18). The row action opens the library's action dialog with a
 * case search and a required reason, and posts to the intake log's
 * file-on-case route, the route that holds the intake role and the read check
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
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations

describe('filing a mailbox message on a case', () => {
	it('is offered on every row, between Link to case and New case', () => {
		const cell = config.columns.find((column) => column.key === 'actions')
		expect(cell.widgetProps.actions).toEqual(['link-to-case', 'file-on-case', 'new-case-from-message'])
		expect(action.visibleWhen).toBeUndefined()
	})

	it('asks for any case and a reason, both required', () => {
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CnActionDialog')
		const fields = action.props.config.fields
		expect(fields.map((field) => [field.field, field.type, field.required])).toEqual([
			['caseId', 'search', true],
			['reason', 'text', true],
		])
		expect(fields[0].source).toEqual({ register: 'dossiq', schema: 'case' })
	})

	it('posts to the intake log route that files by hand', () => {
		const request = action.props.config.confirm.request
		expect(request).toEqual({ method: 'POST', url: '/apps/dossiq/api/mail-intake/log/@objectId/file-on-case' })
		expect(routes).toMatch(/'name' => 'mailIntake#fileOnCase', 'url' => '\/api\/mail-intake\/log\/\{entryId\}\/file-on-case', 'verb' => 'POST'/)
	})

	it('reads in Dutch', () => {
		const config = action.props.config
		for (const text of [action.label, config.title, config.confirm.label, config.doneText, config.fields[1].placeholder]) {
			expect(nl[text], text).toBeTruthy()
		}
	})
})
