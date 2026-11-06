/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqFlow draws "Testen met een zaak": the flow page declares cases as
 * the records a flow is tried with. The library tries the flow through
 * OpenRegister's dry-run, which changes and sends nothing.
 *
 * @spec openspec/specs/flow-test-with-a-case/spec.md#requirement-the-flow-page-tests-a-flow-with-a-case
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const page = manifest.pages.find((p) => p.id === 'FlowDetail')

describe('the flow page test button', () => {
	it('tries a flow with a case, named as the board names it', () => {
		expect(page.config.testSubject).toEqual({ register: 'dossiq', schema: 'case', labelField: 'title', label: 'Test with a case' })
		expect(nl['Test with a case']).toBe('Testen met een zaak')
	})
})
