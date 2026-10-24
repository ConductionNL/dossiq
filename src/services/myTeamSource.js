/**
 * The current user's primary team, for the library's `@myTeam` filter token.
 *
 * The team queue (board DqTeamwachtrij) opens on `assignedGroup: '@myTeam'`.
 * Which of the user's groups is their team is dossiq's knowledge (the
 * organisation roles' `ncGroupId`), so dossiq answers it from GET
 * /api/me/team and hands the library that loader.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the current user's team.
 *
 * @return {Promise<string|null>} The team's Nextcloud group id, or null for no team or a
 *   team the server could not read (503; the server logs why).
 *
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team
 */
export async function fetchMyTeam() {
	let data
	try {
		({ data } = await axios.get(generateUrl('/apps/dossiq/api/me/team')))
	} catch {
		return null
	}
	return data && typeof data.groupId === 'string' && data.groupId !== '' ? data.groupId : null
}

/**
 * Read the current user's team name, for `{myTeam}` in the team queue's line
 * ("· team Woo en informatie", DqTeamwachtrij).
 *
 * @return {Promise<string|null>} The team's name, or null for no team or an unreadable team.
 *
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team
 */
export async function fetchMyTeamName() {
	let data
	try {
		({ data } = await axios.get(generateUrl('/apps/dossiq/api/me/team')))
	} catch {
		return null
	}
	return data && typeof data.name === 'string' && data.name !== '' ? data.name : null
}

/**
 * Hand the name loader to the library, when it offers the hook.
 *
 * @param {Function|undefined} register The library's `registerMyTeamNameSource`.
 * @return {boolean} Whether it was registered.
 *
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team
 */
export function installMyTeamNameSource(register) {
	if (typeof register !== 'function') {
		return false
	}
	register(fetchMyTeamName)
	return true
}

/**
 * Hand the loader to the library, when it offers the hook.
 *
 * @param {Function|undefined} register The library's `registerMyTeamSource`.
 * @return {boolean} Whether it was registered.
 *
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team
 */
export function installMyTeamSource(register) {
	if (typeof register !== 'function') {
		return false
	}
	register(fetchMyTeam)
	return true
}
