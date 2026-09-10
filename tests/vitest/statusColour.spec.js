/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The colour a status is drawn in, and the three ways it can be absent.
 *
 * A status colour reaches the screen through a chain nothing else checks: the
 * schema enumerates twelve names, a case type's author picks one, and three
 * separate surfaces (the Cases index badge, the case page's transition strip,
 * the Workflow board column) turn that name into CSS. The failure mode is not
 * a wrong colour, it is NO colour — an unset value, a value from a row saved
 * before the property existed, or a typo — rendering a transparent badge that
 * reads as a broken page rather than as an unconfigured status. Every one of
 * these asserts that the absent cases land on grey.
 *
 * @spec openspec/specs/case-types/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	DEFAULT_STATUS_COLOUR,
	isLightStatusColour,
	isStatusColour,
	mergeColumnColour,
	normaliseStatusColour,
	STATUS_COLOURS,
	statusColourStyle,
	statusColourToken,
} from '../../src/utils/statusColour.js'

describe('the palette', () => {
	it('carries the twelve names the schema enumerates', () => {
		expect(STATUS_COLOURS).toHaveLength(12)
	})

	it('names each hue and its light variant', () => {
		for (const hue of ['blue', 'green', 'orange', 'red', 'purple', 'grey']) {
			expect(STATUS_COLOURS).toContain(hue)
			expect(STATUS_COLOURS).toContain(`${hue}-light`)
		}
	})

	it('defaults to a colour that is itself in the palette', () => {
		expect(STATUS_COLOURS).toContain(DEFAULT_STATUS_COLOUR)
	})
})

describe('isStatusColour', () => {
	it('accepts every name in the palette', () => {
		for (const name of STATUS_COLOURS) {
			expect(isStatusColour(name)).toBe(true)
		}
	})

	it.each([
		['an empty string', ''],
		['undefined', undefined],
		['null', null],
		['a hex value', '#ff0000'],
		['a name outside the palette', 'teal'],
		['a number', 3],
		['a boolean', true],
	])('refuses %s', (_label, value) => {
		expect(isStatusColour(value)).toBe(false)
	})
})

describe('normaliseStatusColour', () => {
	it('passes a palette name through', () => {
		expect(normaliseStatusColour('orange')).toBe('orange')
	})

	it.each([[''], [undefined], [null], ['#ff0000'], ['teal']])(
		'falls back to grey for %s',
		(value) => {
			expect(normaliseStatusColour(value)).toBe('grey')
		},
	)
})

describe('statusColourToken', () => {
	it('reads the NL Design System token for the name', () => {
		expect(statusColourToken('orange')).toMatch(/^var\(--nl-color-orange, /)
	})

	it('carries a hex fallback, so a stock instance still paints', () => {
		// Without the fallback every badge is transparent on an install that
		// does not ship the NL Design System theme — declared, shipped, and
		// rendering nothing.
		for (const name of STATUS_COLOURS) {
			expect(statusColourToken(name)).toMatch(
				new RegExp(`^var\\(--nl-color-${name}, #[0-9a-f]{6}\\)$`),
			)
		}
	})

	it('gives every name in the palette a DISTINCT fallback', () => {
		const fallbacks = STATUS_COLOURS.map(
			(name) => statusColourToken(name).split(', ')[1],
		)
		expect(new Set(fallbacks).size).toBe(STATUS_COLOURS.length)
	})

	it('resolves an unknown name to the grey token', () => {
		expect(statusColourToken('teal')).toBe(statusColourToken('grey'))
	})
})

describe('isLightStatusColour', () => {
	it('is true for the tints', () => {
		expect(isLightStatusColour('blue-light')).toBe(true)
	})

	it('is false for the full hues', () => {
		expect(isLightStatusColour('blue')).toBe(false)
	})

	it('is false for an unset colour, which resolves to grey', () => {
		expect(isLightStatusColour(undefined)).toBe(false)
	})
})

describe('statusColourStyle', () => {
	it('puts dark text on a light tint', () => {
		expect(statusColourStyle('green-light').color).toBe('var(--color-main-text)')
	})

	it('puts light text on a full hue', () => {
		expect(statusColourStyle('green').color).toMatch(
			/--color-primary-element-text/,
		)
	})

	it('always names a background', () => {
		for (const name of [...STATUS_COLOURS, '', 'teal', undefined]) {
			expect(statusColourStyle(name).backgroundColor).toMatch(
				/^var\(--nl-color-/,
			)
		}
	})
})

describe('mergeColumnColour', () => {
	it('takes the candidate when the column has none yet', () => {
		expect(mergeColumnColour(null, 'orange')).toBe('orange')
	})

	it('keeps the colour the column already took', () => {
		// Two case types can colour the same status name differently and the
		// merged board column can only be one of them: first wins, and the
		// second is ignored rather than blended into a third colour neither
		// author asked for.
		expect(mergeColumnColour('orange', 'purple')).toBe('orange')
	})

	it('stays null while neither side names a palette colour', () => {
		// null rather than grey: the column has no colour YET, and a caller
		// that merges a third status must still be able to take its colour.
		expect(mergeColumnColour(null, '')).toBeNull()
		expect(mergeColumnColour(undefined, 'teal')).toBeNull()
	})

	it('lets a later status colour a column an earlier one left unset', () => {
		expect(mergeColumnColour(mergeColumnColour(null, ''), 'red')).toBe('red')
	})

	it('lets a chosen hue beat a stored grey, whichever arrives first', () => {
		// The schema defaults `statusType.colour` to grey, so a status nobody
		// coloured is STORED as grey and is indistinguishable from a deliberate
		// one. Letting that grey win the merge made the column's colour depend on
		// which case type the collection endpoint happened to answer first: the
		// board drew an authored orange status grey while its own status badge
		// stayed orange.
		expect(mergeColumnColour('grey', 'orange')).toBe('orange')
		expect(mergeColumnColour('orange', 'grey')).toBe('orange')
	})

	it('stays grey when grey is all any of the merged statuses carry', () => {
		expect(mergeColumnColour('grey', 'grey')).toBe('grey')
		expect(mergeColumnColour(null, 'grey')).toBe('grey')
		expect(mergeColumnColour('grey', '')).toBe('grey')
	})

	it('treats grey-light as a chosen hue, because nothing defaults to it', () => {
		expect(mergeColumnColour('grey', 'grey-light')).toBe('grey-light')
	})
})
