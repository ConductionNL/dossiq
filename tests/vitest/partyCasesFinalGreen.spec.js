/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie draw a case's status pill in its status
 * type's colour: "Afgehandeld" green, "Besluitvorming" teal.
 *
 * @spec openspec/specs/party-pages/spec.md#requirement-a-case-pill-takes-its-status-types-colour
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages

describe.each(['ContactDetail', 'OrganisationDetail'])('%s Zaken card', (pageId) => {
	it('colours a case by its status type\'s colour', () => {
		const pill = pages.find((page) => page.id === pageId).config.widgets.find((widget) => widget.id === 'contact-open-cases').content.pill
		expect(pill.colorField).toBe('status.colour')
		expect(pill.variantWhen).toBeUndefined()
	})
})

describe('OrganisationDetail open case side card', () => {
	it('colours the open case by its status type\'s colour', () => {
		const side = pages.find((page) => page.id === 'OrganisationDetail').config.sideColumn.find((widget) => widget.id === 'side-open-case')
		expect(side.content.pill.colorField).toBe('status.colour')
	})
})
