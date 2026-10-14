// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The line after the crumbs on the task page (DqTaak): "bij zaak 2026-0074,
// deadline vandaag". It says which case the task is on and when it is due, in
// words, so a handler reads the urgency without doing the date sum.
//
// The day arithmetic is `daysUntilDeadline`, the one helper every surface asks
// (REQ-OTE-07), so this line and the deadline column never disagree about
// which day "today" is.

import { translate as t } from '@nextcloud/l10n'
import { daysUntilDeadline } from './deadlineCountdown.js'

/**
 * A task's deadline in words, relative to today.
 *
 * Today, tomorrow and yesterday are named; further away it counts the days.
 * Two days and more is always plural, so no plural form is needed.
 *
 * @param {string|number|Date|null|undefined} dueAt The task's due date.
 * @param {Date} [now] The moment to count from (defaults to the current one).
 * @return {string} The words, or '' when there is no readable due date.
 * @spec openspec/specs/task-management/spec.md
 */
export function taskDeadlineWords(dueAt, now = new Date()) {
	const days = daysUntilDeadline(dueAt, now)
	if (days === null) {
		return ''
	}

	if (days === 0) {
		return t('dossiq', 'deadline today')
	}
	if (days === 1) {
		return t('dossiq', 'deadline tomorrow')
	}
	if (days === -1) {
		return t('dossiq', 'deadline yesterday')
	}
	if (days > 1) {
		return t('dossiq', 'deadline in {days} days', { days })
	}
	return t('dossiq', 'deadline {days} days ago', { days: Math.abs(days) })
}

/**
 * The whole line: the case number and the deadline, the parts that are known.
 *
 * A finished task draws no deadline part. Its deadline no longer asks
 * anything of the reader, and "deadline 60 days ago" on a task that was done
 * on time reads as a task that was late.
 *
 * @param {object} options The parts.
 * @param {string} [options.caseNumber] The case's number (its `identifier`).
 * @param {string} [options.dueAt] The task's due date.
 * @param {boolean} [options.finished] Whether the task is finished.
 * @param {Date} [options.now] The moment to count from.
 * @return {string} The line, or '' when no part is known.
 * @spec openspec/specs/task-management/spec.md
 */
export function taskMetaLine({
	caseNumber = '',
	dueAt = null,
	finished = false,
	now = new Date(),
} = {}) {
	const parts = []
	const number = String(caseNumber ?? '').trim()
	if (number !== '') {
		parts.push(t('dossiq', 'on case {number}', { number }))
	}
	if (finished !== true) {
		const deadline = taskDeadlineWords(dueAt, now)
		if (deadline !== '') {
			parts.push(deadline)
		}
	}
	return parts.join(', ')
}
