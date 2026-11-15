/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakBesluiten "Adviezen" and DqTenant "Mandaten" draw a muted line under
 * the card title, which puts the title at the top of the card head beside its
 * button; the card's subtitle carries it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const caseCard = (id) => read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail').configAppend.widgets.find((widget) => widget.id === id)
const organisationCard = (id) => read('src/manifest.d/67-environment-organisations.json').pages.flatMap((page) => page.config?.widgets || []).find((widget) => widget.id === id)

describe('the advice and mandates cards\' subtitles', () => {
	it('Adviezen reads the board\'s line', () => {
		expect(nl[caseCard('case-advice-requests').subtitle]).toBe('Interne en externe adviezen op deze zaak')
	})

	it('Mandaten reads the board\'s line', () => {
		expect(nl[organisationCard('organisation-mandates').subtitle]).toBe('Per organisatie; dossiq toetst ze bij elk besluit')
	})
})
