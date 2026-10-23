/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The VTH tab's section headers carry the board's actions (DqVthInstellingen),
 * each wired to something that exists: integriq's admin settings link a DSO
 * activity, shillinq's fee schedules add a fee rule, and the checklist editor
 * opens a new checklist.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(root, 'src/views/settings/AdminRoot.vue'), 'utf8')
const checklists = fs.readFileSync(path.join(root, 'src/views/settings/tabs/ChecklistTemplatesTab.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations

describe('the VTH section header actions', () => {
	it('links a DSO activity on integriq\'s admin settings', () => {
		expect(admin).toMatch(/<NcButton :href="integriqAdminUrl"[^>]*>[\s\S]*?'Link activity'/)
		expect(admin).toContain("generateUrl('/settings/admin/integriq')")
		expect(nl['Link activity']).toBe('Activiteit koppelen')
	})

	it('adds a fee rule on shillinq\'s fee schedules', () => {
		expect(admin).toMatch(/<NcButton :href="feeSchedulesUrl"[^>]*>[\s\S]*?'Add rule'/)
		expect(admin).toContain("generateUrl('/apps/shillinq/overheid/fee-schedules')")
		expect(nl['Add rule']).toBe('Regel toevoegen')
	})

	it('opens the checklist editor from the header, and the tab leaves its own button out', () => {
		expect(admin).toContain('$refs.checklists.openEditor(null)')
		expect(admin).toContain('<ChecklistTemplatesTab v-if="storesReady" ref="checklists" :hideAddButton="true" />')
		expect(checklists).toContain('<div v-if="!hideAddButton" class="checklists-tab__header">')
		expect(nl['Add checklist']).toBe('Checklist toevoegen')
	})

	it('shows an owner app\'s action only when that app is installed', () => {
		expect(admin).toContain('<template v-if="ownerApps.integriq" #actions>')
		expect(admin).toContain('<template v-if="ownerApps.shillinq" #actions>')
	})

	it('draws the actions in the section title row, as the board does (nextcloud-vue actionsInTitle)', () => {
		for (const id of ['section-intake-activities', 'section-fee-schedules']) {
			expect(admin).toMatch(new RegExp(`id="${id}"\\s+:actionsInTitle="true"`))
		}
		expect(admin).toMatch(/:actionsInTitle="true"\s+:name="t\('dossiq', 'Inspection checklists'\)"/)
	})

	it('puts Add day in the working days title row and leaves the calendar\'s own button out', () => {
		expect(admin).toMatch(/id="section-working-days"\s+:actionsInTitle="true"/)
		expect(admin).toContain('@click="$refs.workingCalendar.openAdd()"')
		expect(admin).toContain('<CnWorkingCalendarWidget ref="workingCalendar" :content="workingCalendarContent" />')
		expect(admin).toMatch(/addButton: false,/)
		expect(nl['Add day']).toBe('Dag toevoegen')
	})

	it('ends each owned list with a link to the app that manages it, as DqVthInstellingen does', () => {
		// "Koppelingen beheren in integriq" and "Leges beheren in shillinq": a
		// section footer link (nextcloud-vue footerLink), only with the app there.
		expect(admin).toMatch(/:footerLink="ownerApps\.integriq \? \{ label: t\('dossiq', 'Manage the links in integriq'\), href: integriqAdminUrl \} : null"/)
		expect(admin).toMatch(/:footerLink="ownerApps\.shillinq \? \{ label: t\('dossiq', 'Manage fees in shillinq'\), href: feeSchedulesUrl \} : null"/)
		expect(nl['Manage the links in integriq']).toBe('Koppelingen beheren in integriq')
		expect(nl['Manage fees in shillinq']).toBe('Leges beheren in shillinq')
	})

	it('words the VTH sections as the board does', () => {
		// The owning app is named by the footer link, not in the description.
		expect(admin).toContain("'An application from the DSO becomes a case of the linked case type.'")
		expect(admin).toContain("'Loaded at installation; adjusted per year.'")
		expect(nl['An application from the DSO becomes a case of the linked case type.']).toBe('Een aanvraag uit het DSO wordt een zaak van het gekoppelde zaaktype')
		expect(nl['Loaded at installation; adjusted per year.']).toBe('Ingeladen bij de installatie; aan te passen per jaar')
		expect(nl['Inspection checklists']).toBe('Inspectiechecklists')
		const checklistSection = admin.slice(admin.indexOf("'Inspection checklists'"), admin.indexOf('<ChecklistTemplatesTab'))
		expect(checklistSection).not.toContain(':description=')
	})
})
