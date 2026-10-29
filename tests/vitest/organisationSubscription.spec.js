/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant: the "Historie" side card ends with the subscription's contract
 * reference ("Kenmerk TEN-0014"), and the Quota card says when another
 * package takes effect.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.d/67-environment-organisations.json').pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const nl = read('l10n/nl.json').translations

describe('organisation history side card (DqTenant)', () => {
	const history = config.sideColumn.find((widget) => widget.id === 'organisation-side-history')

	it('ends with the subscription reference, read from its own source', () => {
		const note = history.content.footnote
		expect(note.source.url).toBe('/apps/openregister/api/objects/dossiq/tenantSubscription')
		expect(note.source.params.tenantRef).toBe('@objectId')
		expect(note.source.responsePath).toBe('results.0.reference')
		expect(nl[note.text]).toBe('Kenmerk {value}')
	})
})

describe('organisation quota card (DqTenant)', () => {
	it('says when another package takes effect', () => {
		const quota = config.widgets.find((widget) => widget.id === 'organisation-quota')
		expect(nl[quota.content.footnote]).toBe('Een ander pakket gaat binnen een kwartier in.')
	})
})
