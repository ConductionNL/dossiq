// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The line under a conversation held from a case: when it was held, who
 * joined and how long it lasted ("4 okt, 10:30 tot 10:42 · Pieter Jansen en
 * Sanne de Vries · 12 minuten").
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { getLanguage, translatePlural as n, translate as t } from '@nextcloud/l10n'

/**
 * @param {unknown} value An ISO string or a Date.
 * @return {Date|null} The moment, or null when it is not one.
 * @spec openspec/specs/case-management/spec.md
 */
function toDate(value) {
	if (!value) {
		return null
	}
	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? null : date
}

/**
 * @param {string|undefined} locale A BCP 47 tag.
 * @param {object} options Intl.DateTimeFormat options.
 * @param {Date} date The moment.
 * @return {string} The formatted part.
 * @spec openspec/specs/case-management/spec.md
 */
function format(locale, options, date) {
	try {
		return new Intl.DateTimeFormat(locale, options).format(date)
	} catch {
		return new Intl.DateTimeFormat(undefined, options).format(date)
	}
}

/**
 * @return {string|undefined} The user's language as a BCP 47 tag.
 * @spec openspec/specs/case-management/spec.md
 */
function userLanguage() {
	try {
		return (getLanguage() || '').replace(/_/g, '-') || undefined
	} catch {
		return undefined
	}
}

/**
 * When the conversation ended: its recorded end, else its start plus its duration.
 *
 * @param {object} record The conversation record.
 * @return {Date|null} The end, or null while it runs.
 * @spec openspec/specs/case-management/spec.md
 */
function endOf(record) {
	const ended = toDate(record?.endedAt)
	if (ended !== null) {
		return ended
	}
	const started = toDate(record?.startedAt)
	const seconds = Number(record?.durationSeconds)
	if (started !== null && Number.isFinite(seconds) && seconds > 0) {
		return new Date(started.getTime() + seconds * 1000)
	}
	return null
}

/**
 * Whether the conversation is over: it has an end or a duration.
 *
 * @param {object} record The conversation record.
 * @return {boolean} True once it ended.
 * @spec openspec/specs/case-management/spec.md
 */
export function conversationEnded(record) {
	return toDate(record?.endedAt) !== null || Number(record?.durationSeconds) > 0
}

/**
 * The day and the times: "4 okt, 10:30 tot 10:42", the year added outside
 * the current one, only the start while it runs.
 *
 * @param {object} record The conversation record.
 * @param {object} [options] Options.
 * @param {Date} [options.now] The reference moment.
 * @param {string} [options.locale] BCP 47 tag (defaults to the user's language).
 * @return {string} The moment, or '' without a start.
 * @spec openspec/specs/case-management/spec.md
 */
export function conversationWhen(record, options = {}) {
	const start = toDate(record?.startedAt)
	if (start === null) {
		return ''
	}
	const now = options.now || new Date()
	const locale = options.locale || userLanguage()
	const dayOptions = { day: 'numeric', month: 'short' }
	if (start.getFullYear() !== now.getFullYear()) {
		dayOptions.year = 'numeric'
	}
	const time = { hour: '2-digit', minute: '2-digit', hour12: false }
	const date = format(locale, dayOptions, start).replace(/\.$/, '')
	const from = format(locale, time, start)
	const end = endOf(record)
	if (end === null) {
		return `${date}, ${from}`
	}
	return t('dossiq', '{date}, {start} to {end}', { date, start: from, end: format(locale, time, end) })
}

/**
 * The whole line: the moment, the people as a list, and the minutes.
 *
 * @param {object} record The conversation record.
 * @param {object} [options] Options, as for conversationWhen.
 * @return {string} The line.
 * @spec openspec/specs/case-management/spec.md
 */
export function conversationSummary(record, options = {}) {
	const parts = []
	const when = conversationWhen(record, options)
	if (when !== '') {
		parts.push(when)
	}
	const people = Array.isArray(record?.participants) ? record.participants.filter((name) => typeof name === 'string' && name !== '') : []
	if (people.length > 0) {
		const locale = options.locale || userLanguage()
		let list
		try {
			list = new Intl.ListFormat(locale, { type: 'conjunction' }).format(people)
		} catch {
			list = people.join(', ')
		}
		parts.push(list)
	}
	const seconds = Number(record?.durationSeconds)
	if (Number.isFinite(seconds) && seconds > 0) {
		const minutes = Math.max(1, Math.round(seconds / 60))
		parts.push(n('dossiq', '%n minute', '%n minutes', minutes))
	}
	return parts.join(' · ')
}
