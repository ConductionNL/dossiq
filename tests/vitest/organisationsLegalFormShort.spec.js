/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisaties: Rechtsvorm reads "BV" for "Besloten Vennootschap".
 *
 * @spec openspec/changes/organisations-legal-form-short/specs/party-pages/spec.md#requirement-the-organisations-list-abbreviates-the-legal-form-as-the-board-does
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const column = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'Organisations').config.columns.find((entry) => entry.key === 'legalForm')

describe('DqOrganisaties legal form', () => {
	it('maps the KvK words to the board abbreviations', () => {
		expect(column.formatter).toBe('valueMap')
		expect(column.formatterOptions.map['Besloten Vennootschap']).toBe('BV')
		expect(column.formatterOptions.map['Naamloze Vennootschap']).toBe('NV')
		expect(column.formatterOptions.map.Stichting).toBeUndefined()
	})
})
