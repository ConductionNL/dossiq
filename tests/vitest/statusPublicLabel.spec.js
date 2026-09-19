/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a status says to the applicant, and what it must never say
 * (citizen-status-labels, REQ-CT-25).
 *
 * The label falls back to the name: that is what makes the property additive,
 * and a case type written before today reads exactly as it did.
 *
 * 🔴 The description does NOT fall back. The internal `description` is written
 * for a handler and names internal checks, internal registers and colleagues
 * by role. A fallback there would publish those words to a citizen on every
 * case type that ever filled a description in, and the page would look
 * entirely correct while doing it.
 */

import { describe, expect, it } from 'vitest'
import {
	descriptionOnCase,
	labelOnCase,
	publicDescriptionOf,
	publicLabelOf,
} from '../../src/utils/statusPublicLabel.js'

describe('publicLabelOf', () => {
	it('shows the declared public label', () => {
		expect(
			publicLabelOf({
				name: 'Toets register B',
				publicLabel: 'We check your application',
			}),
		).toBe('We check your application')
	})

	it('falls back to the name when no label is declared', () => {
		expect(publicLabelOf({ name: 'Ontvangen' })).toBe('Ontvangen')
	})

	it('treats a blank label as no label', () => {
		expect(publicLabelOf({ name: 'In behandeling', publicLabel: '   ' })).toBe(
			'In behandeling',
		)
	})

	it('reads a row with neither as nothing', () => {
		expect(publicLabelOf({})).toBe('')
		expect(publicLabelOf(null)).toBe('')
	})
})

describe('publicDescriptionOf', () => {
	it('shows the declared public description', () => {
		expect(
			publicDescriptionOf({
				description: 'Handler checks register B for prior objections.',
				publicDescription: 'You do not have to do anything right now.',
			}),
		).toBe('You do not have to do anything right now.')
	})

	it('never falls back to the internal description', () => {
		expect(
			publicDescriptionOf({
				name: 'Toets register B',
				description: 'Handler checks register B for prior objections.',
			}),
		).toBe('')
	})
})

describe('the case carries the answer', () => {
	it('reads the label the calculation already resolved', () => {
		const caseRow = {
			identifier: '2026-0001',
			statusPublicLabel: 'We check your application',
			statusPublicDescription: 'You hear from us within two weeks.',
		}

		expect(labelOnCase(caseRow)).toBe('We check your application')
		expect(descriptionOnCase(caseRow)).toBe('You hear from us within two weeks.')
	})

	it('reads a case projected without the fields as nothing to show', () => {
		expect(labelOnCase({ identifier: '2026-0001' })).toBe('')
		expect(descriptionOnCase({ identifier: '2026-0001' })).toBe('')
		expect(labelOnCase(null)).toBe('')
	})
})
