/**
 * SPDX-FileCopyrightText: 2026 Conduction / Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lightweight stub for @nextcloud/l10n used by the Vitest unit suite.
 *
 * The real package resolves the active locale from the Nextcloud runtime,
 * which does not exist under Vitest. For pure-logic tests we only need a
 * deterministic translate() that returns the English source string with
 * {placeholder} substitution, so assertions can target exact output.
 */

/**
 * Substitute {key} placeholders from a vars object into a string.
 *
 * @param {string} text Source string with optional {placeholders}
 * @param {object} [vars] Replacement values keyed by placeholder name
 * @return {string}
 */
function interpolate(text, vars) {
	if (!vars) return text
	return text.replace(/\{(\w+)\}/g, (match, key) => (
		Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : match
	))
}

export function translate(app, text, vars) {
	return interpolate(text, vars)
}

export function translatePlural(app, singular, plural, count, vars) {
	return interpolate(count === 1 ? singular : plural, { count, ...vars })
}

export const t = translate
export const n = translatePlural
