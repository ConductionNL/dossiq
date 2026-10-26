/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBeheerGeavanceerd Mandaatmatrix: a mandate decision is named by its
 * decisionName, the field the mandateDecision schema carries, not by its uuid.
 *
 * @spec exclude a label fallback on an existing table; no requirement covers the row name
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const table = fs.readFileSync(path.join(ROOT, 'src/views/settings/components/DelegationMatrixTable.vue'), 'utf8')

describe('the delegation matrix table', () => {
	it('names a mandate decision by its decisionName before falling back to its id', () => {
		expect(table).toMatch(/b\.decisionName \|\| b\.mandateNumber \|\| b\.id/)
	})
})
