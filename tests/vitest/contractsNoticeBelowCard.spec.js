/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContracten draws "Verlengen en opzeggen zijn besluiten ..." under the
 * contracts card, on the page ground, not inside the card.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/70-privacy-and-procurement.json'), 'utf8'))
const contracts = fragment.pages.find((page) => page.id === 'Contracts')

describe('the contracts notice (DqContracten)', () => {
	it('sits under the list card', () => {
		expect(contracts.config.notice.placement).toBe('page')
	})
})
