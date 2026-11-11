/**
 * Golden test for the state entries of the acts menu (hold, take off hold,
 * promote), recorded before they were built from one table.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it } from 'vitest'
import { buildActsMenu } from '../../src/utils/caseActsMenu.js'

describe('acts menu state entries', () => {
	it('answers every held and draft value as before', () => {
		const values = [undefined, null, true, false, 1, 0, '1', '0', 'true', 'false', 'yes']
		const menus = {}
		for (const held of values) {
			for (const draft of [undefined, true, 'true', 0]) {
				menus[`held=${String(held)} draft=${String(draft)}`] = buildActsMenu({ acts: { held, draft } })
					.filter((entry) => entry.kind === 'state')
			}
		}
		menus['no acts'] = buildActsMenu({}).filter((entry) => entry.kind === 'state')
		menus['null acts'] = buildActsMenu({ acts: null }).filter((entry) => entry.kind === 'state')
		expect(menus).toMatchSnapshot()
	})
})
