/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case AI feature gateway: a document-reading feature without a reference
 * never reaches hermiq, the reference travels with the request, and a refusal
 * becomes a sentence naming the feature and the reason.
 *
 * IT MOCKS AXIOS AND NOT THE GATEWAY, so the outbound request shape is what
 * is asserted.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-request-that-reads-a-document-carries-the-reference-and-a-refusal-is-shown-as-one-req-aic-03
 */
import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	AiFeatureRefusal,
	MISSING_REFERENCE,
	refusalSentence,
	runFeatureOnDocument,
} from '../../src/services/caseAiFeatureGateway.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace('{slug}', params.slug ?? ''),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match),
}))

describe('caseAiFeatureGateway', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('refuses a run without a usable reference before any request', async () => {
		for (const reference of [undefined, '', '  ', 'besluit.pdf']) {
			await expect(
				runFeatureOnDocument({
					feature: 'document-summary',
					documentReference: reference,
					instruction: 'Vat samen',
				}),
			).rejects.toMatchObject({
				name: 'AiFeatureRefusal',
				gate: MISSING_REFERENCE,
				feature: 'document-summary',
			})
		}

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('carries the reference with the request to hermiq', async () => {
		axios.post.mockResolvedValue({
			data: {
				feature: 'document-summary',
				documentReference: '42',
				output: 'Verleend.',
				notices: [],
			},
		})

		const result = await runFeatureOnDocument({
			feature: 'document-summary',
			documentReference: 42,
			instruction: 'Vat samen',
		})

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/hermiq/api/ai-features/document-summary/run-on-document',
			{ documentReference: '42', instruction: 'Vat samen' },
		)
		expect(result.output).toBe('Verleend.')
	})

	it("turns a gate refusal into a refusal carrying hermiq's gate and sentence", async () => {
		axios.post.mockRejectedValue({
			response: {
				status: 422,
				data: { error: 'Refused by the redaction check', gate: 'redaction' },
			},
		})

		const refusal = await runFeatureOnDocument({
			feature: 'document-summary',
			documentReference: '42',
			instruction: 'Vat samen',
		}).catch((error) => error)

		expect(refusal).toBeInstanceOf(AiFeatureRefusal)
		expect(refusal.gate).toBe('redaction')
		expect(refusal.reason).toBe('Refused by the redaction check')
	})

	it('lets a transport failure through as the failure it is', async () => {
		const failure = new Error('Network Error')
		axios.post.mockRejectedValue(failure)

		await expect(
			runFeatureOnDocument({
				feature: 'document-summary',
				documentReference: '42',
				instruction: 'Vat samen',
			}),
		).rejects.toBe(failure)
	})

	it('names the feature and the reason in the sentence a handler reads', () => {
		expect(
			refusalSentence(
				new AiFeatureRefusal('document-summary', 'x', 'redaction'),
				'Samenvatting',
			),
		).toBe('Samenvatting will not read a document that has not been redacted.')
		expect(
			refusalSentence(
				new AiFeatureRefusal('document-summary', 'x', MISSING_REFERENCE),
				'Samenvatting',
			),
		).toBe('Samenvatting reads a document, and no document was chosen.')
		expect(
			refusalSentence(
				new AiFeatureRefusal(
					'document-summary',
					'Refused by the residency check',
					'residency',
				),
				'Samenvatting',
			),
		).toBe('Samenvatting did not run: Refused by the residency check')
	})
})
