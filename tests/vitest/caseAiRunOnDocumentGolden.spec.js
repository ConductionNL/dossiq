/**
 * Golden test for runFeatureOnDocument: what is asked and what comes back or is
 * thrown, for every reference and every answer, recorded before it was split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { describe, expect, it, vi } from 'vitest'
import { AiFeatureRefusal, runFeatureOnDocument } from '../../src/services/caseAiFeatureGateway.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace('{slug}', params.slug),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))

/**
 * What one run answers or throws, as plain data.
 *
 * @param {object} request The run.
 * @return {Promise<object>} The outcome.
 */
async function outcomeOf(request) {
	try {
		return { answer: await runFeatureOnDocument(request) }
	} catch (error) {
		return {
			thrown: error instanceof AiFeatureRefusal ? 'AiFeatureRefusal' : error.constructor.name,
			message: error.message,
			feature: error.feature,
			reason: error.reason,
			gate: error.gate,
		}
	}
}

describe('runFeatureOnDocument', () => {
	it('asks and answers as before', async () => {
		const failing = (status, data) => Object.assign(new Error(`status ${status}`), { response: { status, data } })
		const answers = [
			{ data: { output: 'samenvatting', notices: [] } },
			failing(400, { error: 'Bad', gate: 'hermiq.input' }),
			failing(403, {}),
			failing(404, { error: 'Unknown feature' }),
			failing(422, { error: 'Unredacted', gate: 'hermiq.redaction' }),
			failing(500, { error: 'Down' }),
			new Error('offline'),
		]
		const outcomes = []
		for (const reference of [undefined, null, '', ' 12a ', 'abc']) {
			outcomes.push(await outcomeOf({ feature: 'summary', documentReference: reference, instruction: 'x' }))
		}
		for (const answer of answers) {
			if (answer instanceof Error) {
				axios.post.mockRejectedValueOnce(answer)
			} else {
				axios.post.mockResolvedValueOnce(answer)
			}
			outcomes.push(await outcomeOf({ feature: 'summary', documentReference: ' 42 ', instruction: 'Vat samen' }))
		}
		axios.post.mockResolvedValueOnce({ data: 'ok' })
		outcomes.push(await outcomeOf({ feature: 'summary', documentReference: 7, instruction: undefined }))
		expect({ outcomes, asked: axios.post.mock.calls }).toMatchSnapshot()
	})
})
