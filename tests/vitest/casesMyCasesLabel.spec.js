/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A view on the reader's own cases reads "Mijn zaken" in its summary
 * (board DqPersoonlijkeInstellingen), through nc-vue's filterLabels
 * `valuesAlone`.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-view-on-my-own-cases-reads-mijn-zaken
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

describe('the cases page names @me', () => {
	it('labels assignee @me "My cases" on its own', () => {
		const assignee = manifest.pages.find((page) => page.id === 'Cases').config.filterLabels.assignee
		expect(assignee.values['@me']).toBe('My cases')
		expect(assignee.valuesAlone).toBe(true)
		expect(nl['My cases']).toBe('Mijn zaken')
	})
})
