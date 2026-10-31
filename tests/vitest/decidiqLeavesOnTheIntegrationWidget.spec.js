// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * decidiq's two leaves on dossiq records go through the library integration
 * widget: the decisions on a case as a tab of the case page, the approval
 * chain on a document in its metadata dialog. Both said "unavailable" when
 * decidiq is absent and mounted a mount-mode leaf with the record context and
 * `surface: sidebar-tab`; the library does both now, so dossiq keeps no
 * wrapper of its own. The behaviour itself is tested in nextcloud-vue
 * (CnIntegrationWidgetMount.spec.js).
 *
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

const ROOT = path.resolve(__dirname, '../..')
const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const dialog = fs.readFileSync(path.join(ROOT, 'src/modals/DocumentMetadataDialog.vue'), 'utf8')

describe('the decisions tab of the case page', () => {
	it('is the library integration widget on the decisions leaf, naming decidiq when it is absent', () => {
		const tab = panels.caseWidget('case-decisions-panel')
		expect(tab).toMatchObject({
			type: 'integration',
			integrationId: 'decidesk-decisions',
			appName: 'Decidiq',
			props: { surface: 'sidebar-tab' },
			title: 'Decisions',
		})
	})

	it('has no dossiq wrapper or registry key left', () => {
		expect(registry).not.toMatch(/^\t'case-decisions-pane': \{/m)
		expect(registry).not.toMatch(/^\tBesluitvormingLeafTab: \{/m)
		expect(fs.existsSync(path.join(ROOT, 'src/components/tabs/BesluitvormingLeafTab.vue'))).toBe(false)
	})
})

describe('the approval chain in the document dialog', () => {
	it('mounts the approval-chain leaf through CnIntegrationWidget with the document context', () => {
		const mountTag = dialog.slice(dialog.indexOf('<CnIntegrationWidget'), dialog.indexOf('/>', dialog.indexOf('<CnIntegrationWidget')))
		expect(mountTag).toContain('only="decidiq-approval-chain"')
		expect(mountTag).toContain('leafSurface="sidebar-tab"')
		expect(mountTag).toContain('register="dossiq"')
		expect(mountTag).toContain('schema="informatieobject"')
		expect(mountTag).toContain(':objectId="recordId"')
		expect(mountTag).toContain(':objectLabel="title"')
		expect(mountTag).toContain('appName="Decidiq"')
	})

	it('has no dossiq wrapper left', () => {
		expect(dialog).not.toContain('ApprovalChainLeafTab')
		expect(fs.existsSync(path.join(ROOT, 'src/components/tabs/ApprovalChainLeafTab.vue'))).toBe(false)
	})
})
