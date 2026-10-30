/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqVthInstellingen draws the DSO activity and the fee rule names in the
 * table's regular weight ("Bouwen van een bouwwerk"), not as strong cells
 * (r26d: cell weight 500 vs 400).
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-dso-activity-table-shows-what-each-activity-becomes-and-when-it-last-arrived
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const admin = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/AdminRoot.vue'), 'utf8')

/**
 * @param {string} name The computed that returns the columns.
 * @return {string} Its body.
 */
function columns(name) {
	const at = admin.indexOf(`${name}() {`)
	return admin.slice(at, admin.indexOf('\n\t\t},', at))
}

describe('the VTH tables', () => {
	it('draw the activity and fee rule names in the regular weight', () => {
		expect(columns('intakeActivityColumns')).not.toContain('cn-cell--strong')
		expect(columns('feeScheduleColumns')).not.toContain('cn-cell--strong')
	})
})
