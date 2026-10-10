// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The My Work card keeps its case type inside the card and draws a late
 * deadline in a colour that can be read.
 *
 * Both were found on the review instance (10 Oct, finding F6): the chip
 * "Omgevingsvergunning kleine bouwactiviteit" ran 72px past the card's edge,
 * and "Termijn: 7 okt 2026" on a late case was drawn in `--color-error`, which
 * the NL Design System theme maps to the tint #FFE7E7 (1.2:1 on white).
 *
 * jsdom computes no scoped SCSS, so the colour token is asserted on the
 * stylesheet itself and the chip's tooltip on the mounted card.
 *
 * @spec openspec/changes/my-work-cards-and-team-tiles-read-right/specs/my-work/spec.md
 */

import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import MyWorkCaseCard from '../../src/views/MyWorkCaseCard.vue'

const LONG = 'Omgevingsvergunning kleine bouwactiviteit'
const source = fs.readFileSync(
	path.resolve(__dirname, '../../src/views/MyWorkCaseCard.vue'),
	'utf8',
)
const style = source.slice(source.indexOf('<style'))

/**
 * The SCSS block that opens with a selector, up to its closing brace.
 *
 * @param {string} selector The selector the block starts with.
 * @return {string} The block.
 */
function block(selector) {
	const start = style.indexOf(selector)
	return start === -1 ? '' : style.slice(start, style.indexOf('}', start) + 1)
}

describe('the case type chip', () => {
	it('carries the full case type name as its tooltip', () => {
		const wrapper = mount(MyWorkCaseCard, {
			props: {
				object: { id: 'c1', title: 'Schuur', caseType: 'ct-1' },
				caseTypeMap: { 'ct-1': LONG },
			},
		})
		const chip = wrapper.find('.mywork-card__chip')

		expect(chip.text()).toBe(LONG)
		expect(chip.attributes('title')).toBe(LONG)
	})

	it('ends in an ellipsis inside the card rather than running past it', () => {
		const chip = block('.mywork-card__chip {')

		expect(chip).toContain('max-width: 100%;')
		expect(chip).toContain('overflow: hidden;')
		expect(chip).toContain('text-overflow: ellipsis;')
	})
})

describe('a late deadline', () => {
	it('is drawn in the error TEXT colour, not the error tint', () => {
		const overdue = block('&--overdue {')

		expect(overdue).toContain('color: var(--color-error-text')
		expect(overdue).not.toMatch(/color: var\(--color-error\);/)
	})
})
