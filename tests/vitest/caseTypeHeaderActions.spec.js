// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type declares the header row of its cases. The case page reads the
 * case type's `headerActions` before its `quickActions` through
 * `quickActionsFrom`, and its `primaryAction` through `primaryActionFrom`.
 * nextcloud-vue keeps only ids the page declares, so a case type orders and
 * promotes header actions and never defines or widens one.
 *
 * @spec openspec/changes/case-type-declares-its-header-actions/specs/case-type-header-actions/spec.md#requirement-a-case-type-declares-the-header-row-of-its-cases
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
const caseDetail = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((page) => page.id === 'CaseDetail').config
const caseType = readJson('lib', 'Settings', 'register.d', '90-case-type-header-actions.json').components.schemas.caseType.properties

describe('a case type declares the header row of its cases', () => {
	it('reads the case type\'s header actions first, then its quick actions', () => {
		expect(caseDetail.quickActionsFrom).toEqual(['@context.caseType.headerActions', '@context.caseType.quickActions'])
	})

	it('reads the primary button from the case type', () => {
		expect(caseDetail.primaryActionFrom).toBe('@context.caseType.primaryAction')
	})

	it('resolves @context.caseType from the case\'s own case type', () => {
		expect(caseDetail.visibilityContext.caseType).toMatchObject({ field: 'caseType', schema: 'caseType' })
	})

	it('keeps the page\'s own row for a case type that declares nothing', () => {
		const ids = caseDetail.quickActions.map((entry) => (typeof entry === 'string' ? entry : entry.id))
		expect(ids).toEqual(['send-digital-post', 'generate-document', 'log-contact'])
	})

	it('reads keys the case type schema declares, as ids', () => {
		expect(caseType.headerActions.items.type).toBe('string')
		expect(caseType.primaryAction.type).toBe('string')
	})
})
