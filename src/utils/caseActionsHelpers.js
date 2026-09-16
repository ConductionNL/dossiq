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
 *  - what a follow-up may repeat as, and when a repeating one stops;
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
		case 'invalid_recurrence':
			return translate('Pick how often the follow-up comes back.')
		case 'invalid_end':
			return translate('Give the series an end date or a number, not both.')
		case 'stop_failed':
			return translate('The series could not be stopped.')
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
 * The recurrences a follow-up may carry, in the order the form offers them.
 *
 * The ids match the server's tokens exactly. A recurrence the server does not
 * know is refused rather than treated as `none`, so a drifting list here is a
 * refusal a handler sees, not a series that quietly never repeats.
 *
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */
export function recurrenceOptions(translate) {
	return [
		{ id: 'none', label: translate('Once') },
		{ id: 'monthly', label: translate('Every month') },
		{ id: 'quarterly', label: translate('Every quarter') },
		{ id: 'halfYearly', label: translate('Every half year') },
		{ id: 'yearly', label: translate('Every year') },
	]
}

/**
 * How a series may end, in the order the form offers them.
 *
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */
export function endOptions(translate) {
	return [
		{ id: 'open', label: translate('Until you stop it') },
		{ id: 'until', label: translate('On a date') },
		{ id: 'count', label: translate('After a number of cases') },
	]
}

/**
 * A recurrence as the phrase a planned row reads it back as.
 *
 * @param {string} recurrence The recurrence token.
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {string} The phrase, empty for a follow-up that happens once.
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */
export function recurrenceLabel(recurrence, translate) {
	const token = String(recurrence ?? 'none')
	if (token === 'none') {
		return ''
	}
	const match = recurrenceOptions(translate).find((row) => row.id === token)
	return match ? match.label.toLowerCase() : ''
}

/**
 * Whether a planned follow-up is complete enough to send.
 *
 * The end is checked as well as the start. A series asked to end on a date
 * BEFORE its first occurrence has no occurrences at all, and a count of zero
 * is a series that never runs: both save without complaint and then do
 * nothing, which is the failure this change exists to end.
 *
 * @param {object} plan The form state, `{caseType, date, title, recurrence, end, until, count}`.
 * @param {string} earliest The earliest allowed date, as `YYYY-MM-DD`.
 * @return {boolean} True when every field is present and the dates agree.
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */
export function isPlanComplete(plan, earliest) {
	const caseType = String(plan?.caseType ?? '').trim()
	const date = String(plan?.date ?? '').trim()
	const title = String(plan?.title ?? '').trim()
	if (caseType === '' || date === '' || title === '') {
		return false
	}
	if (date < String(earliest ?? '')) {
		return false
	}

	const recurrence = String(plan?.recurrence ?? 'none')
	if (recurrence === 'none') {
		return true
	}

	const end = String(plan?.end ?? 'open')
	if (end === 'until') {
		const until = String(plan?.until ?? '').trim()
		return until !== '' && until >= date
	}
	if (end === 'count') {
		return Number(plan?.count ?? 0) >= 1
	}
	return true
}

/**
 * The rows the Related cases tab shows for the follow-ups still to come.
 *
 * The label carries the date, because "Controle" on its own says nothing
 * about when it will exist and that is the only thing a planned row adds
 * over an ordinary related case. A SERIES adds one more thing: how often it
 * comes back, and how many cases it has already opened. Both go in the label,
 * so a handler reads a series without opening anything.
 *
 * @param {Array} rows The `planned` endpoint's results.
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {Array<{key: string, label: string, date: string, recurrence: string, occurrences: Array}>} The rows.
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
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
			const recurrence = String(row.recurrence ?? 'none')
			const occurrences = Array.isArray(row.occurrences) ? row.occurrences : []
			return {
				key: String(row.id),
				date,
				recurrence,
				occurrences,
				label: plannedLabel(title, date, recurrence, occurrences, translate),
			}
		})
}

/**
 * The one line a planned row reads as.
 *
 * @param {string} title The planned case's title.
 * @param {string} date The next occurrence, as `YYYY-MM-DD`.
 * @param {string} recurrence The recurrence token.
 * @param {Array} occurrences The cases the series has already opened.
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {string} The label.
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */
function plannedLabel(title, date, recurrence, occurrences, translate) {
	const parts = [title]
	const repeat = recurrenceLabel(recurrence, translate)
	if (repeat !== '') {
		parts.push(repeat)
	}
	if (date !== '') {
		parts.push(translate('next on {date}').replace('{date}', date))
	}
	if (occurrences.length > 0) {
		parts.push(
			translate('{count} opened so far').replace(
				'{count}',
				String(occurrences.length),
			),
		)
	}
	return parts.filter((part) => part !== '').join(', ')
}
