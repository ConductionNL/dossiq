// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The order and the colour of a case priority, for the browser.
//
// 🔴 THIS FILE IS A MIRROR, NOT A SOURCE. The declaration lives on the
// `priority` property of the `case` schema in `lib/Settings/dossiq_register.json`,
// as `x-enum-order` and `x-enum-colours`. That is where an administrator reads
// it, where `CasePriorityService` reads it, and where a change to it belongs.
//
// The browser cannot reach the schema at the moment it renders a table cell —
// the object store carries collections, not schemas — so the two values are
// mirrored here. A mirror drifts, silently, so it is PINNED:
// `tests/vitest/caseListPriority.spec.js` reads the register JSON and fails if
// these maps and the declaration disagree, and `CasePriorityDeclarationTest`
// does the same on the PHP side. Change the schema first and let the tests tell
// you what else to change.
//
// The colour is a NAME from the NL Design System palette, never a hex value, so
// a themed install repoints one token and every badge follows. `statusColour.js`
// resolves these names for the whole app; nothing here defines a colour.
//
// @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md

import { translate as t } from '@nextcloud/l10n'

/** The priority values, in the order the schema declares. */
export const PRIORITY_VALUES = ['low', 'normal', 'high', 'urgent']

/**
 * The declared order. A list cannot sort on the word: alphabetically `high`
 * comes before `low` and `urgent` comes last of all.
 */
export const PRIORITY_ORDER = {
	low: 1,
	normal: 2,
	high: 3,
	urgent: 4,
}

/** The NL Design System hue name each priority is drawn in. */
export const PRIORITY_COLOURS = {
	low: 'grey',
	normal: 'blue',
	high: 'orange',
	urgent: 'red',
}

/**
 * The colour name a priority is drawn in.
 *
 * @param {unknown} priority The stored `case.priority`.
 * @return {string} A name from the NL Design System palette; grey when the
 *   value is not one the schema declares.
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */
export function priorityColour(priority) {
	return PRIORITY_COLOURS[String(priority ?? '')] || 'grey'
}

/**
 * The declared order of a priority.
 *
 * @param {unknown} priority The stored `case.priority`.
 * @return {number} The order, 0 when the value is not one the schema declares.
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */
export function priorityOrder(priority) {
	return PRIORITY_ORDER[String(priority ?? '')] || 0
}

/**
 * What a priority is called in the reader's language.
 *
 * A switch of literal `t()` calls rather than `t('dossiq', priority)`, because
 * the l10n extractor reads the source: a dynamic key is invisible to it, so the
 * string never reaches `l10n/nl.json` and a Dutch reader quietly sees English.
 *
 * @param {unknown} priority The stored `case.priority`.
 * @return {string} The translated label, or the raw value when it is not one
 *   the schema declares.
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */
export function priorityLabel(priority) {
	switch (String(priority ?? '')) {
		case 'low':
			return t('dossiq', 'Low')
		case 'normal':
			return t('dossiq', 'Normal')
		case 'high':
			return t('dossiq', 'High')
		case 'urgent':
			return t('dossiq', 'Urgent')
		default:
			return String(priority ?? '')
	}
}
