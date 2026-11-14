/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqVolgendeStap titles the lifecycle menu "Wat u met deze zaak kunt doen"
 * (coordinator 24 Oct: the board's words; only this string).
 *
 * @spec exclude board wording of one dialog title (DqVolgendeStap), translation only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

describe('the lifecycle menu title', () => {
	it('reads as the board draws it', () => {
		expect(nl['What you can do with this case']).toBe('Wat u met deze zaak kunt doen')
	})
})
