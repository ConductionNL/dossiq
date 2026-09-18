// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A party's own correspondence on the People tab, and the two columns the
 * Files tab declares for it.
 *
 * 🔴 A PARTY NOBODY CORRESPONDED WITH GETS NO LINE, not "0 documents". An
 * empty count on every row is noise on the tab whose job is to say who is on
 * the case, and it is the shape a naive `${sent.length} sent` produces on
 * every party of every case in the fleet.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CasePartiesWidget from '../../src/components/case/CasePartiesWidget.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

const LISTING = {
	primary: 'party-jan',
	roles: [
		{ key: 'aanvrager', label: 'Requester' },
		{ key: 'belanghebbende', label: 'Interested party' },
	],
	kinds: [{ key: 'person', label: 'Person' }],
	byRole: {
		aanvrager: [{ partyUuid: 'party-jan', displayName: 'Jan Jansen' }],
		belanghebbende: [
			{ partyUuid: 'party-council', displayName: 'Gemeente Utrecht' },
			{ partyUuid: 'party-quiet', displayName: 'Stille Buur' },
		],
	},
	results: [
		{ partyUuid: 'party-jan', displayName: 'Jan Jansen' },
		{ partyUuid: 'party-council', displayName: 'Gemeente Utrecht' },
		{ partyUuid: 'party-quiet', displayName: 'Stille Buur' },
	],
}

const DOCUMENTS = [
	{ id: 'doc-a', sender: 'party-jan', recipients: [] },
	{ id: 'doc-b', sender: 'party-jan', recipients: ['party-council'] },
	{ id: 'doc-c', sender: '', recipients: ['party-jan'] },
]

/**
 * The widget over a case with three parties and three documents.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountWidget() {
	axios.get.mockImplementation((url) => {
		const asked = String(url)
		if (asked.includes('/parties')) {
			return Promise.resolve({ data: LISTING })
		}
		if (asked.includes('/dossier')) {
			return Promise.resolve({ data: { informatieobjecten: DOCUMENTS } })
		}
		return Promise.resolve({ data: { indicators: [] } })
	})

	const wrapper = mount(CasePartiesWidget, {
		props: { objectId: 'case-1' },
		global: {
			mocks: { $route: { params: { id: 'case-1' } } },
			stubs: {
				NcEmptyContent: true,
				NcLoadingIcon: true,
				NcNoteCard: { template: '<div><slot /></div>' },
			},
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	await wrapper.vm.$nextTick()
	return wrapper
}

describe("a party's documents on the People tab", () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('counts what a party sent and what it received, separately', async () => {
		const wrapper = await mountWidget()

		// Jan sent two and received one, so the line names both counts. The
		// numbers are what is asserted: a line saying only "sent" on a party
		// who also received would be the bug this separates.
		expect(wrapper.vm.correspondenceOf({ partyUuid: 'party-jan' })).toBe(
			'2 sent, 1 received',
		)
		// One direction only, so the line is the plural form and nothing else.
		// The count is unsubstituted here because no l10n bundle is loaded in
		// jsdom; the WORD is what this asserts, and it is the received one.
		expect(wrapper.vm.correspondenceOf({ partyUuid: 'party-council' })).toMatch(
			/received$/,
		)
	})

	it('gives a party nobody corresponded with no line at all', async () => {
		const wrapper = await mountWidget()

		expect(wrapper.vm.correspondenceOf({ partyUuid: 'party-quiet' })).toBe('')
		const lines = wrapper.findAll('[data-testid="case-parties-documents"]')
		expect(lines).toHaveLength(2)
	})

	it('reads the dossier once for the whole tab, not once per party', async () => {
		await mountWidget()

		const dossierReads = axios.get.mock.calls.filter(([url]) =>
			String(url).includes('/dossier'),
		)
		expect(dossierReads).toHaveLength(1)
	})

	it('drops the line rather than claiming nobody wrote when the read fails', async () => {
		axios.get.mockImplementation((url) => {
			const asked = String(url)
			if (asked.includes('/parties')) {
				return Promise.resolve({ data: LISTING })
			}
			if (asked.includes('/dossier')) {
				return Promise.reject(new Error('OpenRegister said no'))
			}
			return Promise.resolve({ data: { indicators: [] } })
		})

		const wrapper = mount(CasePartiesWidget, {
			props: { objectId: 'case-1' },
			global: {
				mocks: { $route: { params: { id: 'case-1' } } },
				stubs: {
					NcEmptyContent: true,
					NcLoadingIcon: true,
					NcNoteCard: { template: '<div><slot /></div>' },
				},
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('[data-testid="case-parties-documents"]')).toHaveLength(
			0,
		)
	})
})

describe('the Files tab declares the two columns', () => {
	const manifest = JSON.parse(
		fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'),
	)

	/**
	 * The Files tab of the case page.
	 *
	 * @return {object} The widget entry.
	 */
	function filesTab() {
		const panels = require('./helpers/casePanels.js')
		return panels.caseWidget('case-files')
	}

	it('binds each column to a property the dossier listing answers', () => {
		const columns = filesTab().props.columns

		// 🔴 The property, not the label. A column bound to a key nothing
		// answers renders blank forever and raises nothing anywhere: the
		// listing carries senderName and recipientNames, and those two names
		// are what this asserts.
		expect(columns.map((column) => column.property)).toEqual([
			'senderName',
			'recipientNames',
		])
	})

	it('labels them in sentence case with no em-dash', () => {
		const labels = filesTab().props.columns.map((column) => column.label)

		expect(labels).toEqual(['Sender', 'Recipients'])
		for (const label of labels) {
			expect(label).not.toMatch(/—|--/)
		}
	})

	it('is the manifest on disk and not a copy of it', () => {
		expect(manifest.pages.some((page) => page.id === 'CaseDetail')).toBe(true)
	})
})
