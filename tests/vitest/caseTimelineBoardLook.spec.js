/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case History tab as the board draws it (DqZaakHistorie): a 17px title
 * over a 14px line, 4px apart and 16px above the kind chips; chips of 36px,
 * 18px rounded, 14px at 600; the chosen chip filled with no outline and its
 * count a light-on-ink bubble. Read from the component's style.
 *
 * @spec openspec/changes/case-history-board-look/specs/case-history-surface/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/views/cases/components/CaseTimelineTab.vue'), 'utf8')
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

describe('CaseTimelineTab in the board look', () => {
	it('draws the title 17px at 700 over a 14px line, 4px apart', () => {
		expect(source).toContain('<div class="case-timeline__heading">')
		expect(ruleFor('.case-timeline__heading')).toContain('gap: 4px')
		const title = ruleFor('.case-timeline__title')
		expect(title).toContain('font-size: 17px')
		expect(title).toContain('font-weight: 700')
		expect(title).toContain('line-height: 1.2')
		expect(ruleFor('.case-timeline__subtitle')).toContain('font-size: 14px')
	})

	it('keeps 16px between the header and the chips', () => {
		expect(ruleFor('.case-timeline__header')).toContain('margin-block-end: 16px')
	})

	it('draws a chip 36px high, 18px rounded, 14px at 600', () => {
		const chip = ruleFor('.case-timeline__chip')
		expect(chip).toContain('height: 36px')
		expect(chip).toContain('border-radius: 18px')
		expect(chip).toContain('font-size: 14px')
		expect(chip).toContain('font-weight: 600')
	})

	it('fills the chosen chip without an outline, its count light on ink', () => {
		expect(ruleFor('.case-timeline__chip--active')).toContain('border: 0')
		const count = ruleFor('.case-timeline__chip--active .case-timeline__chip-count')
		expect(count).toContain('var(--color-main-text)')
		expect(count).toContain('color: var(--color-main-background)')
	})

	it('rounds a count 10px', () => {
		expect(ruleFor('.case-timeline__chip-count')).toContain('border-radius: 10px')
	})
})
