/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContracten: the header note counts every contract and every supplier
 * ("38 contracten bij 21 leveranciers"), not the filtered list's total.
 *
 * @spec openspec/specs/dossiq-procurement-contract-lifecycle/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/70-privacy-and-procurement.json'), 'utf8'))
const contracts = fragment.pages.find((page) => page.id === 'Contracts')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

describe('the contracts header note (DqContracten)', () => {
	it('counts every contract and every supplier', () => {
		expect(contracts.config.headerMeta).toBe('{count:contracts} contracts with {count:suppliers} suppliers')
		expect(contracts.config.headerMetaCounts).toEqual({
			contracts: { register: 'dossiq', schema: 'supplierContract' },
			suppliers: { register: 'dossiq', schema: 'supplier' },
		})
		expect(nl[contracts.config.headerMeta]).toBe('{count:contracts} contracten bij {count:suppliers} leveranciers')
	})
})
