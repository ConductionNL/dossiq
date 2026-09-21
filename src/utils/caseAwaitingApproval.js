/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the case is waiting for, and on whom.
 *
 * decidiq walks the approval. The case reads the outcome and is gated by it,
 * and the one thing a handler wants on opening the case is not "this button is
 * disabled" but "who do I chase". So the `/acts` answer carries an
 * `awaitingApproval` list and this shapes it for the page.
 *
 * 🔴 IT DERIVES NO VERDICT. Every sentence here was authored by the server,
 * which read decidiq. A second derivation in the browser would eventually
 * disagree with the refusal the write path gives, and the handler would meet
 * that disagreement as a button that fails after they press it.
 *
 * 🔑 NOBODY NAMED IS NOT NOBODY WAITING. dossiq stores no approver, so an
 * empty list means decidiq named none, and the row says so. Rendering it as an
 * empty name list would read as an approval waiting on nobody, which is the
 * one thing it never means.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

/**
 * The approvals this case is waiting on, normalised for rendering.
 *
 * A row with no act names nothing a surface can point at and is dropped: an
 * unlabelled "something is blocked" line sends a handler looking rather than
 * telling them where to look.
 *
 * @param {object|null} acts The `/acts` answer.
 * @return {Array<{act: string, label: string, sentence: string, approvers: Array<string>, decisionRef: string}>}
 *   The outstanding approvals, in the order the server listed them.
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */
export function awaitingApprovals(acts) {
	const rows = Array.isArray(acts?.awaitingApproval) ? acts.awaitingApproval : []

	return rows
		.filter((row) => row && typeof row === 'object')
		.map((row) => ({
			act: String(row.act ?? '').trim(),
			label: String(row.label ?? '').trim(),
			sentence: String(row.sentence ?? '').trim(),
			approvers: Array.isArray(row.approvers)
				? row.approvers
						.map((name) => String(name ?? '').trim())
						.filter(Boolean)
				: [],
			decisionRef: String(row.decisionRef ?? '').trim(),
		}))
		.filter((row) => row.act !== '')
}

/**
 * Whether this case is waiting on an approval at all.
 *
 * @param {object|null} acts The `/acts` answer.
 * @return {boolean} True when at least one approval is outstanding.
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */
export function isAwaitingApproval(acts) {
	return awaitingApprovals(acts).length > 0
}

/**
 * The people one outstanding approval waits on, as one line.
 *
 * @param {object} entry One row of {@link awaitingApprovals}.
 * @return {string} The names joined, or '' when decidiq named none.
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */
export function approversLine(entry) {
	const names = Array.isArray(entry?.approvers) ? entry.approvers : []

	return names.filter(Boolean).join(', ')
}

/**
 * The name to show for one outstanding approval.
 *
 * Falls back to the act rather than to an empty heading: a row headed by
 * nothing reads as a rendering fault, and the act at least names the thing
 * that is blocked.
 *
 * @param {object} entry One row of {@link awaitingApprovals}.
 * @return {string} The heading.
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */
export function approvalHeading(entry) {
	const label = String(entry?.label ?? '').trim()

	return label !== '' ? label : String(entry?.act ?? '').trim()
}
