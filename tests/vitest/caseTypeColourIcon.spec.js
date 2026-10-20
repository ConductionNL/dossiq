/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type is drawn in its own colour and icon: the type pill on a case,
 * the type tag in the case lists, and the case type form and card where an
 * author picks both.
 *
 * @spec openspec/changes/case-type-colour-icon/specs/case-types/spec.md#requirement-a-case-type-carries-a-colour-and-an-icon
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { STATUS_COLOURS } from '../../src/utils/statusColour.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const manifest = readJson('src', 'manifest.json')
const simple = readJson('src', 'menu-layout.simple.json')
const fragment = readJson('lib', 'Settings', 'register.d', '99-case-type-palette.json')
const icons = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

const page = (file, id) => file.pages.find((entry) => entry.id === id)
const RESOLVE = { colorField: 'colour', iconField: 'icon' }

/**
 * Every badge cell keyed on caseType anywhere in a node.
 *
 * @param {unknown} node The node.
 * @param {Array<object>} out The cells found.
 * @return {Array<object>} The cells.
 */
function caseTypeBadges(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => caseTypeBadges(child, out))
	} else if (node && typeof node === 'object') {
		if (node.key === 'caseType' && node.widget === 'badge') {
			out.push(node)
		}
		Object.values(node).forEach((child) => caseTypeBadges(child, out))
	}
	return out
}

describe('case type colour and icon', () => {
	it('declares the colour over the status palette, teal included', () => {
		const colour = fragment.components.schemas.caseType.properties.colour
		expect(colour.enum).toEqual(STATUS_COLOURS)
		expect(STATUS_COLOURS).toContain('teal')
		expect(STATUS_COLOURS).toContain('teal-light')
	})

	it('resolves the type pill on a case to its case type colour and icon', () => {
		const pill = page(simple, 'CaseDetail').config.typePill
		expect(pill.resolve).toMatchObject({ register: 'dossiq', schema: 'caseType', ...RESOLVE })
	})

	it('resolves every case type tag in the case lists', () => {
		const badges = caseTypeBadges(simple)
		expect(badges.length).toBeGreaterThan(0)
		for (const badge of badges) {
			expect(badge.widgetProps.resolve).toEqual(RESOLVE)
		}
	})

	it('offers colour and icon on the case type form and card, with swatches and icons', () => {
		const list = page(manifest, 'CaseTypes').config
		const detail = page(manifest, 'CaseTypeDetail').config
		expect(list.includeFields).toEqual(expect.arrayContaining(['colour', 'icon']))
		const core = detail.widgets.find((widget) => widget.id === 'case-type-core')
		expect(core.content.include).toEqual(expect.arrayContaining(['colour', 'icon']))
		for (const overrides of [list.fieldOverrides, detail.fieldOverrides]) {
			expect(overrides.colour.widget).toBe('select')
			expect(Object.keys(overrides.colour.optionSwatches)).toEqual(STATUS_COLOURS)
			for (const hue of STATUS_COLOURS) {
				expect(overrides.colour.optionSwatches[hue]).toMatch(new RegExp(`^var\\(--nl-color-${hue}, #[0-9a-f]{6}\\)$`))
			}
			expect(overrides.icon.widget).toBe('select')
			for (const name of overrides.icon.enum) {
				expect(overrides.icon.optionIcons[name]).toBe(name)
				expect(icons).toContain(`\t${name},\n`)
			}
		}
	})

	it('paints teal statuses on the board columns and map markers too', () => {
		const text = JSON.stringify(manifest)
		const maps = text.match(/"purple-light":"var\(--nl-color-purple-light, #f2eaf5\)"/g) || []
		expect(maps.length).toBeGreaterThan(0)
		expect((text.match(/"teal":"var\(--nl-color-teal, #1d5e66\)"/g) || []).length).toBe(maps.length)
	})
})
