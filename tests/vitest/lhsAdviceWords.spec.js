/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqHandhaving: the override authority reads as the role, the Zaak card's
 * handler as a person, and the notice names the matrix the advice applied.
 */
import manifest from '../../src/manifest.json'
import nl from '../../l10n/nl.json'
import { describe, expect, it } from 'vitest'

const page = manifest.pages.find((entry) => entry.id === 'LhsRecommendationDetail')
const card = (id) => page.config.sideColumn.find((entry) => entry.id === id)

describe('the advice words on the side cards (DqHandhaving)', () => {
	it('names the override authority as the role and the handler as a person', () => {
		const override = card('side-override').content.fields.find((f) => f.path === 'overrideAuthority')
		expect(nl.translations[override.map.manager]).toBe('Teamleider handhaving')
		const handler = card('side-case').content.fields.find((f) => f.path === 'recommendedBy')
		expect(handler.format).toBe('user')
	})

	it('names the matrix in the notice from the record its cells come from', () => {
		const matrix = page.config.widgets.find((w) => w.id === 'lhs-matrix')
		expect(matrix.content.notice.values.matrix).toBe('@source.name')
	})
})

describe('the inspection on the advice cards (DqHandhaving)', () => {
	it('names the inspection by where it took place, its report having no title', () => {
		const labels = page.config.sideColumn
			.flatMap((entry) => (entry.content && entry.content.fields) || [])
			.filter((field) => field.path === 'inspection')
			.map((field) => field.resolve.labelField)
		expect(labels).toEqual(['location', 'location'])
	})
})
