/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The report group read: a failed read is null rather than empty, and the
 * group view carries the count with the near-duplicates beside it. The
 * feature reads moved to the library `ai-features` widget and are pinned by
 * its specs (tests/utils/aiFeatures.spec.js).
 *
 * IT MOCKS AXIOS AND NOT THE SERVICE, so the requests made (and not made) are
 * what is asserted.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */
import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	fetchReportGroup,
	groupView,
} from '../../src/services/caseAiFeaturesApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match),
}))

describe('caseAiFeaturesApi', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('reads the report group by case id, and null when it fails', async () => {
		axios.get.mockResolvedValueOnce({
			data: { declared: false, available: false, group: null },
		})
		await expect(fetchReportGroup('c-1')).resolves.toEqual({
			declared: false,
			available: false,
			group: null,
		})
		expect(axios.get).toHaveBeenCalledWith('/apps/dossiq/api/report-group', {
			params: { caseId: 'c-1' },
		})

		axios.get.mockRejectedValueOnce(new Error('404'))
		await expect(fetchReportGroup('c-1')).resolves.toBeNull()
	})

	it('shows nothing for a case type that does not group reports', () => {
		expect(
			groupView({ declared: false, available: false, group: null }).state,
		).toBe('off')
	})

	it('names the reason and the gate when the group cannot be read', () => {
		const view = groupView({
			declared: true,
			available: false,
			group: null,
			reason: 'Refused.',
			gate: 'consent',
		})
		expect(view).toMatchObject({
			state: 'unavailable',
			reason: 'Refused.',
			gate: 'consent',
		})
		expect(groupView(null).state).toBe('unavailable')
	})

	it('carries the count with the near-duplicates beside it', () => {
		const view = groupView({
			declared: true,
			available: true,
			group: {
				groupId: 'g-7',
				count: 200,
				nearDuplicates: [
					{ reportId: 'case-9', score: 0.61, decidedBy: 'model' },
					{ reportId: '' },
				],
			},
		})
		expect(view.state).toBe('grouped')
		expect(view.count).toBe(200)
		expect(view.nearDuplicates).toEqual([{ reportId: 'case-9', score: 0.61 }])
	})
})
