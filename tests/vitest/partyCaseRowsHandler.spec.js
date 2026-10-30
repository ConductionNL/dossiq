/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie end a case row's meta line with the
 * handler: "2026-0110 · Woo-verzoek · behandelaar Pieter Jansen".
 *
 * @spec openspec/specs/party-pages/spec.md#requirement-a-party-case-row-names-its-handler
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

describe.each(['ContactDetail', 'OrganisationDetail'])('%s Zaken card', (pageId) => {
	it('reads number, case type and handler as parts', () => {
		const meta = pages.find((page) => page.id === pageId).config.widgets.find((widget) => widget.id === 'contact-open-cases').content.meta
		expect(meta.parts).toEqual(['{identifier}', '{caseType.title}', 'handler {assignee|user}'])
	})
})

describe('OrganisationDetail open case side card', () => {
	it('reads number and handler as parts', () => {
		const side = pages.find((page) => page.id === 'OrganisationDetail').config.sideColumn.find((widget) => widget.id === 'side-open-case')
		expect(side.content.meta.parts).toEqual(['{identifier}', 'handler {assignee|user}'])
	})
})

describe('Dutch label', () => {
	it('writes the handler part as "behandelaar"', () => {
		expect(nl['handler {assignee|user}']).toBe('behandelaar {assignee|user}')
	})
})
