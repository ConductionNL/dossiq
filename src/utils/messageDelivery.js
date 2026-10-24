/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * When and how a message template goes out: the case events a template can
 * be sent on (`moment`) and the ways it reaches the applicant (`channel`),
 * as the emailTemplate schema enumerates them, with their English labels.
 */

/** @type {Array<{value: string, label: string}>} */
export const MESSAGE_MOMENTS = [
	{ value: 'case-received', label: 'Case received' },
	{ value: 'status-changed', label: 'Status changed' },
	{ value: 'information-requested', label: 'Additional information needed' },
	{ value: 'term-extended', label: 'Term extended' },
	{ value: 'decision-made', label: 'Decision made' },
	{ value: 'case-closed', label: 'Case closed' },
]

/** @type {Array<{value: string, label: string}>} */
export const MESSAGE_CHANNELS = [
	{ value: 'mailbox-then-email', label: 'Message box, otherwise e-mail' },
	{ value: 'mailbox', label: 'Message box' },
	{ value: 'email', label: 'E-mail' },
	{ value: 'portal', label: 'Portal' },
	{ value: 'post', label: 'Post (mail)' },
]

/**
 * The options as a `{ value: label }` map, the shape a record list's
 * `valueLabels` takes.
 *
 * @param {Array<{value: string, label: string}>} options The options.
 * @return {Object<string, string>} The map.
 */
export function labelMap(options) {
	return Object.fromEntries(options.map((option) => [option.value, option.label]))
}
