/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards DqContact and DqOrganisatie draw a finished case's pill in green
 * ("Afgehandeld"), an open one in purple, whatever the case type calls its
 * final status.
 *
 * @spec openspec/changes/party-cases-final-green/specs/party-pages/spec.md#requirement-a-finished-cases-pill-is-green
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages

describe.each(['ContactDetail', 'OrganisationDetail'])('%s Zaken card', (pageId) => {
	it('colours a finished case green', () => {
		const pill = pages.find((page) => page.id === pageId).config.widgets.find((widget) => widget.id === 'contact-open-cases').content.pill
		expect(pill.variant).toBe('purple')
		expect(pill.variantWhen).toEqual([{ when: { field: 'isFinalStatus', op: 'eq', value: true }, variant: 'success' }])
	})
})
