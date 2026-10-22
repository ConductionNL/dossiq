/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case board (DqZaak, DqZaakMeer) draws the Locations card as a small map
 * with its markers and no zoom, fit or locate buttons.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')
const card = simple.configAppend.widgets.find((widget) => widget.id === 'case-locations-card')

describe('the Locations card', () => {
	it('draws the map without controls', () => {
		expect(card.content.controls).toBe(false)
	})
})
