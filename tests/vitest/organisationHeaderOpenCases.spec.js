/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqOrganisatie names the open cases in the header line, "· 1 lopende
 * zaak", not as a pill beside the legal form (DqContact keeps its pill).
 *
 * @spec openspec/changes/organisation-header-open-cases/specs/party-pages/spec.md#requirement-the-organisation-header-line-counts-its-open-cases
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.json').pages.find((page) => page.id === 'OrganisationDetail').config
const nl = read('l10n/nl.json').translations

describe('organisation header open cases (DqOrganisatie)', () => {
	it('counts the open cases the organisation requested', () => {
		expect(config.headerMetaCounts).toEqual({ open: { register: 'dossiq', schema: 'case', filter: { requester: '@objectId', isFinalStatus: false } } })
	})

	it('names them in the singular or plural, and nothing for none', () => {
		expect(config.headerMeta.count).toBe('open')
		expect(config.headerMeta.zero).toBe('')
		expect(nl[config.headerMeta.one]).toBe('{count:open} lopende zaak')
		expect(nl[config.headerMeta.other]).toBe('{count:open} lopende zaken')
	})

	it('draws no open-case pill any more', () => {
		expect(config.statusPill).toBeUndefined()
	})
})
