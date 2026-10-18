/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisaties draws when each organisation was last in contact, and
 * DqContacten a masked BSN. Both come from nextcloud-vue (2.98.0): the
 * aggregate `op: max` and the built-in `masked` formatter, so dossiq keeps no
 * formatter of its own for either.
 *
 * @spec openspec/changes/screens-round12-relations/specs/initiator-display/spec.md
 */

import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const page = (id) => manifest.pages.find((p) => p.id === id)
const column = (id, key) => page(id).config.columns.find((c) => typeof c === 'object' && c.key === key)

describe('the relation lists read their derived columns from the library', () => {
	it('shows the newest contact moment of each organisation', () => {
		const last = column('Organisations', 'lastContact')
		expect(last.aggregate).toMatchObject({ schema: 'contactmoment', op: 'max', field: 'startTime', where: { contact: '@self.id' } })
		expect(last.sortable).toBe(false)
	})

	it('masks the BSN with the library formatter, not an app one', () => {
		expect(column('Contacts', 'citizenServiceNumber').formatter).toBe('masked')
		const source = readFileSync(resolve(__dirname, '../../src/services/formatters.js'), 'utf8')
		expect(source).not.toMatch(/^\s*masked:/m)
	})

	it('offers the organisations with an open case through the reverse reference', () => {
		const chip = page('Organisations').config.quickFilters.find((q) => q.label === 'With an open case')
		expect(chip.filter).toEqual({ '_related[case][requester][isFinalStatus]': false })
		expect(chip.showCount).toBe(true)
	})
})
