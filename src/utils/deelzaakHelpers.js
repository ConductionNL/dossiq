// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// Pure presentation helpers for the deelzaak (sub-case) UI surface.
//
// These functions hold the user-facing string + visibility logic for the
// case-list sub-case count badge (T10) and for what a refused delete says
// (REQ-CM-35). They are deliberately DOM-free and side-effect-free so the
// vitest suite (node environment) can pin the exact rendered copy without a
// browser. The Vue layer (formatters.js, DeelzaakList.vue) consumes them.

import { translate as t } from '@nextcloud/l10n'

/**
 * Badge label for a sub-case count.
 *
 * Returns an empty string for counts <= 0 so the caller renders NO badge for
 * cases without sub-cases (spec REQ-DZS-005-B). For positive counts it
 * returns the translated "{count} sub-cases" — the English source literal,
 * rendered as "N deelzaken" in Dutch via `l10n/nl.json`.
 *
 * @param {number} count Number of sub-cases for the case.
 * @return {string} Badge label, or '' when no badge should be shown.
 * @spec openspec/changes/deelzaak-support/tasks.md#T10
 */
export function subCaseCountBadge(count) {
	const n = Number(count)
	if (!Number.isFinite(n) || n <= 0) {
		return ''
	}
	return t('dossiq', '{count} sub-cases', { count: n })
}

/**
 * Whether a sub-case count should render a badge at all.
 *
 * @param {number} count Number of sub-cases for the case.
 * @return {boolean} True when count > 0.
 * @spec openspec/changes/deelzaak-support/tasks.md#T10
 */
export function hasSubCaseBadge(count) {
	const n = Number(count)
	return Number.isFinite(n) && n > 0
}

/**
 * What a refused delete tells the person who asked for it.
 *
 * REQ-CM-35 refuses the delete of a held case with a sentence naming every
 * rule that holds it, and that sentence is the whole point: a generic "could
 * not be deleted" throws away the only thing the person needs to act on. It
 * is read from the server's refusal body, which OpenRegister nests under
 * `errors` when a guard stopped the write and puts at the top level when the
 * refusal came from a dossiq door.
 *
 * The generic line is the fallback for a failure that carried no sentence at
 * all (a network drop, a 500), never a replacement for one that did.
 *
 * @param {object} err The error the delete threw.
 * @return {string} The refusal sentence, or the generic fallback.
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 */
export function refusalMessage(err) {
	const body = err?.response?.data ?? {}
	const sentence = body?.errors?.message ?? body?.message ?? body?.detail
	if (typeof sentence === 'string' && sentence.trim() !== '') {
		return sentence
	}
	return t('dossiq', 'The case could not be deleted. Please try again.')
}
