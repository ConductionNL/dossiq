// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The colour a status is drawn in.
//
// `statusType.colour` is a NAME from the NL Design System palette, never a hex
// value, so a themed install repoints one token and every badge, every board
// column header and every stepper step follows. The names are the twelve the
// schema enumerates; anything else — an empty colour, a value from an older
// row, a typo — resolves to grey rather than to nothing, because a badge with
// no colour at all reads as a rendering fault rather than as an unconfigured
// status.
//
// Each name resolves to `var(--nl-color-<name>, <fallback>)`. The fallback is
// what makes this honest on an install that does NOT carry the NL Design
// System theme: without it every badge would be transparent on a stock
// Nextcloud, which is exactly the "declared it, shipped it, renders nothing"
// failure this app keeps finding. dossiq declares no --nl-color-* token of its
// own; thematiq does, and this reads whichever the instance has.
//
// @spec openspec/specs/case-types/spec.md

/** The colours `statusType.colour` accepts, in the schema's own order. */
export const STATUS_COLOURS = [
	'blue',
	'blue-light',
	'green',
	'green-light',
	'orange',
	'orange-light',
	'red',
	'red-light',
	'purple',
	'purple-light',
	'grey',
	'grey-light',
]

/** The colour a status with none configured is drawn in. */
export const DEFAULT_STATUS_COLOUR = 'grey'

// Hex fallbacks, used only when the instance carries no --nl-color-* token.
// Values are the NL Design System hue ramps; the -light variants are the tints
// the same ramps define, so a light badge keeps its hue rather than washing to
// the same near-white for every status.
const FALLBACK = {
	blue: '#0b71ab',
	'blue-light': '#e5f1f8',
	green: '#39870c',
	'green-light': '#eaf5e4',
	orange: '#e17000',
	'orange-light': '#fdf2e5',
	red: '#d52b1e',
	'red-light': '#fbe9e8',
	purple: '#762f8e',
	'purple-light': '#f2eaf5',
	grey: '#696969',
	'grey-light': '#f0f0f0',
}

/**
 * Whether a value is one of the colours the schema enumerates.
 *
 * @param {unknown} colour The candidate colour name.
 * @return {boolean} True when the name is in the palette.
 */
export function isStatusColour(colour) {
	return typeof colour === 'string' && STATUS_COLOURS.includes(colour)
}

/**
 * Normalise any stored value to a colour name in the palette.
 *
 * @param {unknown} colour The stored `statusType.colour`.
 * @return {string} A name from STATUS_COLOURS; grey when there is none.
 */
export function normaliseStatusColour(colour) {
	return isStatusColour(colour) ? colour : DEFAULT_STATUS_COLOUR
}

/**
 * The CSS value a status colour resolves to.
 *
 * @param {unknown} colour The stored `statusType.colour`.
 * @return {string} A `var(--nl-color-…, #hex)` expression.
 */
export function statusColourToken(colour) {
	const name = normaliseStatusColour(colour)
	return `var(--nl-color-${name}, ${FALLBACK[name]})`
}

/**
 * Whether a colour is a light tint, and so needs dark text over it.
 *
 * @param {unknown} colour The stored `statusType.colour`.
 * @return {boolean} True for the -light variants.
 */
export function isLightStatusColour(colour) {
	return normaliseStatusColour(colour).endsWith('-light')
}

/**
 * The inline style a badge or a column header in this colour carries.
 *
 * A `-light` tint is a background with the app's own text colour over it; a
 * full hue is a background with white over it. Returning both halves from one
 * place keeps a badge and a board column header from drifting apart.
 *
 * @param {unknown} colour The stored `statusType.colour`.
 * @return {{backgroundColor: string, color: string}} The style object.
 */
export function statusColourStyle(colour) {
	return {
		backgroundColor: statusColourToken(colour),
		color: isLightStatusColour(colour)
			? 'var(--color-main-text)'
			: 'var(--color-primary-element-text, #fff)',
	}
}

/**
 * The colour a merged board column takes.
 *
 * The Workflow board merges every non-final status that shares a NAME into one
 * column, because statuses are defined per case type and the board would
 * otherwise draw one near-empty column per type. Two types can give the same
 * status name two different colours, and the column can only be one of them:
 * the FIRST configured colour wins, in the order the board already sorts by.
 * Ignoring the later one is the honest outcome — blending two hues would give
 * the column a colour neither case type asked for.
 *
 * @param {unknown} current The colour the column already took, if any.
 * @param {unknown} candidate The colour of another status with the same name.
 * @return {string|null} The column's colour, or null while none is configured.
 */
export function mergeColumnColour(current, candidate) {
	if (isStatusColour(current)) return current
	if (isStatusColour(candidate)) return candidate
	return null
}
