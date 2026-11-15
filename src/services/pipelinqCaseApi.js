/**
 * The case surfaces that read and act through pipelinq, over dossiq's
 * PipelinqCaseController, plus the pure helpers that turn its answers into
 * the sentences the boards DqZaakContactmomenten, DqZaakPartijen and
 * DqZaaktype show.
 *
 * WHY DOSSIQ ROUTES AND NOT PIPELINQ'S. The PHP consumers hold the rules: an
 * absent pipelinq answered apart from an empty one, an uncomputable figure
 * kept from becoming zero, the acceptance written as a patch, the shared
 * marker counting the cases a reader may not see. Calling pipelinq from the
 * browser would mean writing each rule twice.
 *
 * Every read returns the controller's answer as is, so a surface can tell
 * `available: false` (pipelinq is not here) from an empty list (pipelinq
 * answered nothing). A failed request throws; the caller says so in words.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-one-seam-names-pipelinq-and-an-absent-pipelinq-is-a-different-answer-from-an-empty-one-req-plq-01
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The base URL of one case's pipelinq routes, plus a path.
 *
 * @param {string} caseId The case uuid.
 * @param {string} path The path below the case's pipelinq routes.
 * @return {string} The URL.
 */
function caseUrl(caseId, path) {
	return generateUrl('/apps/dossiq/api/cases/{caseId}/pipelinq', { caseId }) + path
}

/**
 * Log a contact moment on this case; the answer carries pipelinq's refusal.
 *
 * @param {string} caseId The case uuid.
 * @param {object} moment The fields the handler filled in.
 * @return {Promise<{contactmoment: object, pipelinqRefusal: string, pipelinqIndicators: Array}>} The answer.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
 */
export async function logContactMoment(caseId, moment) {
	const { data } = await axios.post(caseUrl(caseId, '/contact-moments'), moment)
	return data
}

/**
 * File a contact moment on this case onto another case too.
 *
 * @param {string} caseId The case it is on.
 * @param {string} momentId The contact moment.
 * @param {string} targetCaseId The case to file it onto.
 * @return {Promise<object>} The answer.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-shows-every-contact-moment-it-is-a-member-of-and-says-when-one-is-shared-req-plq-03
 */
export async function fileContactMoment(caseId, momentId, targetCaseId) {
	const { data } = await axios.post(
		caseUrl(
			caseId,
			'/contact-moments/' + encodeURIComponent(momentId) + '/file',
		),
		{ targetCaseId },
	)
	return data
}

/**
 * Put this case under a programme.
 *
 * @param {string} caseId The case uuid.
 * @param {string} programmeId The programme.
 * @param {string} title The case title as shown.
 * @return {Promise<object>} The answer.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
 */
export async function linkCaseProgramme(caseId, programmeId, title = '') {
	const { data } = await axios.post(caseUrl(caseId, '/programme'), {
		programmeId,
		title,
	})
	return data
}

/**
 * The programmes a case may be put under.
 *
 * @return {Promise<{available: boolean, programmes: Array}>} The answer.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
 */
export async function fetchProgrammes() {
	const { data } = await axios.get(
		generateUrl('/apps/dossiq/api/pipelinq/programmes'),
	)
	return data
}

/**
 * The kind vocabulary and one case type's declaration, for the editor.
 *
 * @param {string} caseTypeId The case type uuid.
 * @return {Promise<{source: string, kinds: Array, accepted: Array|null, available: boolean}>} The answer.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
 */
export async function fetchCaseTypePartyKinds(caseTypeId) {
	const { data } = await axios.get(
		generateUrl(
			'/apps/dossiq/api/case-types/{caseTypeId}/pipelinq/party-kinds',
			{ caseTypeId },
		),
	)
	return data
}

/**
 * Declare which kinds a case type accepts, in order.
 *
 * @param {string} caseTypeId The case type uuid.
 * @param {Array<string>} kinds The accepted kind codes, in order.
 * @return {Promise<object>} The answer.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
 */
export async function declareCaseTypePartyKinds(caseTypeId, kinds) {
	const { data } = await axios.put(
		generateUrl(
			'/apps/dossiq/api/case-types/{caseTypeId}/pipelinq/party-kinds',
			{ caseTypeId },
		),
		{ kinds },
	)
	return data
}

/**
 * The refusal sentence of a failed request: the server's own when it sent one.
 *
 * @param {Error} error The axios error.
 * @param {string} fallback The sentence when the server said nothing.
 * @return {string} The sentence.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
 */
export function refusalOf(error, fallback) {
	const said = error?.response?.data?.error
	return typeof said === 'string' && said !== '' ? said : fallback
}

/**
 * The code a kind is known by, whichever vocabulary answered.
 *
 * pipelinq's kinds carry `code`; dossiq's own three carry `key`.
 *
 * @param {object} kind The kind.
 * @return {string} The code.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
 */
export function kindCode(kind) {
	return String(kind?.code || kind?.key || '')
}

/**
 * The sentence for pipelinq's refusal, with the indicator named.
 *
 * @param {string} refusal The reason pipelinq gave.
 * @param {Array<object>} indicators The indicators pipelinq named.
 * @return {string} The sentence, '' when pipelinq did not refuse.
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-contact-moment-logged-on-a-case-is-appended-to-pipelinqs-record-req-plq-02
 */
export function refusalSentence(refusal, indicators) {
	if (!refusal) {
		return ''
	}

	const named = (Array.isArray(indicators) ? indicators : [])
		.map((indicator) => String(indicator?.label || indicator?.code || ''))
		.filter(Boolean)

	if (named.length === 0) {
		return t('dossiq', '{reason} The contact moment is kept in dossiq.', {
			reason: refusal,
		})
	}

	return t(
		'dossiq',
		'Indicator {indicators}: {reason} The contact moment is kept in dossiq.',
		{
			indicators: named.join(', '),
			reason: refusal,
		},
	)
}
