/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reads behind the AI features panel on a case
 * (ai-features-on-the-case-consume-hermiq, REQ-AIC-01, REQ-AIC-02, REQ-AIC-04).
 *
 * 🔴 AN UNDECLARED CASE TYPE ASKS NOBODY. The case type's own `aiFeatures`
 * declaration is read first, from OpenRegister, and dossiq's feature endpoint
 * (which asks hermiq) is called only when something is placed on the case
 * surface. The endpoint refuses to ask hermiq for an empty declaration as
 * well; this keeps the browser from even making the request.
 *
 * 🔑 "COULD NOT READ" IS NOT "NOTHING THERE". Every read answers null when it
 * failed, so the panel can say it is incomplete instead of rendering an empty
 * list that reads as "this case has no AI features".
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The surface this panel renders.
 *
 * @type {string}
 */
export const CASE_SURFACE = 'case'

/**
 * The id inside a reference that may be a plain id or an object carrying one.
 *
 * @param {string|object|null|undefined} value The reference.
 * @return {string} The id, or the empty string.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01
 */
export function referenceId(value) {
	if (value !== null && typeof value === 'object') {
		return String(value.id ?? value.uuid ?? '').trim()
	}

	return String(value ?? '').trim()
}

/**
 * The slugs a case type places on the case surface.
 *
 * @param {object} caseType The case type record.
 * @return {Array<string>} The slugs, in declaration order.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-a-feature-declared-for-intake-does-not-appear-on-the-case
 */
export function caseSurfaceSlugs(caseType) {
	const declared = caseType?.aiFeatures
	if (
		declared === null
		|| typeof declared !== 'object'
		|| Array.isArray(declared)
	) {
		return []
	}

	return Object.keys(declared).filter((slug) => declared[slug] === CASE_SURFACE)
}

/**
 * Read a record from dossiq's register as the signed-in person.
 *
 * @param {string} schema The schema slug.
 * @param {string} id The object id.
 * @return {Promise<object>} The record.
 */
async function readObject(schema, id) {
	const { data } = await axios.get(
		generateUrl(
			`/apps/openregister/api/objects/dossiq/${schema}/${encodeURIComponent(id)}`,
		),
	)
	return data?.object ?? data ?? {}
}

/**
 * The AI features this case's type places on the case, each with where it runs.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<Array<object>|null>} The features, or null when they could not be read.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-an-undeclared-feature-makes-no-call
 */
export async function fetchCaseFeatures(caseId) {
	try {
		const caseRecord = await readObject('case', caseId)
		const caseTypeId = referenceId(caseRecord.caseType)
		if (caseTypeId === '') {
			return []
		}

		const caseType = await readObject('caseType', caseTypeId)
		const slugs = caseSurfaceSlugs(caseType)
		if (slugs.length === 0) {
			return []
		}

		const query = new URLSearchParams({ surface: CASE_SURFACE })
		for (const slug of slugs) {
			query.append(`caseType[aiFeatures][${slug}]`, CASE_SURFACE)
		}

		const { data } = await axios.get(
			`${generateUrl('/apps/dossiq/api/assistant/ai-features')}?${query.toString()}`,
		)
		return Array.isArray(data?.features) ? data.features : []
	} catch {
		return null
	}
}

/**
 * The report group this case is in, as it now stands. A read only: it never
 * places the case in a group.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object|null>} dossiq's answer, or null when it could not be read.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
 */
export async function fetchReportGroup(caseId) {
	try {
		const { data } = await axios.get(
			generateUrl('/apps/dossiq/api/report-group'),
			{
				params: { caseId },
			},
		)
		return data ?? null
	} catch {
		return null
	}
}

/**
 * The documents on this case a feature can be run on.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<Array<{id: string, title: string}>|null>} The documents, or null when they could not be read.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-reference-travels-with-the-request
 */
export async function fetchCaseDocuments(caseId) {
	try {
		const { data } = await axios.get(
			generateUrl(
				`/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(caseId)}/files`,
			),
		)
		const rows = Array.isArray(data?.results)
			? data.results
			: Array.isArray(data)
				? data
				: []
		return rows
			.filter((row) => row && row.id !== undefined && row.id !== null)
			.map((row) => ({
				id: String(row.id),
				title: String(row.title ?? row.name ?? row.id),
			}))
	} catch {
		return null
	}
}

/**
 * Where a feature runs, in words. A feature hermiq does not offer reads
 * unavailable, never as local.
 *
 * @param {object} feature One row of the feature endpoint.
 * @return {string} The sentence.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-without-hermiq-the-features-read-unavailable-rather-than-local
 */
export function whereItRuns(feature) {
	if (feature?.available !== true) {
		return t('dossiq', 'Unavailable: hermiq does not offer this feature here.')
	}

	const provider = String(feature.provider ?? '').trim()
	const residency = String(feature.residency ?? '').trim()
	if (provider === '' || residency === '') {
		return t('dossiq', 'hermiq did not say which provider runs this, or where.')
	}

	return t('dossiq', '{provider}, runs in {residency}', { provider, residency })
}

/**
 * What the panel shows for the report group.
 *
 * `off` renders nothing (the case type does not group reports), `unavailable`
 * names the reason and hermiq's gate, `grouped` carries the count and the
 * near-duplicates beside it.
 *
 * @param {object|null} answer The report-group answer, or null when it could not be read.
 * @return {{state: string, count: number, nearDuplicates: Array<object>, reason: string, gate: (string|null)}} The view.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-two-hundred-reports-read-as-one-item-with-a-count
 */
export function groupView(answer) {
	const view = {
		state: 'off',
		count: 0,
		nearDuplicates: [],
		reason: '',
		gate: null,
	}
	if (answer === null || answer === undefined) {
		return {
			...view,
			state: 'unavailable',
			reason: t('dossiq', 'The report group could not be read.'),
		}
	}

	if (answer.declared !== true) {
		return view
	}

	if (
		answer.available !== true
		|| answer.group === null
		|| typeof answer.group !== 'object'
	) {
		return {
			...view,
			state: 'unavailable',
			reason:
				String(answer.reason ?? '') || t('dossiq', 'hermiq did not answer.'),
			gate: answer.gate ?? null,
		}
	}

	const nearDuplicates = (
		Array.isArray(answer.group.nearDuplicates) ? answer.group.nearDuplicates : []
	)
		.filter((member) => member && String(member.reportId ?? '') !== '')
		.map((member) => ({
			reportId: String(member.reportId),
			score: Number(member.score ?? 0),
		}))

	return {
		...view,
		state: 'grouped',
		count: Number(answer.group.count ?? 0),
		nearDuplicates,
	}
}

/**
 * The prompts the assistant offers on a case, as hermiq holds them, in the
 * administrator's order. dossiq keeps no prompt text of its own.
 *
 * @return {Promise<Array<{id: string, label: string, prompt: string}>|null>} The prompts, or null when they could not be read.
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-prompts-offered-on-a-case-come-from-hermiq
 */
export async function fetchCasePrompts() {
	try {
		const { data } = await axios.get(
			generateUrl('/apps/dossiq/api/assistant/prompts'),
		)
		return Array.isArray(data?.prompts) ? data.prompts : []
	} catch {
		return null
	}
}
