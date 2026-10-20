/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The report group a case was placed in on creation
 * (ai-features-on-the-case-consume-hermiq, REQ-AIC-04). The AI features
 * themselves are the library's `ai-features` sidebar widget.
 *
 * 🔑 "COULD NOT READ" IS NOT "NOTHING THERE". The read answers null when it
 * failed, so the widget says the group could not be read.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

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
