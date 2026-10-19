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
const checklists = fs.readFileSync(path.join(root, 'src/views/settings/tabs/ChecklistsTab.vue'), 'utf8')
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
		expect(admin).toContain('<ChecklistsTab v-if="storesReady" ref="checklists" :hideAddButton="true" />')
		expect(checklists).toContain('<div v-if="!hideAddButton" class="checklists-tab__header">')
		expect(nl['Add checklist']).toBe('Checklist toevoegen')
	})

	it('shows an owner app\'s action only when that app is installed', () => {
		expect(admin).toContain('<template v-if="ownerApps.integriq" #actions>')
		expect(admin).toContain('<template v-if="ownerApps.shillinq" #actions>')
	})
})
