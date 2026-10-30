/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Case types list draws the breadcrumb row under its title with the count
 * in its note, and no count subtitle (board DqZaaktypen).
 *
 * @spec openspec/specs/case-types-header-crumb-row/spec.md#requirement-the-case-types-list-keeps-its-count-in-the-breadcrumb-row
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const page = simple.pages.find((entry) => entry.id === 'CaseTypes')

describe('case types list header', () => {
	it('sets no count subtitle', () => {
		expect(page.config.countSubtitle).toBeUndefined()
	})

	it('keeps the count in the breadcrumb row note', () => {
		expect(page.config.headerMeta).toContain('{total}')
		expect(page.config.breadcrumb).toBeTruthy()
	})
})
