/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Address-fixture seeding helper for the PDOK-via-openconnector e2e layer
 * (migrate-pdok-to-openconnector, task PR-4).
 *
 * The two valid address fixtures — Conduction HQ (Amsterdam) and Tilburg
 * Stadhuis — mirror the canonical PostalAddress shape from
 * `openregister/openspec/changes/add-addresses-register/`. They are seeded
 * into the OpenRegister `addresses` register so the address-form e2e specs can
 * assert against OR-stored addresses WITHOUT requiring a live PDOK connection
 * or the openconnector adapter being installed.
 *
 * The woonplaats fixture is intentionally excluded — it lacks the fields
 * required for address-form population.
 *
 * If the OR `addresses` register / schema is not present yet (the sibling
 * add-addresses-register change has not shipped to this environment), the
 * create calls will fail; callers that need the seed should skip the live
 * assertion in that case (the register is an external dependency of this
 * change). Seeding is fixture SETUP only — all behavioural assertions happen
 * against the rendered DOM in the spec files (Playwright = UI-only).
 */

import { APIRequestContext, expect } from '@playwright/test'

/** OpenRegister register slug that owns address objects. */
export const ADDRESSES_REGISTER = 'addresses'
/** OpenRegister schema slug for a postal address. */
export const ADDRESS_SCHEMA = 'address'

const API_BASE = '/index.php/apps/openregister/api/objects'

/** Unique-per-run marker embedded in every seeded address. */
export const ADDRESS_RUN_PREFIX = `E2EADDR-${Date.now().toString(36)}-${Math.floor(Math.random() * 1e4)}`

/**
 * The two valid PostalAddress fixtures used by the address-form e2e layer.
 * Coordinates are [lng, lat] per GeoJSON, matching the normalized shape the
 * openconnector PDOK adapter writes through to the OR addresses register.
 */
export const ADDRESS_FIXTURES = [
	{
		displayName: `${ADDRESS_RUN_PREFIX} Lauriergracht 116, Amsterdam`,
		street: 'Lauriergracht',
		houseNumber: '116',
		postalCode: '1016 RR',
		city: 'Amsterdam',
		municipality: 'Amsterdam',
		location: { type: 'Point', coordinates: [4.88525, 52.37025] },
	},
	{
		displayName: `${ADDRESS_RUN_PREFIX} Stadhuisplein 130, Tilburg`,
		street: 'Stadhuisplein',
		houseNumber: '130',
		postalCode: '5038 TC',
		city: 'Tilburg',
		municipality: 'Tilburg',
		location: { type: 'Point', coordinates: [5.0796, 51.5606] },
	},
]

/**
 * Read a CSRF request-token from a freshly-loaded procest page. OpenRegister
 * write endpoints (POST/PUT/DELETE) are CSRF-protected; GET is not.
 * @param api Authenticated request context (storageState).
 */
export async function getRequestToken(api: APIRequestContext): Promise<string> {
	const res = await api.get('/index.php/apps/procest/dashboard')
	const html = await res.text()
	const m = html.match(/data-requesttoken="([^"]+)"/)
	if (!m) {
		throw new Error('Could not read requesttoken from /apps/procest/dashboard')
	}
	return m[1]
}

/** Standard headers for a CSRF-protected write call. */
function writeHeaders(token: string): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/json',
	}
}

/**
 * Probe whether the OR `addresses` register/schema exists in this environment.
 * Returns false when the listing endpoint 404s (sibling add-addresses-register
 * change not yet shipped), so callers can skip live address assertions.
 * @param api Authenticated request context.
 */
export async function addressesRegisterAvailable(api: APIRequestContext): Promise<boolean> {
	const res = await api.get(`${API_BASE}/${ADDRESSES_REGISTER}/${ADDRESS_SCHEMA}?_limit=1`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	return res.status() !== 404
}

/**
 * Seed both valid address fixtures into the OR addresses register. Returns the
 * created object ids (for cleanup). Asserts each create succeeds — call only
 * after `addressesRegisterAvailable()` returns true.
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 */
export async function seedAddressFixtures(api: APIRequestContext, token: string): Promise<string[]> {
	const ids: string[] = []
	for (const fixture of ADDRESS_FIXTURES) {
		const res = await api.post(`${API_BASE}/${ADDRESSES_REGISTER}/${ADDRESS_SCHEMA}`, {
			headers: writeHeaders(token),
			data: fixture,
		})
		expect(res.ok(), `seed address -> ${res.status()} ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		ids.push(String(body?.['@self']?.id ?? body?.uuid ?? body?.id ?? ''))
	}
	return ids
}

/**
 * Delete every address whose body carries this run's prefix (idempotent).
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 */
export async function cleanupAddressFixtures(api: APIRequestContext, token: string): Promise<void> {
	const res = await api.get(`${API_BASE}/${ADDRESSES_REGISTER}/${ADDRESS_SCHEMA}?_limit=200`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	if (!res.ok()) return
	const body = await res.json()
	const rows: any[] = Array.isArray(body) ? body : (body?.results ?? body?.data ?? [])
	for (const row of rows) {
		if (JSON.stringify(row).includes(ADDRESS_RUN_PREFIX)) {
			const id = String(row?.['@self']?.id ?? row?.uuid ?? row?.id ?? '')
			if (id) {
				await api.delete(`${API_BASE}/${ADDRESSES_REGISTER}/${ADDRESS_SCHEMA}/${id}`, {
					headers: writeHeaders(token),
				})
			}
		}
	}
}
