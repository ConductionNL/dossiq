/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype's Historie card ends in "Kenmerk ZT-OMV-REG": the case type's
 * identifier under the trail.
 *
 * @spec openspec/changes/case-type-history-reference/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const page = readJson('src', 'menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const history = page.config.sideColumn.find((card) => card.id === 'side-case-type-history')
const nl = readJson('l10n', 'nl.json').translations

describe('the case type Historie card', () => {
	it('ends in the case type\'s reference', () => {
		expect(history.content.footnote).toBe('Reference {identifier}')
		expect(nl['Reference {identifier}']).toBe('Kenmerk {identifier}')
	})
})
