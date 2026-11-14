/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaak's follow row: who else has the case open now, then Volgen, and no
 * follower count.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')

describe('the case follow row', () => {
	it('says who else is looking and draws no follower count', () => {
		expect(simple.config.followRow).toEqual({ presence: true, count: false })
	})
})
