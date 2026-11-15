/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqPersoonlijkeInstellingen "Weergaven in mijn menu": the Woo-verzoeken view
 * filters on the case type's identifier. The Cases page names the case type
 * that value identifies, so the menu reads "Zaaktype Woo-verzoek".
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const cases = manifest.pages.find((page) => page.id === 'Cases')
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

describe('the Cases page names the case type an identifier filter means', () => {
	it('labels caseTypeIdentifier as the case type, read by its identifier', () => {
		expect(cases.config.filterLabels.caseTypeIdentifier).toEqual({
			label: 'Case type',
			reference: { schema: 'caseType', matchField: 'identifier', labelField: 'title' },
		})
		expect(nl['Case type']).toBe('Zaaktype')
	})
})
