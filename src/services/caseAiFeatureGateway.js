/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Runs a case type's AI feature on a case document, through hermiq
 * (ai-features-on-the-case-consume-hermiq, REQ-AIC-03, design D-3).
 *
 * THE REFERENCE IS REQUIRED BY THE REQUEST SHAPE. hermiq's redaction gate sits
 * on the document, so a request that quietly leaves the reference out gets a
 * run nobody checked, and it looks exactly like success. A request without a
 * usable reference is therefore refused HERE, before any request is sent.
 *
 * IT CALLS HERMIQ IN THE HANDLER'S OWN SESSION. hermiq reads the document in
 * the signed-in person's Files, so the handler's own access decides what can be
 * read. Routing this through dossiq's backend would read it as a service
 * account instead.
 *
 * A REFUSAL IS A SENTENCE, NOT "AI ERROR". hermiq names the gate that refused,
 * and refusalSentence() turns it into words naming the feature and the reason,
 * because a generic failure sends a handler to the wrong person.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-request-that-reads-a-document-carries-the-reference-and-a-refusal-is-shown-as-one-req-aic-03
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The gate name used for a request dossiq refused before sending it.
 *
 * @type {string}
 */
export const MISSING_REFERENCE = 'missing-reference'

/**
 * A run that was refused, by dossiq before sending or by one of hermiq's gates.
 */
export class AiFeatureRefusal extends Error {

	/**
	 * @param {string} feature The feature slug.
	 * @param {string} reason The refusing side's own sentence.
	 * @param {string|null} gate The gate that refused, when one was named.
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-an-unredacted-document-is-refused-in-words-a-handler-can-act-on
	 */
	constructor(feature, reason, gate) {
		super(reason)
		this.name = 'AiFeatureRefusal'
		this.feature = feature
		this.reason = reason
		this.gate = gate
	}

}

/**
 * Run one AI feature on one case document.
 *
 * @param {object} request The run.
 * @param {string} request.feature The feature slug the case type declared.
 * @param {string|number} request.documentReference The document's Nextcloud file id.
 * @param {string} request.instruction What the feature should do with the document.
 * @return {Promise<{feature: string, documentReference: string, output: string, notices: Array<string>}>} hermiq's answer.
 * @throws {AiFeatureRefusal} When the reference is missing, or hermiq refuses.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-a-document-reading-feature-without-a-reference-never-reaches-hermiq
 */
export async function runFeatureOnDocument({ feature, documentReference, instruction }) {
	const reference = String(documentReference ?? '').trim()
	if (/^\d+$/.test(reference) === false) {
		throw new AiFeatureRefusal(feature, t('dossiq', 'No document was chosen.'), MISSING_REFERENCE)
	}

	const url = generateUrl('/apps/hermiq/api/ai-features/{slug}/run-on-document', { slug: feature })
	try {
		const response = await axios.post(url, { documentReference: reference, instruction })
		return response.data
	} catch (error) {
		const status = error?.response?.status
		const data = error?.response?.data ?? {}
		if ([400, 403, 404, 422].includes(status) === true) {
			throw new AiFeatureRefusal(feature, String(data.error ?? ''), data.gate ?? null)
		}

		throw error
	}
}

/**
 * The sentence a handler reads for a refusal, naming the feature and the reason.
 *
 * @param {AiFeatureRefusal} refusal The refusal.
 * @param {string} featureLabel The feature's name as the handler knows it.
 * @return {string} The sentence.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-an-unredacted-document-is-refused-in-words-a-handler-can-act-on
 */
export function refusalSentence(refusal, featureLabel) {
	if (refusal.gate === MISSING_REFERENCE) {
		return t('dossiq', '{feature} reads a document, and no document was chosen.', { feature: featureLabel })
	}

	if (refusal.gate === 'redaction') {
		return t('dossiq', '{feature} will not read a document that has not been redacted.', { feature: featureLabel })
	}

	return t('dossiq', '{feature} did not run: {reason}', { feature: featureLabel, reason: refusal.reason })
}
