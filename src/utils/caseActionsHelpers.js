/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reasoning behind the case page's Actions menu — Copy case, Start and
 * Plan follow-up — kept out of the components so it can be tested without a
 * DOM.
 *
 * Four questions live here:
 *
 *  - what a copy is called before anybody types anything;
 *  - what a refusal from the server means in words. The endpoints answer with
 *    a short static code on purpose (a message a server writes is a message no
 *    translator ever sees), so turning the code into a sentence is the
 *    client's job;
 *  - whether a plan is complete enough to send, which decides whether the
 *    confirm button is enabled rather than whether the POST fails;
 *  - what the earliest date a follow-up may be planned for is, because a
 *    follow-up in the past is a case that appears immediately and reads as a
 *    bug.
 *
 * @spec openspec/specs/case-management/spec.md
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

/**
 * The title a copy is proposed under.
 *
 * The prefix is applied ONCE: copying a copy proposes the same name back
 * rather than growing "Copy of Copy of Copy of", which is how a handler ends
 * up with three cases whose titles differ only in a prefix nobody reads.
 *
 * @param {string} title The source case's title.
 * @return {string} The proposed title.
 * @spec openspec/specs/case-management/spec.md
 */
export function proposedCopyTitle(title) {
	const source = String(title ?? '').trim()
	if (source === '') {
		return 'Copy'
	}
	if (source.startsWith('Copy of ')) {
		return source
	}
	return `Copy of ${source}`
}

/**
 * Turn a refusal from the case-actions endpoints into a sentence.
 *
 * Unknown codes fall through to a generic line rather than being printed
 * raw: a code is an identifier, and showing one to a handler tells them
 * nothing they can act on.
 *
 * @param {object} error The response body, `{code}`.
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {string} The message.
 * @spec openspec/specs/case-management/spec.md
 */
export function caseActionRefusal(error, translate) {
	const code = String(error?.code ?? '')
	switch (code) {
		case 'case_not_found':
			return translate('This case could not be read.')
		case 'storage_unavailable':
		case 'flows_unavailable':
			return translate('The case store is not available right now.')
		case 'invalid_date':
			return translate('Pick a date for the follow-up.')
		case 'copy_failed':
			return translate('The copy could not be created.')
		case 'plan_failed':
			return translate('The follow-up could not be planned.')
		default:
			return translate('This did not work. Try again.')
	}
}

/**
 * The earliest date a follow-up may be planned for: tomorrow.
 *
 * Today is excluded deliberately. A schedule trigger fires on a cron minute,
 * and a follow-up planned for today would fire either in a few hours or not
 * until next year depending on the clock — two behaviours from one gesture.
 *
 * @param {Date} [now] The current moment, injectable for tests.
 * @return {string} The date as `YYYY-MM-DD`.
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
export function earliestFollowUpDate(now = new Date()) {
	const next = new Date(now.getTime())
	next.setDate(next.getDate() + 1)
	return next.toISOString().slice(0, 10)
}

/**
 * Whether a planned follow-up is complete enough to send.
 *
 * @param {object} plan The form state, `{caseType, date, title}`.
 * @param {string} earliest The earliest allowed date, as `YYYY-MM-DD`.
 * @return {boolean} True when every field is present and the date is not past.
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
export function isPlanComplete(plan, earliest) {
	const caseType = String(plan?.caseType ?? '').trim()
	const date = String(plan?.date ?? '').trim()
	const title = String(plan?.title ?? '').trim()
	if (caseType === '' || date === '' || title === '') {
		return false
	}
	return date >= String(earliest ?? '')
}

/**
 * The rows the Related cases tab shows for the follow-ups still to come.
 *
 * The label carries the date, because "Controle" on its own says nothing
 * about when it will exist and that is the only thing a planned row adds
 * over an ordinary related case.
 *
 * @param {Array} rows The `planned` endpoint's results.
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {Array<{key: string, label: string, date: string}>} The rows.
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
export function plannedRows(rows, translate) {
	if (Array.isArray(rows) === false) {
		return []
	}
	return rows
		.filter((row) => row && String(row.id ?? '') !== '')
		.map((row) => {
			const title = String(row.title ?? '').trim()
			const date = String(row.date ?? '').trim()
			return {
				key: String(row.id),
				date,
				label:
					date === ''
						? title
						: `${title}, ${translate('planned for {date}').replace('{date}', date)}`,
			}
		})
}
