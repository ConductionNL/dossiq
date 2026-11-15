/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The colour a status is drawn in, and the three ways it can be absent.
 *
 * A status colour reaches the screen through a chain nothing else checks: the
 * schema enumerates fourteen names, a case type's author picks one, and three
 * separate surfaces (the Cases index badge, the case page's transition strip,
 * the Workflow board column) turn that name into CSS. The failure mode is not
 * a wrong colour, it is NO colour — an unset value, a value from a row saved
 * before the property existed, or a typo — rendering a transparent badge that
 * reads as a broken page rather than as an unconfigured status. Every one of
 * these asserts that the absent cases land on grey.
 *
 * @spec openspec/specs/case-types/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	DEFAULT_STATUS_COLOUR,
	isLightStatusColour,
	isStatusColour,
	normaliseStatusColour,
	STATUS_COLOUR_BADGE_VARIANTS,
	STATUS_COLOURS,
	statusBadgeStyle,
	statusColourStyle,
	statusColourToken,
	statusTextToken,
} from '../../src/utils/statusColour.js'

const ROOT = path.resolve(__dirname, '../..')

describe('the palette', () => {
	it('carries the fourteen names the schema enumerates', () => {
		expect(STATUS_COLOURS).toHaveLength(14)
	})

	it('names each hue and its light variant', () => {
		for (const hue of ['blue', 'green', 'orange', 'red', 'purple', 'teal', 'grey']) {
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
		['a name outside the palette', 'magenta'],
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

	it.each([[''], [undefined], [null], ['#ff0000'], ['magenta']])(
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
		expect(statusColourToken('magenta')).toBe(statusColourToken('grey'))
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
		for (const name of [...STATUS_COLOURS, '', 'magenta', undefined]) {
			expect(statusColourStyle(name).backgroundColor).toMatch(
				/^var\(--nl-color-/,
			)
		}
	})
})

describe('statusBadgeStyle', () => {
	it('draws a full hue as its own tint with the theme\'s pill text, else the darkened hue', () => {
		const style = statusBadgeStyle('blue')
		expect(style.backgroundColor).toBe(statusColourToken('blue-light'))
		expect(style.color).toBe(
			`var(--nl-color-blue-text, color-mix(in srgb, ${statusColourToken('blue')} 65%, var(--color-main-text, #1b1c1d)))`,
		)
	})

	it('leaves a light tint as its tint, with the theme\'s pill text, else the main text', () => {
		expect(statusBadgeStyle('green-light')).toEqual({
			backgroundColor: statusColourStyle('green-light').backgroundColor,
			color: 'var(--nl-color-green-text, var(--color-main-text))',
		})
	})

	it('reads the pill text token of every hue', () => {
		for (const hue of ['blue', 'green', 'orange', 'red', 'purple', 'grey']) {
			expect(statusBadgeStyle(hue).color.startsWith(`var(--nl-color-${hue}-text, `), hue).toBe(true)
			expect(statusTextToken(hue, 'black')).toBe(`var(--nl-color-${hue}-text, black)`)
		}
	})

	it('reaches AA on every hue, computed on the fallback ramps', () => {
		// The 65% mix of the hue with the ink, over the hue's light tint.
		const hex = (value) => value.match(/#[0-9a-f]{6}/i)[0]
		const channel = (h, i) => parseInt(h.slice(1 + 2 * i, 3 + 2 * i), 16) / 255
		const luminance = (h) =>
			[0.2126, 0.7152, 0.0722].reduce((sum, weight, i) => {
				const c = channel(h, i)
				return (
					sum
					+ weight
						* (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
				)
			}, 0)
		const mix = (a, b, share) =>
			'#'
			+ [0, 1, 2]
				.map((i) =>
					Math.round(
						(channel(a, i) * share + channel(b, i) * (1 - share)) * 255,
					)
						.toString(16)
						.padStart(2, '0'),
				)
				.join('')
		for (const hue of ['blue', 'green', 'orange', 'red', 'purple', 'teal', 'grey']) {
			const text = mix(hex(statusColourToken(hue)), '#1b1c1d', 0.65)
			const tint = hex(statusColourToken(`${hue}-light`))
			const [hi, lo] = [luminance(text), luminance(tint)].sort((a, b) => b - a)
			expect((hi + 0.05) / (lo + 0.05), hue).toBeGreaterThanOrEqual(4.5)
		}
	})

	it('is drawn by the library badge on the Status column, with the colour read off the status type', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
		const status = manifest.pages.find((p) => p.id === 'Cases').config.columns.find(
			(c) => typeof c === 'object' && c.key === 'status',
		)
		expect(status.widget).toBe('badge')
		expect(status.widgetProps.resolve).toEqual({ register: 'dossiq', schema: 'statusType', colorField: 'colour' })
	})
})

describe('the badge tone of a status colour', () => {
	it('gives every colour the schema allows a tone the library badge knows', () => {
		const tones = ['default', 'info', 'success', 'warning', 'error', 'purple', 'teal']
		for (const hue of STATUS_COLOURS) {
			expect(tones).toContain(STATUS_COLOUR_BADGE_VARIANTS[hue])
		}
	})

	it('gives a hue and its light twin the same tone', () => {
		expect(STATUS_COLOUR_BADGE_VARIANTS['orange-light']).toBe(
			STATUS_COLOUR_BADGE_VARIANTS.orange,
		)
	})
})
