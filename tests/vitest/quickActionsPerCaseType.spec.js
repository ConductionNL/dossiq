// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Quick actions per case type. The case page reads the case type's
 * `quickActions` through `quickActionsFrom`: a case type that lists nothing
 * keeps the page's three (message, document, contact); one that lists ids of
 * the page's header actions gets those. The library only takes declared
 * header actions, so a case type picks, it never defines.
 *
 * @spec openspec/changes/quick-actions-per-case-type/specs/case-page/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const simple = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((page) => page.id === 'CaseDetail').config
const fragment = readJson('lib', 'Settings', 'register.d', '99-case-type-quick-actions.json')

describe('quick actions per case type', () => {
	it('reads them from the case type', () => {
		expect(simple.quickActionsFrom).toBe('@context.caseType.quickActions')
	})

	it('keeps the page\'s three for a case type that lists none', () => {
		const ids = simple.quickActions.map((entry) => (typeof entry === 'string' ? entry : entry.id))
		expect(ids).toEqual(['send-digital-post', 'generate-document', 'log-contact'])
	})

	it('declares the key on the case type as a list of action ids', () => {
		const property = fragment.components.schemas.caseType.properties.quickActions
		expect(property.type).toBe('array')
		expect(property.items).toEqual({ type: 'string' })
	})

	it('takes a case type version of its own, which the fragment merger keeps when it is the highest', () => {
		// The version register (one number per change) and RegisterFragmentMerger keeping the highest
		// x.y.z replace the old rule that the newest fragment sorts last.
		const versions = fs.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
			.filter((name) => name.endsWith('.json') && name !== '99-case-type-quick-actions.json')
			.map((name) => readJson('lib', 'Settings', 'register.d', name).components?.schemas?.caseType?.version)
			.filter(Boolean)
		expect(versions).not.toContain(fragment.components.schemas.caseType.version)
	})
})
