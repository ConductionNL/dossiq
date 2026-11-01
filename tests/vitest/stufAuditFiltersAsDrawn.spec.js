/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBeheerGeavanceerd "StUF-ZKN-auditlog": the three filters share one row in
 * equal columns, and "Opnieuw laden" and "CSV exporteren" stand under them as
 * two secondary buttons.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/StufAuditLog.vue'), 'utf8')
const template = sfc.slice(sfc.indexOf('<template>'), sfc.indexOf('<table'))

describe('the StUF audit log filters as the board draws them', () => {
	it('puts the three filters in a three-column grid', () => {
		expect(sfc).toMatch(/\.stuf-audit-log__filters \{[^}]*display: grid;[^}]*grid-template-columns: repeat\(3, minmax\(0, 1fr\)\);/)
	})

	it('draws both buttons as secondary buttons in their own row under the filters', () => {
		const actions = template.slice(template.indexOf('stuf-audit-log__buttons'))
		expect(template.indexOf('stuf-audit-log__buttons')).toBeGreaterThan(template.lastIndexOf('<NcSelect'))
		expect((actions.match(/<NcButton variant="secondary"/g) || []).length).toBe(2)
		expect(template).not.toMatch(/variant="(primary|tertiary)"/)
	})
})
