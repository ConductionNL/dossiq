/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype's Gegevens card ends in "Lopende zaken 43": the cases of this
 * case type that have not reached a final status.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const page = readJson('src', 'menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const facts = page.config.sideColumn.find((card) => card.id === 'side-case-type-facts').content.fields
const nl = readJson('l10n', 'nl.json').translations

describe('the case type Gegevens card', () => {
	it('ends with the running cases of this case type', () => {
		const last = facts[facts.length - 1]
		expect(last).toEqual({ label: 'Running cases', countFrom: { register: 'dossiq', schema: 'case', filter: { caseType: '@objectId', isFinalStatus: false } } })
		expect(nl[last.label]).toBe('Lopende zaken')
	})
})
