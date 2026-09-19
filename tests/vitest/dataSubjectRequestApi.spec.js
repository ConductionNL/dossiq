/**
 * The AVG client: which endpoint each act calls, and what it does with a
 * refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import axios from '@nextcloud/axios'
import {
	fetchSubjectExportState,
	previewErasure,
	refusalOf,
	requestSubjectExport,
	runErasure,
	subjectExportDownloadUrl,
} from '../../src/services/dataSubjectRequestApi.js'

describe('the AVG client', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('takes the preview from the case, naming no subject', async () => {
		axios.post.mockResolvedValue({
			data: { uuid: 'p1', report: { protected: [] } },
		})

		const preview = await previewErasure('c1')

		expect(preview.uuid).toBe('p1')
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/c1/avg/erasure-preview',
		)
		// The subject and the erase mode come off the case on the server. A
		// body here would be a door into counting what the instance holds
		// about a person the caller cannot otherwise reach.
		expect(axios.post.mock.calls[0]).toHaveLength(1)
	})

	it('runs the erasure on its own endpoint', async () => {
		axios.post.mockResolvedValue({ data: { complete: false, withheld: ['o1'] } })

		const outcome = await runErasure('c1')

		expect(outcome.complete).toBe(false)
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/c1/avg/erasure-run',
		)
	})

	it('asks for and reads the export on the same url, by verb', async () => {
		axios.post.mockResolvedValue({ data: { uuid: 'e1' } })
		axios.get.mockResolvedValue({ data: { exportId: 'e1', downloadable: true } })

		await requestSubjectExport('c1')
		const state = await fetchSubjectExportState('c1')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/c1/avg/subject-export',
		)
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/c1/avg/subject-export',
		)
		expect(state.downloadable).toBe(true)
	})

	it('escapes the case id rather than pasting it into the path', async () => {
		axios.post.mockResolvedValue({ data: {} })

		await previewErasure('a/b c')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/dossiq/api/cases/a%2Fb%20c/avg/erasure-preview',
		)
	})

	it('serves the file from the platform, not from dossiq', () => {
		expect(subjectExportDownloadUrl('e1')).toBe(
			'/apps/openregister/api/gdpr/subject-exports/e1/download',
		)
	})

	it('keeps the server rule and sentence on a refusal', () => {
		const refusal = refusalOf({
			response: {
				data: {
					error: 'erasure-preview-stale',
					message: 'Take the preview again.',
				},
			},
		})

		expect(refusal.rule).toBe('erasure-preview-stale')
		expect(refusal.message).toBe('Take the preview again.')
	})

	it('says nothing was erased when the server said nothing at all', () => {
		const refusal = refusalOf(new Error('network down'))

		expect(refusal.rule).toBe('data-subject-request-failed')
		expect(refusal.message).toContain('Nothing was erased')
	})
})
