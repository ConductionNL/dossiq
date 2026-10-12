/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reads behind the AI features panel: an undeclared case type asks
 * nobody, a feature hermiq does not offer reads unavailable rather than
 * local, a failed read is null rather than empty, and the group view carries
 * the count with the near-duplicates beside it.
 *
 * IT MOCKS AXIOS AND NOT THE SERVICE, so the requests made (and not made) are
 * what is asserted.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */
import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	caseSurfaceSlugs,
	fetchCaseDocuments,
	fetchCaseFeatures,
	fetchCasePrompts,
	fetchReportGroup,
	groupView,
	referenceId,
	whereItRuns,
} from '../../src/services/caseAiFeaturesApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match),
}))

/**
 * Answer the register reads and the feature endpoint by url.
 *
 * @param {object} routes Url fragment to response body.
 * @return {void}
 */
function answer(routes) {
	axios.get.mockImplementation(async (url) => {
		for (const [fragment, body] of Object.entries(routes)) {
			if (url.includes(fragment)) {
				if (body instanceof Error) {
					throw body
				}
				return { data: body }
			}
		}
		throw new Error(`unexpected request ${url}`)
	})
}

describe('caseAiFeaturesApi', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('reads an id from a plain reference and from an object', () => {
		expect(referenceId('ct-1')).toBe('ct-1')
		expect(referenceId({ id: 'ct-2' })).toBe('ct-2')
		expect(referenceId({ uuid: 'ct-3' })).toBe('ct-3')
		expect(referenceId(null)).toBe('')
	})

	it('keeps only the features placed on the case surface', () => {
		expect(
			caseSurfaceSlugs({
				aiFeatures: {
					'document-summary': 'case',
					'intake-chat': 'intake',
					off: 'none',
				},
			}),
		).toEqual(['document-summary'])
		expect(caseSurfaceSlugs({})).toEqual([])
		expect(caseSurfaceSlugs({ aiFeatures: ['case'] })).toEqual([])
	})

	it('asks nobody about features when the case type declares none on the case', async () => {
		answer({
			'/objects/dossiq/case/': { id: 'c-1', caseType: 'ct-1' },
			'/objects/dossiq/caseType/': {
				id: 'ct-1',
				aiFeatures: { 'intake-chat': 'intake' },
			},
		})

		await expect(fetchCaseFeatures('c-1')).resolves.toEqual([])
		expect(
			axios.get.mock.calls.some(([url]) =>
				url.includes('/api/assistant/ai-features'),
			),
		).toBe(false)
	})

	it('sends the declaration and answers the features with where they run', async () => {
		const features = [
			{
				slug: 'document-summary',
				surface: 'case',
				available: true,
				provider: 'Hermiq',
				residency: 'on-premise',
			},
		]
		answer({
			'/objects/dossiq/case/': { id: 'c-1', caseType: { id: 'ct-1' } },
			'/objects/dossiq/caseType/': {
				id: 'ct-1',
				aiFeatures: { 'document-summary': 'case' },
			},
			'/api/assistant/ai-features': { features },
		})

		await expect(fetchCaseFeatures('c-1')).resolves.toEqual(features)
		const featureCall = axios.get.mock.calls.find(([url]) =>
			url.includes('/api/assistant/ai-features'),
		)
		const query = new URLSearchParams(featureCall[0].split('?')[1])
		expect(query.get('surface')).toBe('case')
		expect(query.get('caseType[aiFeatures][document-summary]')).toBe('case')
	})

	it('answers null, not an empty list, when the features cannot be read', async () => {
		answer({ '/objects/dossiq/case/': new Error('403') })

		await expect(fetchCaseFeatures('c-1')).resolves.toBeNull()
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

	it('lists the case documents with their file id', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				results: [{ id: 42, title: 'besluit.pdf' }, { title: 'no id' }],
			},
		})
		await expect(fetchCaseDocuments('c-1')).resolves.toEqual([
			{ id: '42', title: 'besluit.pdf' },
		])

		axios.get.mockRejectedValueOnce(new Error('500'))
		await expect(fetchCaseDocuments('c-1')).resolves.toBeNull()
	})

	it('says a feature hermiq does not offer is unavailable, never local', () => {
		expect(
			whereItRuns({
				slug: 'x',
				available: false,
				provider: null,
				residency: null,
			}),
		).toBe('Unavailable: hermiq does not offer this feature here.')
		expect(
			whereItRuns({
				slug: 'x',
				available: true,
				provider: 'Hermiq',
				residency: 'on-premise',
			}),
		).toBe('Hermiq, runs in on-premise')
		expect(
			whereItRuns({ slug: 'x', available: true, provider: '', residency: '' }),
		).toContain('did not say')
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
	it("reads the prompts from dossiq's endpoint, and null when it fails", async () => {
		axios.get.mockResolvedValueOnce({
			data: { prompts: [{ id: 'p-1', label: 'L', prompt: 'P' }] },
		})
		await expect(fetchCasePrompts()).resolves.toEqual([
			{ id: 'p-1', label: 'L', prompt: 'P' },
		])
		expect(axios.get).toHaveBeenCalledWith('/apps/dossiq/api/assistant/prompts')

		axios.get.mockRejectedValueOnce(new Error('500'))
		await expect(fetchCasePrompts()).resolves.toBeNull()
	})
})
