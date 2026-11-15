/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBeheerinstellingen Adviseringsbeheer: the term and the warning side by
 * side, the address under them, as the other system sections lay out.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const admin = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/AdminRoot.vue'), 'utf8')

describe('Adviseringsbeheer', () => {
	it('lays its fields out in two columns', () => {
		const at = admin.indexOf('id="section-consultation"')
		const section = admin.slice(at, admin.indexOf('/>', at))
		expect(section).toContain(':columns="2"')
	})
})
