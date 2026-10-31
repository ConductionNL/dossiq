/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The team queue opens on the reader's own team (board DqTeamwachtrij, "Team:
 * Woo en informatie"): its view filters on `@myTeam`, and dossiq answers the
 * library which group that is from GET /api/me/team.
 *
 * @spec openspec/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import manifest from '../../src/manifest.json'
import simple from '../../src/menu-layout.simple.json'

const get = vi.fn()
// A failed request is a plain function, not a vi.fn result: vitest reports a rejected mock result as a test error.
let failWith = null
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => (failWith ? Promise.reject(failWith) : get(...args)) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/index.php${url}` }))

const { fetchMyTeam, fetchMyTeamName, installMyTeamSource } = await import('../../src/services/myTeamSource.js')

beforeEach(() => {
	get.mockReset()
	failWith = null
})

describe('the team queue', () => {
	it('filters on the reader\'s team', () => {
		const views = manifest.pages.find((page) => page.id === 'Cases' && page.route === '/cases').config.defaultSavedViews
		expect(views.find((view) => view.slug === 'team-queue').filters).toEqual({ assignedGroup: '@myTeam' })
	})
})

describe('the team source', () => {
	it('answers the team group from /api/me/team', async () => {
		get.mockResolvedValue({ data: { groupId: 'zuiddrecht-woo-en-informatie', name: 'Informatie en Woo' } })
		await expect(fetchMyTeam()).resolves.toBe('zuiddrecht-woo-en-informatie')
		expect(get).toHaveBeenCalledWith('/index.php/apps/dossiq/api/me/team')
	})

	it('answers every team of a reader in several, the primary first (coordinator 24 Oct)', async () => {
		get.mockResolvedValue({ data: { groupId: 'woo', name: 'Woo', groupIds: ['woo', 'vergunningen'], names: ['Woo', 'Vergunningen'] } })
		await expect(fetchMyTeam()).resolves.toEqual(['woo', 'vergunningen'])
		get.mockResolvedValue({ data: { groupId: 'woo', name: 'Woo', groupIds: ['woo'], names: ['Woo'] } })
		await expect(fetchMyTeam()).resolves.toBe('woo')
	})

	it('names every team in the count line', async () => {
		get.mockResolvedValue({ data: { groupId: 'woo', name: 'Woo en informatie', groupIds: ['woo', 'vergunningen'], names: ['Woo en informatie', 'Vergunningen'] } })
		await expect(fetchMyTeamName()).resolves.toBe('Woo en informatie, Vergunningen')
	})

	it('counts the menu badge on the same teams as the list', () => {
		const queue = simple.menu.find((item) => item.id === 'Queue')
		expect(queue.count.filter.assignedGroup).toBe('@myTeam')
	})

	it('answers null for a reader in no team', async () => {
		get.mockResolvedValue({ data: { groupId: null, name: null } })
		await expect(fetchMyTeam()).resolves.toBeNull()
	})

	it('answers null when the server cannot read the team', async () => {
		failWith = new Error('Request failed with status code 503')
		await expect(fetchMyTeam()).resolves.toBeNull()
	})

	it('registers with the library when it offers the hook, and skips it otherwise', () => {
		const register = vi.fn()
		expect(installMyTeamSource(register)).toBe(true)
		expect(register).toHaveBeenCalledWith(fetchMyTeam)
		expect(installMyTeamSource(undefined)).toBe(false)
	})
})
