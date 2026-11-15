/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTeamwachtrij: the view switch draws the board's four views, list first,
 * the table under More views (Ruben, 23 Oct: users keep features), and the
 * line under the title names the reader's team ("· team Woo en
 * informatie"), left out for a reader in no team. Needs nextcloud-vue
 * view-switch-order-and-more and count-line-my-team-name.
 *
 * @spec openspec/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/index.php${url}` }))

const { fetchMyTeamName, installMyTeamNameSource } = await import('../../src/services/myTeamSource.js')

const queue = manifest.pages.find((page) => page.id === 'Cases' && page.route === '/cases').config.defaultSavedViews.find((view) => view.slug === 'team-queue').overrides

beforeEach(() => get.mockReset())

describe('the team queue view switch', () => {
	it('draws list, cards, board and map, and keeps the table', () => {
		expect(queue.viewSwitch).toEqual(['list', 'cards', 'board', 'map'])
		expect(queue.viewModes).toContain('table')
	})
})

describe('the team queue line names the team', () => {
	it('carries the team as an optional part', () => {
		expect(queue.countText).toBe('{shown} of {total} open cases without a handler[ · team {myTeam}] · oldest first')
		expect(nl.translations[queue.countText]).toBe('{shown} van {total} open zaken zonder behandelaar[ · team {myTeam}] · oudste eerst')
	})

	it('reads the team name from /api/me/team', async () => {
		get.mockResolvedValue({ data: { groupId: 'zuiddrecht-woo-en-informatie', name: 'Woo en informatie' } })
		await expect(fetchMyTeamName()).resolves.toBe('Woo en informatie')
		get.mockResolvedValue({ data: { groupId: null, name: null } })
		await expect(fetchMyTeamName()).resolves.toBeNull()
	})

	it('registers the name loader when the library offers the hook', () => {
		const register = vi.fn()
		expect(installMyTeamNameSource(register)).toBe(true)
		expect(register).toHaveBeenCalledWith(fetchMyTeamName)
		expect(installMyTeamNameSource(undefined)).toBe(false)
	})
})
