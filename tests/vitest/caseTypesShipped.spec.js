/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypen: the case type list offers the types dossiq delivered
 * (Meegeleverd) beside All, Published and Draft. A shipped type is one the app
 * imported, so OpenRegister records its owner as __system__; the chip asks for
 * that through the nested @self filter, the form OpenRegister answers.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const page = layout.pages.find((p) => p.id === 'CaseTypes')

describe('the case type list in the simple profile', () => {
	it('offers the shipped case types, current versions only, with a count', () => {
		const chip = page.config.quickFilters.find((q) => q.label === 'Shipped')
		expect(chip.filter).toEqual({ supersededBy: { isnull: true }, '@self': { owner: '__system__' } })
		expect(chip.showCount).toBe(true)
		expect(page.config.quickFilters.map((q) => q.label)).toEqual(['All', 'Published', 'Draft', 'Shipped'])
	})
})
