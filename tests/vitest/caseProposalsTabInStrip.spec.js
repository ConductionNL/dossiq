/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PtVoorstelBeoordelen: while proposals wait on a case, the Proposals tab
 * is there with their count. It asks portaliq's queue route about this case, so
 * it is absent without waiting proposals, without the right to review them and
 * without portaliq. The strip keeps the five tabs of board DqZaak, so with five
 * places Proposals is listed under More.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

function caseTabs() {
	const manifest = buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', 'menu-layout.simple.json'),
	)
	const page = manifest.pages.find((p) => p.id === 'CaseDetail')
	return page.config.widgets.find((w) => w.id === 'case-panels').content
}

const QUEUE = '/apps/portaliq/api/proposals?register=dossiq&schema=case&id=@objectId'

describe('the Proposals tab on the simple case page', () => {
	const content = caseTabs()
	const labels = content.tabs.map((tab) => tab.label)
	const proposals = content.tabs.find((tab) => tab.widgetId === 'case-change-proposals')

	it('comes after the five tabs of DqZaak, which keep their places', () => {
		const always = content.tabs.filter((tab) => !tab.visibleWhen && !tab.overflow).map((tab) => tab.label)
		expect(always).toEqual(['Overview', 'Files', 'Contact', 'Tasks', 'Case history'])
		expect(content.maxVisibleTabs).toBe(always.length)
		expect(labels.indexOf('Proposals')).toBe(labels.indexOf('Case history') + 1)
	})

	it('is not pinned under More: the five places decide where it goes', () => {
		expect(proposals.overflow).toBeUndefined()
	})

	it('shows only while portaliq has proposals waiting on this case', () => {
		expect(proposals.visibleWhen).toEqual({ appInstalled: 'portaliq', endpoint: QUEUE, field: 'proposals', op: 'notEmpty' })
	})

	it('counts the waiting proposals from the same route', () => {
		expect(proposals.countFrom).toEqual({ endpoint: QUEUE, field: 'proposals' })
	})
})
