/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What dossiq takes from @conduction/nextcloud-vue 2.76.0, read from the
 * build the bundle ships (`dist/esm`), not from the library's `src`.
 *
 * On 2.73.1 and 2.74.0 the case detail page sent its lock request to
 * `/api/objects/()=>.../()=>.../()=>.../lock`: CnDetailPage passes getter
 * functions and `useObjectLock` read them with `unref`, which hands a
 * function back untouched. OpenRegister answered 404 and no case was ever
 * locked. The settings dialog's Credentials text also carried em-dashes.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { post: (...args) => post(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => `/index.php${url}`,
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'admin' }),
}))

const LIBRARY = path.resolve(
	__dirname,
	'../../node_modules/@conduction/nextcloud-vue/dist/esm',
)

describe('the case lock request (nextcloud-vue ^2.76.0)', () => {
	beforeEach(() => {
		post.mockReset()
		post.mockResolvedValue({ data: {} })
	})

	it('puts the real register, schema and id in the URL when the page passes getters', async () => {
		const { useObjectLock } = await import(
			path.join(LIBRARY, 'composables', 'useObjectLock.js')
		)
		const objectStore = { objects: {}, fetchObject: vi.fn() }
		const lock = useObjectLock(
			objectStore,
			() => 'dossiq',
			() => 'dossiq-case',
			() => 'b3a1c2d4-0000-4000-8000-000000000001',
			{ schemaSlug: () => 'case', autoRenew: false },
		)

		await lock.acquire()

		expect(post).toHaveBeenCalledTimes(1)
		const url = post.mock.calls[0][0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/objects/dossiq/case/b3a1c2d4-0000-4000-8000-000000000001/lock',
		)
		expect(url).not.toContain('=>')
	})
})

describe('the Credentials text in the settings dialog (nextcloud-vue ^2.76.0)', () => {
	// The build calls `translate('nextcloud-vue', '...')`; the source calls `t(...)`.
	const source = fs.readFileSync(
		path.join(LIBRARY, 'components', 'CnCredentials', 'CnCredentials.vue2.js'),
		'utf8',
	)
	const strings = [
		...source.matchAll(
			/\b(?:t|translate)\(\s*'nextcloud-vue'\s*,\s*'((?:\\.|[^'\\])*)'/g,
		),
	].map((match) => match[1].replace(/\\'/g, "'"))

	it('has no em-dashes in any English string it translates', () => {
		expect(strings.length).toBeGreaterThan(0)
		expect(strings.filter((text) => text.includes('\u2014'))).toEqual([])
	})

	it('has no em-dashes in the Dutch text of those strings', async () => {
		const { translations } = await import(
			path.join(LIBRARY, 'l10n', 'nl.json.js')
		)
		const dutch = strings
			.map((text) => translations[text])
			.filter((text) => typeof text === 'string')

		expect(dutch.length).toBeGreaterThan(0)
		expect(dutch.filter((text) => text.includes('\u2014'))).toEqual([])
	})
})
