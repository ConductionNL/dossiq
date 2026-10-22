/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The team queue opens on the reader's own team (board DqTeamwachtrij, "Team:
 * Woo en informatie"): its view filters on `@myTeam`, and dossiq answers the
 * library which group that is from GET /api/me/team.
 *
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import manifest from '../../src/manifest.json'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/index.php${url}` }))

const { fetchMyTeam, installMyTeamSource } = await import('../../src/services/myTeamSource.js')

beforeEach(() => get.mockReset())

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

	it('answers null for a reader in no team', async () => {
		get.mockResolvedValue({ data: { groupId: null, name: null } })
		await expect(fetchMyTeam()).resolves.toBeNull()
	})

	it('registers with the library when it offers the hook, and skips it otherwise', () => {
		const register = vi.fn()
		expect(installMyTeamSource(register)).toBe(true)
		expect(register).toHaveBeenCalledWith(fetchMyTeam)
		expect(installMyTeamSource(undefined)).toBe(false)
	})
})
