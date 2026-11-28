// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What each lifecycle act asks for, as declarations for the library's
 * CnTransitionInputFields: archiving shows the case and its retention with
 * the read-only notice, promoting asks nothing, and every act asks in the
 * order the boards draw it.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { actForm } from '../../src/utils/caseActInputs.js'

const fieldsOf = (act) => actForm(act).inputs.map((input) => input.field)

describe('the lifecycle act forms', () => {
	it('shows the case and its retention when archiving, once the case is read, with the read-only notice', () => {
		const { summary, notice } = actForm({ kind: 'gesture', id: 'archive' })

		expect(summary).toMatchObject({
			when: 'kase',
			testid: 'case-act-archive',
			card: { title: 'kase.title', meta: ['kase.identifier', 'kase.initiatorDisplayName'], badge: 'kase.resultPublicLabel' },
			title: 'Retention according to the case type',
		})
		expect(summary.facts).toEqual([
			{ label: 'Archive designation', path: 'kase.archiveNomination', fallback: 'Not set' },
			{ label: 'Destruction date', path: 'kase.archiveActionDate', fallback: 'Not set' },
			{ label: 'Archive status after archiving', fallback: 'Archived' },
		])
		expect(notice).toEqual({
			type: 'info',
			icon: 'LockOutline',
			heading: 'Read-only after that',
			text: 'After archiving you can no longer change the documents, tasks and messages of this case. The case stays findable until the destruction date.',
		})
		expect(fieldsOf({ id: 'archive' })).toEqual(['reason', 'notifyRequester'])
	})

	it('tells under the archive reason how the case comes back, as board DqArchiveren draws it', () => {
		const reason = actForm({ kind: 'gesture', id: 'archive' }).inputs.find((input) => input.field === 'reason')
		expect(reason.note).toBe('Goes into the case history. You can retrieve it with Retrieve from the archive, under Next step.')

		const abort = actForm({ kind: 'gesture', id: 'abort' }).inputs.find((input) => input.field === 'reason')
		expect(abort.note).toBe('Goes into the history of the case.')
	})

	it('shows no summary or notice on any other act', () => {
		for (const id of ['suspend', 'finish', 'hold', 'resume']) {
			expect(actForm({ id })).toMatchObject({ summary: null, notice: null, title: '', confirmIcon: null })
		}
	})

	it('names the archive dialog and puts the archive icon on its confirm button', () => {
		const archive = actForm({ id: 'archive' })
		expect(archive.title).toBe('Archive case')
		expect(archive.confirmIcon?.name).toBe('ArchiveOutlineIcon')
	})

	it('asks each act for what it needs, in the board\'s order', () => {
		expect(fieldsOf({ id: 'finish' })).toEqual(['resultTemplate', 'reason', 'resultTypeId'])
		expect(fieldsOf({ id: 'suspend' })).toEqual(['pauseReason', 'reason', 'days'])
		expect(fieldsOf({ id: 'hold' })).toEqual(['reason', 'until'])
		expect(fieldsOf({ id: 'resume' })).toEqual(['reason'])
		expect(fieldsOf({ id: 'promote' })).toEqual([])
		expect(actForm(null)).toEqual({ inputs: [], columns: 1, summary: null, notice: null, title: '', confirmIcon: null })
	})

	it('keeps the reason under its board label and note', () => {
		expect(actForm({ id: 'resume' }).inputs[0]).toMatchObject({
			required: true,
			label: 'Reason',
			labelOutside: true,
			note: 'Goes into the history of the case.',
		})
	})
})
