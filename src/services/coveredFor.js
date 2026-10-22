// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The line a covered case carries on Assigned to me: whom the reader covers
 * it for, and until when ("Voor Anouk Bakker, tot 10 okt", board
 * DqAanMijToegewezen). The work queue puts `coveredFor: { absentee, name,
 * until }` on every case it adds for a substitution, `null` on the reader's
 * own.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
import { getLanguage, translate as t } from '@nextcloud/l10n'

/**
 * The covered-for line of one case.
 *
 * @param {object|null} value The case's `coveredFor`.
 * @param {string} [locale] The locale the day is written in; the user's by default.
 * @return {string} The line, or '' for the reader's own case.
 */
export function coveredForLabel(value, locale = getLanguage()) {
	const name = value && typeof value.name === 'string' ? value.name.trim() : ''
	if (name === '') {
		return ''
	}
	const until = typeof value.until === 'string' ? value.until : ''
	const day = /^\d{4}-\d{2}-\d{2}/.test(until) ? new Date(`${until.slice(0, 10)}T12:00:00`) : null
	if (!day || Number.isNaN(day.getTime())) {
		return t('dossiq', 'For {name}', { name })
	}
	const date = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(day).replace(/\.$/, '')
	return t('dossiq', 'For {name}, until {date}', { name, date })
}
