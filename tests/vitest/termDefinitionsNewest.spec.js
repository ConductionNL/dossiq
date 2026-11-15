/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The term table split: Integrations shows the three newest definitions with
 * a link to the full table on Advanced.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { newestTermDefinitions } from '../../src/utils/termDefinitions.js'

const read = (...parts) => fs.readFileSync(path.resolve(__dirname, '../..', ...parts), 'utf8')

describe('the newest term definitions', () => {
	it('keeps three, latest validity first and the higher version on a tie', () => {
		const defs = [
			{ id: 'a', validFrom: '2024-01-01', version: 1 },
			{ id: 'b', validFrom: '2026-07-01', version: 2 },
			{ id: 'c', validFrom: '2026-01-01', version: 1 },
			{ id: 'd', validFrom: '2026-01-01', version: 2 },
			{ id: 'e' },
		]
		expect(newestTermDefinitions(defs).map((def) => def.id)).toEqual(['b', 'd', 'c'])
		expect(defs[0].id).toBe('a')
	})

	it('returns a shorter list whole', () => {
		expect(newestTermDefinitions([{ id: 'a' }])).toEqual([{ id: 'a' }])
	})
})

describe('the term table split', () => {
	it('draws the shortened rows only in the summary', () => {
		const tab = read('src', 'views', 'settings', 'tabs', 'DeadlineDefinitionsTab.vue')
		expect(tab).toContain('v-for="def in shownDefinitions"')
		expect(tab).toMatch(/shownDefinitions\(\) \{\s+return this\.summary \? newestTermDefinitions\(this\.definitions\) : this\.definitions/)
	})

	it('links Integrations to the full table on Advanced', () => {
		const admin = read('src', 'views', 'settings', 'AdminRoot.vue')
		expect(admin).toContain(':footerLink="{ label: t(\'dossiq\', \'All term definitions\'), href: allTermDefinitionsUrl }"')
		expect(admin).toContain('id="section-term-definitions-all"')
		expect(admin).toContain("'?tab=advanced#section-term-definitions-all'")
	})
})
