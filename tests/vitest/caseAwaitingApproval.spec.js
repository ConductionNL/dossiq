/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case says what it is waiting for, and on whom (REQ-DEC-01).
 *
 * 🔴 THE RENDERING THAT WOULD BE WRONG IS THE QUIET ONE. An approval decidiq
 * named nobody for must not render as a row with an empty name list: that
 * reads as "waiting on nobody", which is the one thing it never means. And a
 * `/acts` answer that failed, or came from an instance that predates this
 * change, must show no panel at all rather than an empty "waiting for" box
 * that looks like a case blocked on something nameless.
 *
 * 🔑 NOTHING HERE DECIDES ANYTHING. Every sentence is the server's, which read
 * decidiq. These assertions are about shaping, and a derived verdict appearing
 * in this file later would be the second authority the whole design avoids.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	approvalHeading,
	approversLine,
	awaitingApprovals,
	isAwaitingApproval,
} from '../../src/utils/caseAwaitingApproval.js'

/** An `/acts` answer for a case waiting on two people. @return {object} The body. */
function waitingOnTwo() {
	return {
		caseId: 'case-1',
		acts: [],
		awaitingApproval: [
			{
				act: 'send-besluit',
				label: 'Approval by the teamleider',
				sentence:
					'Approval by the teamleider is still open. It is waiting on Sanne de Wit, Joris Bakker.',
				approvers: ['Sanne de Wit', 'Joris Bakker'],
				decisionRef: 'dec-7f3c',
			},
		],
	}
}

describe('what the case is waiting for', () => {
	it('names the approval and both people it waits on', () => {
		const rows = awaitingApprovals(waitingOnTwo())

		expect(rows).toHaveLength(1)
		expect(approvalHeading(rows[0])).toBe('Approval by the teamleider')
		expect(approversLine(rows[0])).toBe('Sanne de Wit, Joris Bakker')
		// The sentence is the server's, passed through and not rebuilt: a
		// browser-side rebuild would eventually differ from the refusal the
		// write path gives for the same approval.
		expect(rows[0].sentence).toBe(waitingOnTwo().awaitingApproval[0].sentence)
	})

	it('says the approval is outstanding at all', () => {
		expect(isAwaitingApproval(waitingOnTwo())).toBe(true)
	})

	it('shows nothing when the case waits on nothing', () => {
		expect(awaitingApprovals({ caseId: 'case-1', acts: [] })).toEqual([])
		expect(isAwaitingApproval({ caseId: 'case-1', acts: [] })).toBe(false)
	})

	it('shows nothing when the acts read failed', () => {
		// `allSettled` hands the dialog null for an endpoint that was down. A
		// panel drawn here would tell a handler the case is blocked on
		// something, on the strength of an answer nobody received.
		expect(awaitingApprovals(null)).toEqual([])
		expect(isAwaitingApproval(undefined)).toBe(false)
	})

	it('renders no names when decidiq named none, rather than an empty list', () => {
		const rows = awaitingApprovals({
			awaitingApproval: [
				{
					act: 'send-besluit',
					label: 'Approval by the teamleider',
					sentence:
						'Approval by the teamleider is still open. decidiq did not say who it is waiting on. Open the approval there to see.',
					approvers: [],
				},
			],
		})

		// Empty, so the template's `v-if` leaves the people line out entirely
		// and the sentence beside it carries the explanation.
		expect(approversLine(rows[0])).toBe('')
		expect(rows[0].sentence).toContain('did not say who')
	})

	it('drops a row that names no act', () => {
		// A row with no act points at no button and no panel. Rendered, it is a
		// line saying something is blocked and never saying what.
		const rows = awaitingApprovals({
			awaitingApproval: [
				{ label: 'Nameless', sentence: 'Something is open.' },
				'not an object',
				{ act: 'send-besluit', label: 'Real', sentence: 'Still open.' },
			],
		})

		expect(rows.map((row) => row.act)).toEqual(['send-besluit'])
	})

	it('heads a row by its act when the approval carries no label', () => {
		const rows = awaitingApprovals({
			awaitingApproval: [{ act: 'send-besluit', sentence: 'Still open.' }],
		})

		expect(approvalHeading(rows[0])).toBe('send-besluit')
	})
})
