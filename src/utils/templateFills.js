/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { getCanonicalLocale, translatePlural as n, translate as t } from '@nextcloud/l10n'
import { formatDuration, parseDuration } from './durationHelpers.js'

/**
 * A date written out in full ("1 november 2026").
 *
 * @param {Date} date The date.
 * @param {string} locale The locale.
 * @return {string} The date.
 */
function longDate(date, locale) {
	return date.toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
}

/**
 * Parse an ISO date (YYYY-MM-DD, a time is ignored) as a UTC day.
 *
 * @param {string} value The stored date.
 * @return {Date|null} The day, or null.
 */
function dayOf(value) {
	const match = typeof value === 'string' ? value.match(/^(\d{4})-(\d{2})-(\d{2})/) : null
	return match ? new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))) : null
}

/**
 * A period in words, a whole number of weeks as weeks ("P14D" reads "2 weeks").
 *
 * @param {string} iso The ISO 8601 period.
 * @return {string} The period.
 */
function periodWords(iso) {
	const parts = parseDuration(iso)
	if (parts && parts.years === 0 && parts.months === 0 && parts.weeks === 0 && parts.days > 0 && parts.days % 7 === 0) {
		return formatDuration(`P${parts.days / 7}W`)
	}
	return formatDuration(iso)
}

/**
 * The day a period that starts on `from` ends.
 *
 * @param {Date} from The start day.
 * @param {string} iso The ISO 8601 period.
 * @return {Date|null} The end day.
 */
function endOf(from, iso) {
	const parts = parseDuration(iso)
	if (!parts) {
		return null
	}
	const end = new Date(from.getTime())
	end.setUTCFullYear(end.getUTCFullYear() + parts.years, end.getUTCMonth() + parts.months, end.getUTCDate() + parts.weeks * 7 + parts.days)
	return end
}

/**
 * The day after `day`.
 *
 * @param {Date} day The day.
 * @return {Date} The next day.
 */
function dayAfter(day) {
	return new Date(day.getTime() + 86400000)
}

/**
 * What a deadline fill says after its date: the statutory term in calendar
 * days and the first day the case is late ("28 calendar days, late from
 * 2 november"). Without a term in whole days only the date is shown.
 *
 * @param {object} fill One fill, `term` naming the case's period field.
 * @param {{kase: object, locale: string}} run The case and the locale for dates.
 * @return {string} The note, or empty.
 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
 */
function deadlineNote(fill, run) {
	const day = dayOf(run.kase[fill.field])
	const parts = parseDuration(run.kase[fill.term])
	if (!day || !parts || parts.years !== 0 || parts.months !== 0) {
		return ''
	}
	const days = parts.weeks * 7 + parts.days
	const late = dayAfter(day).toLocaleDateString(run.locale, { day: 'numeric', month: 'long', timeZone: 'UTC' })
	return n('dossiq', '{days} calendar day, late from {date}', '{days} calendar days, late from {date}', days, { days, date: late })
}

/**
 * The value of a date or deadline fill: the date written out in full.
 *
 * @param {object} fill One fill.
 * @param {{kase: object, locale: string}} run The case and the locale for dates.
 * @return {string|null} The value, or null for a value that is no date.
 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
 */
function dateValue(fill, run) {
	const day = dayOf(run.kase[fill.field])
	return day ? longDate(day, run.locale) : null
}

/**
 * The value of an extension fill: the period and the day it ends, or for
 * `after-extension` the day after that end.
 *
 * @param {object} fill One fill, `field` naming the ISO 8601 period and `from` the date it counts from.
 * @param {{kase: object, locale: string}} run The case and the locale for dates.
 * @return {string|null} The value.
 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
 */
function extensionValue(fill, run) {
	const period = run.kase[fill.field]
	const from = dayOf(run.kase[fill.from])
	const end = from ? endOf(from, period) : null
	if (fill.as === 'after-extension') {
		return end ? longDate(dayAfter(end), run.locale) : null
	}
	return end
		? t('dossiq', '{period}, until {date}', { period: periodWords(period), date: longDate(end, run.locale) })
		: periodWords(period)
}

// How a fill's value is read, per `as`; any other reads as plain text. A Map,
// so a name such as `toString` is not found on an object's prototype.
const VALUE_READERS = new Map([
	['date', dateValue],
	['deadline', dateValue],
	['extension', extensionValue],
	['after-extension', extensionValue],
])

/**
 * Whether a case holds no value: absent, null or empty.
 *
 * @param {unknown} raw The stored value.
 * @return {boolean} True for no value.
 */
function isBlank(raw) {
	return raw === undefined || raw === null || raw === ''
}

/**
 * The value one fill reads off the case, or null when the case lacks it.
 *
 * @param {object} fill One fill.
 * @param {{kase: object, locale: string}} run The case and the locale for dates.
 * @return {string|null} The value.
 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
 */
function fillValue(fill, run) {
	const raw = fill && fill.field ? run.kase[fill.field] : undefined
	if (isBlank(raw)) {
		return null
	}
	const read = VALUE_READERS.get(fill.as)
	return read ? read(fill, run) : String(raw)
}

/**
 * Whether a fill states a value of its own instead of reading the case.
 *
 * @param {object} fill One fill.
 * @return {boolean} True for a fixed value.
 */
function hasFixedValue(fill) {
	return Boolean(fill && typeof fill.value === 'string' && fill.value !== '')
}

/**
 * The row of a required fill: its value, or marked still to fill in.
 *
 * @param {object} fill One fill.
 * @param {string|null} value Its value off the case.
 * @return {object} The row.
 */
function requiredRow(fill, value) {
	return value === null
		? { label: fill.label, value: t('dossiq', 'Still to fill in, not in the case'), required: true, missing: true }
		: { label: fill.label, value, required: true, missing: false }
}

/**
 * The note a fill adds after its value: a deadline's term, else nothing.
 *
 * @param {object} fill One fill.
 * @param {{kase: object, locale: string}} run The case and the locale for dates.
 * @return {string} The note, or empty.
 */
function noteFor(fill, run) {
	return fill?.as === 'deadline' ? deadlineNote(fill, run) : ''
}

/**
 * One row of the card, or null for a fill that stays out of it.
 *
 * @param {object} fill One fill.
 * @param {{kase: object, locale: string}} run The case and the locale for dates.
 * @return {object|null} The row.
 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
 */
function fillRow(fill, run) {
	if (hasFixedValue(fill)) {
		return { label: fill.label, value: fill.value, fixed: true }
	}
	const value = fillValue(fill, run)
	if (fill?.required === true) {
		return requiredRow(fill, value)
	}
	if (value === null) {
		return null
	}
	const note = noteFor(fill, run)
	return note === '' ? { label: fill.label, value } : { label: fill.label, value, note }
}

/**
 * The rows "What the template fills in" shows for a template, read off the
 * case: `{ label, field, as }` where `as` is `date` (written out in full),
 * `deadline` (a date, with a note from the `term` period), `extension` (the
 * period, and the day it ends counted from the `from` date), `after-extension`
 * (the day after that end) or plain text. A fill whose field the case lacks is left out, unless it is
 * `required`: then it stays, marked missing, so the handler sees what to add
 * (board DqDocumentGenereren). A fill with a `value` of its own states a fact
 * of the template ("Status after generating"). Labels and fixed values stay
 * untranslated for the caller to translate.
 *
 * @param {Array<object>|undefined} fills The template's fills.
 * @param {object} kase The case.
 * @param {string} [locale] The locale for dates.
 * @return {Array<{label: string, value: string, note?: string, required?: boolean, missing?: boolean, fixed?: boolean}>} The rows.
 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
 */
export function templateFillRows(fills, kase, locale = getCanonicalLocale()) {
	if (!Array.isArray(fills) || !kase) {
		return []
	}
	const run = { kase, locale }
	return fills.map((fill) => fillRow(fill, run)).filter(Boolean)
}
