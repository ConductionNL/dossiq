/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The plan-an-item dialog of the personal queue, as CnActionDialog config.
 *
 * Plan something on the reader's own day, with no case behind it: it writes a
 * calendar event on the reader's own calendar, never a case, so it enters no
 * case count, list or report. The templates suggest a title, and nothing
 * downstream branches on which one was picked.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

/** @type {object} */
export const PLAN_ITEM_DIALOG = Object.freeze({
	title: 'Plan an item',
	eyebrow: 'Your queue · own task',
	subtitle: 'This goes on your own calendar and on your queue. It is not a case.',
	fields: [
		{
			field: 'template',
			type: 'select',
			label: 'Template',
			required: false,
			options: [
				{ value: 'call-back', label: 'Call someone back', fills: { title: 'Call someone back' } },
				{ value: 'prepare-decision', label: 'Prepare a decision', fills: { title: 'Prepare a decision' } },
				{ value: 'site-visit', label: 'Site visit', fills: { title: 'Site visit' } },
				{ value: 'catch-up', label: 'Catch up on a case', fills: { title: 'Catch up on a case' } },
			],
		},
		{ field: 'title', label: 'What is it' },
		{ field: 'startsAt', type: 'datetime', label: 'When' },
	],
	confirm: { label: 'Schedule it', icon: 'CalendarClockOutline', request: { method: 'POST', url: '/apps/dossiq/api/personal-queue/planned-items' } },
})
