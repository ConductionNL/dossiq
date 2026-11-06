/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisaties: a long trade name wraps under itself ("Aannemersbedrijf
 * / Van Wijk B.V."), and an organisation without open cases reads "Geen", as
 * its open tickets do.
 *
 * @spec openspec/changes/organisations-list-cells-as-drawn/specs/party-pages/spec.md#requirement-the-organisations-list-draws-its-cells-as-the-board
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'Organisations').config
const column = (key) => config.columns.find((entry) => entry.key === key)

describe('organisations list cells (DqOrganisaties)', () => {
	it('wraps a long trade name', () => {
		expect(String(column('tradeName').cellClass).split(' ')).toContain('cn-cell--wrap')
	})

	it('reads no open cases as "None", like no open tickets', () => {
		expect(column('openCases').aggregate.zeroLabel).toBe('None')
		expect(column('openCases').aggregate.zeroLabel).toBe(column('openTickets').aggregate.zeroLabel)
	})
})
