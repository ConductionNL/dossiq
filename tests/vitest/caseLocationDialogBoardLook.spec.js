/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Add location dialog as the board draws it (DqZaakLocatie): the wizard
 * width, the address field named once by its own label, the map beside a
 * 200px layer list, and BAG and cadastre cards with a 17px title over 14px
 * rows 8px apart. Read from the component.
 *
 * @spec openspec/changes/case-location-dialog-board-look/specs/case-dashboard-view/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/CaseLocationDialog.vue'), 'utf8')
const style = source.slice(source.indexOf('>', source.indexOf('<style')) + 1).replace(/\/\*[\s\S]*?\*\//g, '')

/**
 * The body of the rule whose selector is exactly this one.
 *
 * @param {string} selector The selector.
 * @return {string} The rule body, or '' when there is none.
 */
function ruleFor(selector) {
	const re = /([^{}]+)\{([^{}]*)\}/g
	let m
	while ((m = re.exec(style))) {
		if (m[1].trim() === selector) {
			return m[2]
		}
	}
	return ''
}

describe('CaseLocationDialog in the board look', () => {
	it('opens at the wizard width', () => {
		expect(source).toMatch(/<CnDialog[^>]*\swidth="wizard"/)
	})

	it('names the address field once, by the field\'s own label', () => {
		expect(source).not.toContain('case-location-dialog__label')
	})

	it('draws the layer toggles as plain 14px checkboxes, 8px from their label', () => {
		const content = ruleFor('.case-location-dialog .case-location-dialog__layers :deep(.checkbox-radio-switch-checkbox .checkbox-content)')
		expect(content).toContain('gap: 8px')
		expect(content).toContain('padding: 0')
		expect(content).toContain('padding-inline-start: 4px')
		expect(ruleFor('.case-location-dialog__layers :deep(.checkbox-content__text)')).toContain('font-size: 14px')
	})

	it('draws the layer rows 34px apart with a regular-weight label (r34)', () => {
		expect(ruleFor('.case-location-dialog__layers')).toContain('gap: 10px')
		expect(ruleFor('.case-location-dialog__layers :deep(.checkbox-content__text)')).toContain('font-weight: 400')
	})

	it('sets the address label at 14px/600, 6px above the field', () => {
		const label = ruleFor('.case-location-dialog :deep(.select__label)')
		expect(label).toContain('font-size: 14px')
		expect(label).toContain('font-weight: 600')
		expect(label).toContain('margin-bottom: 6px')
	})

	it('sets the search hint 6px under the field, on the normal line', () => {
		const hint = ruleFor('.case-location-dialog > .case-location-dialog__hint')
		expect(hint).toContain('margin-top: -16px')
		expect(hint).toContain('line-height: 1.2')
	})

	it('puts the map beside a 200px layer list in an 8px rounded frame', () => {
		expect(ruleFor('.case-location-dialog__map-row')).toContain('grid-template-columns: minmax(0, 1fr) 200px')
		expect(ruleFor('.case-location-dialog__layers')).toContain('border-radius: 8px')
		expect(ruleFor('.case-location-dialog__layers legend')).toContain('font-weight: 600')
	})

	it('draws a fact card 12px rounded, 14px 16px in, with a 17px title at 700', () => {
		const card = ruleFor('.case-location-dialog__card')
		expect(card).toContain('border-radius: 12px')
		expect(card).toContain('padding: 14px 16px')
		expect(card).toContain('gap: 8px')
		const title = ruleFor('.case-location-dialog__card h3')
		expect(title).toContain('font-size: 17px')
		expect(title).toContain('font-weight: 700')
	})

	it('sets the facts 8px apart with no padding of their own', () => {
		const list = ruleFor('.case-location-dialog__card dl')
		expect(list).toContain('gap: 8px 16px')
		expect(list).toContain('padding: 0')
	})
})
