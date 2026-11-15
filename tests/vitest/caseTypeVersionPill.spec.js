/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype, DqZaaktypeOverzicht and DqZaaktypeVelden: the status pill names
 * the version, "Versie 4 gepubliceerd" or "Versie 5 concept".
 *
 * @spec openspec/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const page = readJson('src', 'menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const nl = readJson('l10n', 'nl.json').translations

describe('the case type status pill', () => {
	it('names the version and whether it is published', () => {
		expect(page.config.statusPill).toMatchObject({ field: 'isDraft', labels: { false: 'Version {version} published', true: 'Version {version} draft' } })
		expect(nl['Version {version} published']).toBe('Versie {version} gepubliceerd')
		expect(nl['Version {version} draft']).toBe('Versie {version} concept')
	})
})
