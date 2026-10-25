/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisaties: counts are purple pills, an empty count is muted
 * "Geen", and the columns are as wide as the board draws them.
 *
 * @spec openspec/changes/organisations-count-pills/specs/party-pages/spec.md#requirement-the-organisations-list-shows-counts-as-pills-and-none-as-text
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const columns = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'Organisations').config.columns
const column = (key) => columns.find((entry) => entry.key === key)

describe('DqOrganisaties count columns', () => {
	it.each(['openCases', 'openTickets'])('%s is a purple pill with "None" as text', (key) => {
		expect(column(key).widget).toBe('badge')
		expect(column(key).widgetProps.variant).toBe('purple')
		expect(column(key).aggregate.zeroAs).toBe('text')
		expect(column(key).aggregate.zeroLabel).toBe('None')
	})

	it('takes the board widths', () => {
		expect(Object.fromEntries(columns.filter((entry) => entry.width).map((entry) => [entry.key, entry.width]))).toEqual({
			kvkNumber: '130px',
			legalForm: '151px',
			'address.place': '116px',
			openCases: '132px',
			openTickets: '120px',
			lastContact: '142px',
		})
		expect(column('legalForm').cellClass).toBe('cn-cell--wrap')
	})
})
