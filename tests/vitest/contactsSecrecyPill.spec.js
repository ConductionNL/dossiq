/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqContacten draws "Geheimhouding" beside the BSN of a resident whose
 * data is protected.
 *
 * @spec openspec/changes/contacts-secrecy-pill/specs/party-pages/spec.md#requirement-the-contacts-list-marks-a-protected-resident-beside-the-bsn
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const contacts = read('src/manifest.json').pages.find((page) => page.id === 'Contacts' && page.config.schema === 'brpPerson').config
const nl = read('l10n/nl.json').translations

describe('contacts list secrecy pill (DqContacten)', () => {
	it('puts "Geheimhouding" beside the BSN of a protected resident', () => {
		const bsn = contacts.columns.find((column) => column.key === 'citizenServiceNumber')
		expect(bsn.pills).toEqual([{ field: 'indicatieGeheim', equals: true, label: 'Data protection', variant: 'warning' }])
		expect(nl['Data protection']).toBe('Geheimhouding')
	})
})
