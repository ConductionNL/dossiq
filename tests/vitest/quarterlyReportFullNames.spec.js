/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTermijnen Kwartaalrapport: the case types read in full
 * ("Omgevingsvergunning kleine bouwactiviteit") and the table scrolls, as
 * the board draws it, instead of cutting the first column to fit.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const page = manifest.pages.find((item) => item.id === 'TermijnDashboard')
const table = page.config.widgets.find((item) => item.id === 'deadline-report-quarterly')

describe('quarterly report table', () => {
	it('keeps the case type names whole', () => {
		expect(table.content.fitWidth).toBe(false)
	})
})
