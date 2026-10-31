/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaak "Termijn en betaling" writes "Laatst gecontroleerd" as the board's
 * date with the year ("6 okt 2026"), not the stored date-time
 * ("26-10-2026, 17:47:35", r32).
 *
 * @spec openspec/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const text = fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8')

describe('the term and payment card', () => {
	it('writes the last check as a board date with the year', () => {
		const at = text.indexOf('"paymentStateCheckedAt": {\n\t\t\t\t\t\t\t\t\t"label": "Last checked"')
		expect(at).toBeGreaterThan(-1)
		expect(text.slice(at, text.indexOf('}', at))).toContain('"format": "date-long"')
	})
})
