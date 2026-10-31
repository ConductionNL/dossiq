/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The DqZaaktype boards draw the case type's domain (VTH) as a teal pill.
 */
import { describe, expect, it } from 'vitest'
import simple from '../../src/menu-layout.simple.json'

const page = simple.pages.find((p) => p.id === 'CaseTypeDetail')

describe('the case type header pill (DqZaaktype)', () => {
	it('names the domain in a teal pill', () => {
		const config = page.config || page.configPatch || {}
		expect(config.typePill).toEqual({ field: 'category', variant: 'teal' })
	})
})
