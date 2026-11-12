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
 * The hour and minute of a moment, on a 24-hour clock.
 *
 * @type {object}
 */
const TIME_OPTIONS = { hour: '2-digit', minute: '2-digit', hour12: false }

/**
 * The day options for a start: day and month, the year added outside the
 * year of the reference moment.
 *
 * @param {Date} start The start of the conversation.
 * @param {Date} [now] The reference moment (defaults to the current one).
 * @return {object} Intl.DateTimeFormat options.
 */
function dayOptionsFor(start, now) {
	const reference = now || new Date()
	const dayOptions = { day: 'numeric', month: 'short' }
	if (start.getFullYear() !== reference.getFullYear()) {
		dayOptions.year = 'numeric'
	}
	return dayOptions
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
	const locale = options.locale || userLanguage()
	const date = format(locale, dayOptionsFor(start, options.now), start).replace(/\.$/, '')
	const from = format(locale, TIME_OPTIONS, start)
	const end = endOf(record)
	if (end === null) {
		return `${date}, ${from}`
	}
	return t('dossiq', '{date}, {start} to {end}', { date, start: from, end: format(locale, TIME_OPTIONS, end) })
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
	const parts = [
		conversationWhen(record, options),
		participantList(record, options),
		durationPart(record),
	]
	return parts.filter((part) => part !== '').join(' · ')
}

/**
 * The people who joined, as a list in the locale ("A en B").
 *
 * @param {object} record The conversation record.
 * @param {object} options Options, as for conversationWhen.
 * @return {string} The list, or '' without named people.
 */
function participantList(record, options) {
	const people = participantNames(record)
	if (people.length === 0) {
		return ''
	}
	return formatList(people, options.locale || userLanguage())
}

/**
 * @param {object} record The conversation record.
 * @return {string[]} The non-empty names among its participants.
 */
function participantNames(record) {
	if (!Array.isArray(record?.participants)) {
		return []
	}
	return record.participants.filter((name) => typeof name === 'string' && name !== '')
}

/**
 * @param {string[]} people The names.
 * @param {string|undefined} locale A BCP 47 tag.
 * @return {string} The names as a conjunction list, or comma-joined when the locale cannot format one.
 */
function formatList(people, locale) {
	try {
		return new Intl.ListFormat(locale, { type: 'conjunction' }).format(people)
	} catch {
		return people.join(', ')
	}
}

/**
 * How long it lasted, in whole minutes and at least one.
 *
 * @param {object} record The conversation record.
 * @return {string} The minutes, or '' without a positive duration.
 */
function durationPart(record) {
	const seconds = Number(record?.durationSeconds)
	if (!Number.isFinite(seconds) || seconds <= 0) {
		return ''
	}
	const minutes = Math.max(1, Math.round(seconds / 60))
	return n('dossiq', '%n minute', '%n minutes', minutes)
}
