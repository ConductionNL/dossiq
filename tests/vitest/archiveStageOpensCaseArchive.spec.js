/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqArchiveren: the closed stage's Archive button opens the same dialog as
 * the case-archive action of the Lifecycle menu.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const full = read('src/manifest.json').pages.find((page) => page.id === 'CaseDetail')
const caseArchive = full.config.headerActions.find((action) => action.id === 'case-archive')

describe('the closed stage', () => {
	it('opens the dialog the case-archive action opens', () => {
		const stage = simple.config.primaryActionByStage.closed
		expect(stage.target).toBe(caseArchive.target)
		expect(stage.props.config).toEqual(caseArchive.props.config)
	})

	it('names the case and offers to tell the requester', () => {
		const config = simple.config.primaryActionByStage.closed.props.config
		expect(config.eyebrow).toBe('Close off · {identifier}')
		expect(config.fields.map((field) => field.field)).toContain('notifyRequester')
	})

	it('names the retention period between the nomination and the destruction date', () => {
		const items = simple.config.primaryActionByStage.closed.props.config.facts[0].items
		expect(items.map((item) => item.label)).toEqual(['Archive nomination', 'Retention period', 'Destruction date', 'Archive status afterwards'])
		expect(items[1].value).toBe('{@self._retention.retentionPeriod|isoDuration}')
	})

	it('locks the read-only note, as the board draws it', () => {
		expect(simple.config.primaryActionByStage.closed.props.config.note.icon).toBe('LockOutline')
	})
})
