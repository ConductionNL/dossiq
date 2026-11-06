/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqContact writes the date of birth with the month in full ("14 maart
 * 1988"); board DqSociaalDomein draws the side history as stacked rows, the
 * moment and who under the action.
 *
 * @spec openspec/changes/contact-birth-longdate-social-history-stacked/specs/party-pages/spec.md#requirement-the-date-of-birth-is-written-out-and-the-social-history-is-stacked
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))

describe('r30 DqContact and DqSociaalDomein', () => {
	it('writes the date of birth with the month in full', () => {
		const card = manifest.pages.find((page) => page.id === 'ContactDetail').config.widgets.find((widget) => widget.id === 'contact-card')
		expect(card.content.fields.find((field) => field.path === 'birth.date').format).toBe('longdate')
	})

	it('draws the social side history as stacked rows', () => {
		const side = layout.pages.find((page) => page.id === 'CaseDetail').config.sideColumn
		expect(side.find((entry) => entry && entry.id === 'side-social-history').content.layout).toBe('stacked')
	})
})
