/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeVelden draws the Fields tab as the fields editor and the field
 * rights only. The case type form, the raw terms and the case list columns
 * stay on the page, on a Properties tab under the tab strip's Actions menu,
 * and the written terms' Edit opens that tab.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const built = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', 'menu-layout.simple.json'))
const detail = built.pages.find((item) => item.id === 'CaseTypeDetail')
const widget = (id) => detail.config.widgets.find((item) => item.id === id)
const tabs = widget('case-type-panels').content.tabs
const tab = (id) => tabs.find((item) => item.id === id)
const idsOf = (entry) => entry.widgetIds || [entry.widgetId]

describe('the case type Fields tab (DqZaaktypeVelden)', () => {
	it('holds the fields editor and the field rights only', () => {
		expect(idsOf(tab('fields'))).toEqual(['case-type-fields-editor', 'case-type-field-rules'])
	})

	it('keeps the case type form, the raw terms, the collection settings and the list columns on an overflow Properties tab', () => {
		expect(tab('properties')).toMatchObject({ label: 'Properties', overflow: true })
		expect(idsOf(tab('properties'))).toEqual(['case-type-core', 'case-type-terms', 'case-type-collection', 'case-type-list-layout'])
	})

	it('opens the Properties tab from the written terms\' Edit', () => {
		expect(widget('case-type-terms-written').headerActions[0].action.target).toBe('/settings/case-types/@objectId#properties')
	})

	it('draws the versions and data cards beside the Properties tab', () => {
		const beside = detail.config.sideColumn.filter((card) => (card.tabs || []).includes('properties')).map((card) => card.id)
		expect(beside).toEqual(['side-case-type-versions', 'side-case-type-facts'])
	})
})
