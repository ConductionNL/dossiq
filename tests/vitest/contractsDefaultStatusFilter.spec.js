/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContracten opens on the contracts that still run, with "Status: actief
 * en verlopend" as a removable chip: every status a running contract can
 * have, as a default filter the person can clear.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const contracts = read('src/manifest.d/70-privacy-and-procurement.json').pages.find((page) => page.id === 'Contracts')
const status = read('lib/Settings/register.d/96-supplier-contract-status.json').components.schemas.supplierContract.properties.status

describe('the contracts default filter (DqContracten)', () => {
	it('opens on every status a running contract has', () => {
		expect(contracts.config.defaultFilters.status).toEqual(status.enum)
	})

	it('is not a fixed filter', () => {
		expect(contracts.config.filter).toBeUndefined()
		expect(contracts.config.fixedFilters).toBeUndefined()
	})
})
