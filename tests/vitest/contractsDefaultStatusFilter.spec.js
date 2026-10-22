/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContracten opens with "Status: actief en verlopend" as a removable chip:
 * active and ending contracts, a default filter the person can clear, named
 * as the board names it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const contracts = read('src/manifest.d/70-privacy-and-procurement.json').pages.find((page) => page.id === 'Contracts')
const status = read('lib/Settings/register.d/96-supplier-contract-status.json').components.schemas.supplierContract.properties.status
const nl = read('l10n/nl.json').translations

describe('the contracts default filter (DqContracten)', () => {
	it('opens on the active and ending contracts, as the board does', () => {
		expect(contracts.config.defaultFilters.status).toEqual(['active', 'ending'])
		contracts.config.defaultFilters.status.forEach((value) => expect(status.enum).toContain(value))
	})

	it('names the chip "Status: actief en verlopend"', () => {
		const labels = contracts.config.filterLabels.status
		expect(nl[labels.label]).toBe('Status')
		expect(contracts.config.defaultFilters.status.map((value) => nl[labels.values[value]])).toEqual(['actief', 'verlopend'])
	})

	it('is not a fixed filter', () => {
		expect(contracts.config.filter).toBeUndefined()
		expect(contracts.config.fixedFilters).toBeUndefined()
	})
})
