/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
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
 * The rows "What the template fills in" shows for a template, read off the
 * case: `{ label, field, as }` where `as` is `date` (written out in full),
 * `extension` (the period, and the day it ends counted from the `from` date)
 * or plain text. A fill whose field the case lacks is left out. Labels stay
 * untranslated for the caller to translate.
 *
 * @param {Array<object>|undefined} fills The template's fills.
 * @param {object} kase The case.
 * @param {string} [locale] The locale for dates.
 * @return {Array<{label: string, value: string}>} The rows.
 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
 */
export function templateFillRows(fills, kase, locale = getCanonicalLocale()) {
	if (!Array.isArray(fills) || !kase) {
		return []
	}
	return fills.map((fill) => {
		const raw = fill && fill.field ? kase[fill.field] : undefined
		if (raw === undefined || raw === null || raw === '') {
			return null
		}
		if (fill.as === 'date') {
			const day = dayOf(raw)
			return day ? { label: fill.label, value: longDate(day, locale) } : null
		}
		if (fill.as === 'extension') {
			const from = dayOf(kase[fill.from])
			const end = from ? endOf(from, raw) : null
			return {
				label: fill.label,
				value: end
					? t('dossiq', '{period}, until {date}', { period: periodWords(raw), date: longDate(end, locale) })
					: periodWords(raw),
			}
		}
		return { label: fill.label, value: String(raw) }
	}).filter(Boolean)
}
