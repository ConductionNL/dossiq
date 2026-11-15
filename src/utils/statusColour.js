// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The colour a status is drawn in.
//
// `statusType.colour` is a NAME from the NL Design System palette, never a hex
// value, so a themed install repoints one token and every badge, every board
// column header and every stepper step follows. The names are the fourteen the
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
	'teal',
	'teal-light',
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
	teal: '#1d5e66',
	'teal-light': '#e3f1f3',
	grey: '#696969',
	'grey-light': '#f0f0f0',
}

/**
 * Whether a value is one of the colours the schema enumerates.
 *
 * @param {unknown} colour The candidate colour name.
 * @return {boolean} True when the name is in the palette.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function isStatusColour(colour) {
	return typeof colour === 'string' && STATUS_COLOURS.includes(colour)
}

/**
 * Normalise any stored value to a colour name in the palette.
 *
 * @param {unknown} colour The stored `statusType.colour`.
 * @return {string} A name from STATUS_COLOURS; grey when there is none.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function normaliseStatusColour(colour) {
	return isStatusColour(colour) ? colour : DEFAULT_STATUS_COLOUR
}

/**
 * The CSS value a status colour resolves to.
 *
 * @param {unknown} colour The stored `statusType.colour`.
 * @return {string} A `var(--nl-color-…, #hex)` expression.
 *
 * @spec openspec/specs/case-types/spec.md
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
 *
 * @spec openspec/specs/case-types/spec.md
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
 *
 * @spec openspec/specs/case-types/spec.md
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
 * The inline style a status BADGE carries: a soft tint with the hue's dark
 * text over it, the way the Zuiddrecht workplace boards draw every status
 * pill (DqTokens "Statuskleuren": zachte vlakken met donkere tekst).
 *
 * A full hue becomes its own `-light` tint as the background. The text is the
 * theme's pill text for that hue (`--nl-color-<hue>-text`) when it names one,
 * else the hue darkened towards the main text: `color-mix(in srgb, <hue> 65%,
 * <text>)`. A `-light` tint stays the tint, with the theme's pill text or else
 * the main text.
 *
 * Computed on the fallback ramps, text on tint: blue 7.20:1, green 6.50:1,
 * orange 5.20:1, red 7.01:1, purple 9.45:1, grey 7.30:1 (all AA). The full
 * hue with white text, which this replaces, gave orange 3.22:1 and green
 * 4.52:1; and under a set whose primary text token is dark (Zuiddrecht:
 * `--color-primary-element-text` reads as the ink there) the old badge drew
 * ink on blue at 2.2:1. The settings swatches keep `statusColourStyle`: a
 * swatch shows the hue itself, a badge shows the status.
 *
 * @param {unknown} colour The stored `statusType.colour`.
 * @return {{backgroundColor: string, color: string}} The style object.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function statusBadgeStyle(colour) {
	const name = normaliseStatusColour(colour)
	if (isLightStatusColour(name)) {
		const style = statusColourStyle(name)
		return { ...style, color: statusTextToken(name.replace(/-light$/, ''), style.color) }
	}
	return {
		backgroundColor: statusColourToken(`${name}-light`),
		color: statusTextToken(name, `color-mix(in srgb, ${statusColourToken(name)} 65%, var(--color-main-text, #1b1c1d))`),
	}
}

/**
 * The text a status badge sets on a hue's tint: the theme's own pill text
 * for that hue (`--nl-color-<hue>-text`, the boards' text in the workplace
 * sets, each at AA on its tint), else the given fallback.
 *
 * @param {string} hue A hue name from the palette, without `-light`.
 * @param {string} fallback The CSS colour when the theme names none.
 * @return {string} A `var(--nl-color-<hue>-text, <fallback>)` expression.
 *
 * @spec openspec/changes/archive/2026-10-30-status-badge-text-token/specs/case-types/spec.md#requirement-a-status-badge-sets-the-theme-s-pill-text
 */
export function statusTextToken(hue, fallback) {
	return `var(--nl-color-${hue}-text, ${fallback})`
}

/**
 * A status colour as one of the library badge's tones, for a pill drawn by
 * `CnStatusBadge` (the facts widget's `as: "pill"`), which takes a tone and
 * not a hue. A hue and its light twin share a tone. Grey is the neutral
 * tone, and so is a status with no colour, as on the Cases list.
 *
 * @type {Readonly<Record<string, string>>}
 * @spec openspec/specs/case-types/spec.md
 */
export const STATUS_COLOUR_BADGE_VARIANTS = Object.freeze({
	blue: 'info',
	'blue-light': 'info',
	green: 'success',
	'green-light': 'success',
	orange: 'warning',
	'orange-light': 'warning',
	red: 'error',
	'red-light': 'error',
	purple: 'purple',
	'purple-light': 'purple',
	teal: 'teal',
	'teal-light': 'teal',
	grey: 'default',
	'grey-light': 'default',
})
