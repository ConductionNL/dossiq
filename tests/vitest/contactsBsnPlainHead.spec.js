/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqContacten (design-system #424): the BSN head is a plain label.
 * OpenRegister refuses sorting and filtering on an explicit-reveal value.
 *
 * @spec openspec/specs/party-pages/spec.md#requirement-the-contacts-list-neither-sorts-nor-filters-on-the-bsn
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const column = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
	.pages.find((page) => page.id === 'Contacts').config.columns.find((entry) => entry.key === 'citizenServiceNumber')

describe('DqContacten BSN column', () => {
	it('neither sorts nor filters', () => {
		expect(column.sortable).toBe(false)
		expect(column.filterable).toBe(false)
	})
})
