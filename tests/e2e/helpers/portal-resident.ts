/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The resident's half of a portal scenario, read through portaliq's own
 * portal API as the resident.
 *
 * The session comes from portaliq's dev-login, which a test instance switches
 * on (`portaliq/dev_login_enabled`, set by portaliq's ci-seed) and production
 * never does. Without portaliq, or with the switch off, `residentSession()`
 * answers null and the caller skips.
 */

import type { APIRequestContext } from '@playwright/test'

import { occRun } from './occ.ts'

/** portaliq's portal API. */
const PORTAL = '/index.php/apps/portaliq/portal/api'

/** A resident's portal session. */
export interface ResidentSession {
	subjectRef: string
	token: string
}

/**
 * Whether portaliq is enabled on the instance under test.
 *
 * @return True when `occ app:list` names portaliq as enabled.
 */
export async function portaliqEnabled(): Promise<boolean> {
	const apps = await occRun(['app:list', '--output=json'])
	if (apps.code !== 0) {
		return false
	}

	return 'portaliq' in (JSON.parse(apps.output).enabled ?? {})
}

/**
 * A portal session for one resident, or null when dev-login is not offered.
 *
 * @param api          Any request context on the instance.
 * @param subjectRef   The resident's portal subject reference.
 * @param organisation The tenant the session is for.
 * @return The session, or null.
 */
export async function residentSession(
	api: APIRequestContext,
	subjectRef: string,
	organisation = 'dev-org',
): Promise<ResidentSession | null> {
	const res = await api.post(`${PORTAL}/session/dev-login`, {
		headers: { 'OCS-APIRequest': 'true' },
		data: { subjectRef, audience: 'client', organisation },
	})
	if (res.status() !== 200) {
		return null
	}
	const body = await res.json()

	return typeof body?.token === 'string' ? { subjectRef, token: body.token } : null
}

/**
 * Read a portal API path as the resident.
 *
 * @param api     Any request context on the instance.
 * @param session The resident's session.
 * @param path    The path under `/portal/api`, starting with a slash.
 * @return The status and the JSON body.
 */
export async function asResident(
	api: APIRequestContext,
	session: ResidentSession,
	path: string,
): Promise<{ status: number; body: any }> {
	const res = await api.get(`${PORTAL}${path}`, {
		headers: { Authorization: `Bearer ${session.token}` },
	})

	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

/**
 * Send a portal API request with a body as the resident.
 *
 * @param api     Any request context on the instance.
 * @param session The resident's session.
 * @param method  POST or PATCH.
 * @param path    The path under `/portal/api`, starting with a slash.
 * @param data    The body.
 * @return The status and the JSON body.
 */
export async function sendAsResident(
	api: APIRequestContext,
	session: ResidentSession,
	method: 'POST' | 'PATCH',
	path: string,
	data: Record<string, unknown> = {},
): Promise<{ status: number; body: any }> {
	const res = await api.fetch(`${PORTAL}${path}`, {
		method,
		headers: { Authorization: `Bearer ${session.token}`, 'Content-Type': 'application/json' },
		data,
	})

	return { status: res.status(), body: await res.json().catch(() => ({})) }
}
