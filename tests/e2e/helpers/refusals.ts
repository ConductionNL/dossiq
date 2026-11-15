/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ASSERT THE REFUSAL, NOT A SHAPE BOTH PRINCIPALS PRODUCE.
 *
 * 🔴 WHY A HELPER RATHER THAN `expect(res.ok()).toBeFalsy()`. A falsy `ok()`
 * is every 4xx and every 5xx. It passes on the 500 a broken route answers, on
 * the 404 a typo in the path answers, and on the 401 an account that does not
 * exist answers. None of those is the permission under test, and each of them
 * reads exactly like the refusal the spec means.
 *
 * So a refusal is asserted as a STATUS the endpoint is known to answer and a
 * MESSAGE that names the reason. Three shapes exist on this platform and all
 * three were measured with curl rather than assumed:
 *
 *   POST /apps/openregister/api/flow-tasks
 *     no credentials -> 401 {"message":"Current user is not logged in"}
 *
 *   POST /apps/openregister/api/objects/flow-timers/working-calendar
 *     no credentials -> 403 {"error":"User 'Anonymous' does not have
 *                            permission to 'create' objects in schema
 *                            'Working calendar'"}
 *     an ordinary account -> the same 403, naming that account
 *
 *   GET /apps/openregister/api/objects/dossiq/propertyDefinition/{uuid}
 *     no credentials -> 404 {"error":"Not Found"}
 *
 * The 404 is deliberate on OpenRegister's side and is asserted as a 404: a 403
 * there would confirm the row exists, which is a different leak from the one
 * the 404 avoids.
 */

import type { APIResponse } from '@playwright/test'

import { expect } from '@playwright/test'

/**
 * Assert that a response is the refusal the spec means.
 *
 * @param response The response from the refused principal.
 * @param expected `statuses`, the statuses this endpoint is known to answer a
 *                 refused caller with, and `message`, matched against the body
 *                 so the reason is named and not merely absent.
 * @param what     What was attempted, for the failure message.
 */
export async function expectRefused(
	response: APIResponse,
	expected: { statuses: number[]; message: RegExp },
	what: string,
): Promise<void> {
	const body = await response.text()

	expect(
		expected.statuses,
		`${what}: answered ${response.status()} ${body}`,
	).toContain(response.status())

	expect(
		body,
		`${what}: answered ${response.status()} without naming a reason, which `
			+ 'is what a broken route and a working guard have in common',
	).toMatch(expected.message)
}

/** No session at all. */
export const NOT_LOGGED_IN = {
	statuses: [401],
	message: /not logged in/i,
}

/** A named principal the schema's RBAC refuses. */
export const NO_PERMISSION = {
	statuses: [403],
	message: /does not have permission/i,
}

/** A row a caller may not know exists. */
export const NOT_FOUND = {
	statuses: [404],
	message: /not found/i,
}

/**
 * Either of the two ways this platform turns away a caller with no session:
 * the app layer answers 401 and names the session, the object layer answers
 * 403 and names `Anonymous`. Both name their reason, which is the property
 * that matters; `res.ok() === false` does not.
 */
export const REFUSED_ANONYMOUS = {
	statuses: [401, 403],
	message: /not logged in|does not have permission/i,
}
