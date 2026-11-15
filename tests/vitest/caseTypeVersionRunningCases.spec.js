/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype's Versies card reads "Versie 4, 2 okt 2026 · 31 lopende zaken":
 * per version, the cases that run on it and are not finished.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const versions = page.config.sideColumn.find((card) => card.id === 'side-case-type-versions').content
const nl = read('l10n/nl.json').translations

describe('case type versions with their running cases (DqZaaktype)', () => {
	it('counts the unfinished cases of each version', () => {
		expect(versions.aggregates.runningCases).toEqual({
			register: 'dossiq',
			schema: 'case',
			op: 'count',
			where: { caseType: '@self.id', isFinalStatus: false },
			text: { zero: 'no running cases', one: '{count} running case', other: '{count} running cases' },
		})
	})

	it('reads date · n lopende zaken, and geen lopende zaken at zero', () => {
		// A draft has no validFrom yet and reads only its last change (draft-version-date): it has no running cases,
		// so DqZaaktypeOverzicht's "Concept, gewijzigd 7 okt" carries no count.
		expect(versions.meta.parts).toEqual([['{validFrom|date} · {runningCases}', 'Draft, changed {versionDate|date}']])
		const text = versions.aggregates.runningCases.text
		expect([nl[text.zero], nl[text.one], nl[text.other]]).toEqual(['geen lopende zaken', '{count} lopende zaak', '{count} lopende zaken'])
	})
})
