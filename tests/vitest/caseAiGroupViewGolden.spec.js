/**
 * Golden test for groupView: the whole view for every shape of report-group
 * answer, recorded before the function was split into steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const { groupView } = await import('../../src/services/caseAiFeaturesApi.js')

describe('groupView', () => {
	it('answers every shape of report-group answer as before', () => {
		const answers = [
			null,
			undefined,
			{},
			{ declared: 'true' },
			{ declared: true },
			{ declared: true, available: true, group: null },
			{ declared: true, available: true, group: 'x', reason: 'busy', gate: 'hermiq.reports' },
			{ declared: true, available: false, group: {}, reason: '' },
			{ declared: true, available: true, group: {} },
			{
				declared: true,
				available: true,
				group: {
					count: '12',
					nearDuplicates: [
						{ reportId: 'r1', score: '0.9' },
						null,
						{ reportId: '' },
						{ reportId: 7 },
						{ score: 1 },
					],
				},
			},
			{ declared: true, available: true, group: { count: 3, nearDuplicates: 'r1' } },
		]
		expect(answers.map((answer) => groupView(answer))).toMatchSnapshot()
	})
})
