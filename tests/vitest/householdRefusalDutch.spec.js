/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The household endpoint's refusal sentence is a catalogue key with Dutch, so
 * the household card shows it in the reader's language (DqSociaalDomein).
 *
 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const controller = fs.readFileSync(path.join(ROOT, 'lib', 'Controller', 'HouseholdCasesController.php'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

describe('household refusal', () => {
	it('has Dutch for the sentence the controller answers', () => {
		const match = controller.match(/'message'\s*=>\s*'((?:[^'\\]|\\.)*)'/)
		expect(match).not.toBeNull()
		const sentence = match[1].replace(/\\'/g, '\'')
		expect(nl[sentence]).toBe('Alleen wie met de bijzondere persoonsgegevens van deze zaak werkt, ziet het huishouden.')
	})
})
