/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact and organisation pages on the shared relation-page shape
 * (nextcloud-vue screens-relation-pages; boards DqContact, DqOrganisatie).
 *
 * What would fail silently: an open-case count filtered on a value no case
 * carries (status is a uuid, so "open" is `isFinalStatus: false`), a feed icon
 * outside src/icons.js (renders nothing), a BSN shown unmasked in a second
 * card after the side card masks it, and an Overview tab that names one widget
 * instead of the stack.
 *
 * @spec openspec/specs/initiator-display/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const iconsSource = read('src', 'icons.js')
const caseSchema = JSON.parse(read('lib', 'Settings', 'dossiq_register.json'))
	.components.schemas.case

const page = (id) => manifest.pages.find((p) => p.id === id)
const widget = (p, id) => p.config.widgets.find((w) => w.id === id)

describe.each(['ContactDetail', 'OrganisationDetail'])(
	'%s on the relation-page shape',
	(id) => {
		const p = page(id)

		it('stacks the card, the cases, the tickets and the latest contact moments on Overview (board order)', () => {
			const tabs = widget(p, 'contact-panels').content.tabs
			expect(tabs[0]).toEqual({
				widgetIds: [
					'contact-card',
					'contact-open-cases',
					'contact-open-tickets',
					'contact-latest-moments',
				],
				label: 'Overview',
			})
			for (const wid of tabs[0].widgetIds) {
				expect(widget(p, wid)).toBeTruthy()
			}
		})

		it('counts open cases on a field the case carries', () => {
			// The contact page draws the count as a pill; the organisation page
			// names it in the header line (DqOrganisatie "· 1 lopende zaak").
			const filter = p.config.statusPill
				? p.config.statusPill.countFrom.filter
				: p.config.headerMetaCounts.open.filter
			expect(filter).toEqual({
				requester: '@objectId',
				isFinalStatus: false,
			})
			expect(caseSchema.properties.isFinalStatus.type).toBe('boolean')
			const one = p.config.statusPill ? p.config.statusPill.countLabel.one : p.config.headerMeta.one
			expect(one).toMatch(/\{count(:open)?\}/)
		})

		it('draws the related lists through record-list', () => {
			expect(widget(p, 'contact-open-cases').type).toBe('record-list')
			expect(widget(p, 'contact-latest-moments').type).toBe('record-list')
		})

		it('names only registered icons in the contact moment feed', () => {
			const icon = widget(p, 'contact-latest-moments').content.icon
			for (const name of [...Object.values(icon.map), icon.default]) {
				expect(iconsSource).toMatch(new RegExp(`\\b${name}\\b`))
			}
		})

		it('puts the facts in the side column as facts cards', () => {
			const types = p.config.sideColumn.map((w) => w.type)
			expect(types).toContain('facts')
			expect(types).not.toContain('data')
		})
	},
)

describe('ContactDetail and the BSN', () => {
	const p = page('ContactDetail')

	it('masks the BSN in the side card and leaves it out of the overview card', () => {
		const identity = p.config.sideColumn.find((w) => w.id === 'side-identity')
		const bsn = identity.content.fields.find(
			(f) => f.path === 'citizenServiceNumber',
		)
		expect(bsn.format).toBe('masked')
		expect(widget(p, 'contact-card').content.fields.map((f) => f.path)).not.toContain(
			'citizenServiceNumber',
		)
	})
})
