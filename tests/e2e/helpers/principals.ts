/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE PRINCIPAL A PERMISSION TEST IS ABOUT.
 *
 * 🔴 WHY THIS FILE EXISTS. Eleven specs built their "anonymous" context like
 * this:
 *
 *     api       = await playwright.request.newContext({ baseURL })
 *     anonymous = await playwright.request.newContext({ baseURL })
 *     token     = await getRequestToken(api)
 *
 * The two calls are identical, so whatever `api` is, `anonymous` is. And `api`
 * is signed in: `getRequestToken` reads a non-empty `data-requesttoken` off
 * `/apps/dossiq/dashboard` and throws when it finds none, and Nextcloud's
 * login page carries an EMPTY one. The fact that the line above does not throw
 * is the proof.
 *
 * The cause is one line in `tests/e2e/playwright.config.ts`: `use.storageState`
 * names the admin session that `global-setup.ts` captured. Playwright fills
 * every option a `request.newContext` call leaves out from that block
 * (`runBeforeCreateRequestContext` in @playwright/test), so an omitted
 * `storageState` silently becomes the admin's.
 *
 * WHAT THAT COST, in both directions. In dossiq it turned two working guards
 * into reported security holes: a spec reported 201 for an "anonymous" create
 * whose response carried `createdBy: "admin"`, while a credential-free curl on
 * the same endpoint answers
 * `401 {"message":"Current user is not logged in"}`. In decidiq the same fault
 * turned a refusal into a hollow pass. A test that passes for the admin too
 * cannot fail.
 *
 * 🔴 EVERY CREDENTIAL-BEARING OPTION IS NAMED, not only `storageState`. The
 * merge above is `if (!(key in options))`, so a key present with the value
 * `undefined` still suppresses the project default, and a key left out does
 * not. Naming `httpCredentials`, `clientCertificates` and `extraHTTPHeaders`
 * here means a future `use:` block cannot reintroduce this bug by adding one
 * of them, which is exactly how it arrived the first time.
 */

import type { APIRequestContext } from '@playwright/test'

/**
 * The shape `playwright` has inside a test fixture, narrowed to the one call
 * this module makes.
 *
 * Structural rather than imported from `@playwright/test` so the options
 * builders below can be unit-tested without a browser.
 */
export interface RequestFactory {
	request: {
		newContext: (options: Record<string, unknown>) => Promise<APIRequestContext>
	}
}

/**
 * The options that carry a credential into a request context.
 *
 * Exported because the unit test asserts over this list rather than over a
 * hand-written copy of it: a new credential option added here without a value
 * in the builders below fails that test.
 */
export const CREDENTIAL_OPTIONS = [
	'storageState',
	'httpCredentials',
	'clientCertificates',
	'extraHTTPHeaders',
] as const

/**
 * Request-context options for a caller with no credentials of any kind.
 *
 * @param baseURL The instance under test. Without it a relative path in a
 *                spec cannot resolve, and the request then fails for a reason
 *                that has nothing to do with authorization.
 * @return Options to hand to `request.newContext`.
 */
export function anonymousRequestOptions(baseURL: string): Record<string, unknown> {
	return {
		baseURL,
		// An EXPLICIT empty jar. Omitting this is the whole defect.
		storageState: { cookies: [], origins: [] },
		httpCredentials: undefined,
		clientCertificates: undefined,
		// No `requesttoken`, and nothing else either. A caller with no session
		// has no CSRF token to present, and presenting one would make the
		// refusal ambiguous between "not logged in" and "bad token".
		extraHTTPHeaders: {},
	}
}

/**
 * Request-context options for an ordinary logged-in account.
 *
 * Basic auth in an explicit header rather than `httpCredentials`, for the
 * reason `helpers/auth.ts#provisioningContext` records: `httpCredentials`
 * waits to be challenged with a 401, and Nextcloud's OCS layer answers an
 * unauthenticated call with a 200 carrying an OCS status instead, so the
 * challenge never arrives and the credentials are never sent.
 *
 * @param baseURL  The instance under test.
 * @param username The ordinary account.
 * @param password Its password.
 * @return Options to hand to `request.newContext`.
 */
export function unprivilegedRequestOptions(
	baseURL: string,
	username: string,
	password: string,
): Record<string, unknown> {
	const basic = Buffer.from(`${username}:${password}`).toString('base64')
	return {
		baseURL,
		// The admin's session would otherwise ride along beside the basic
		// credentials, and Nextcloud answers from the SESSION. Measured on
		// proof run 34603140073 for `provisioningContext`: with a password the
		// instance refuses, `whoami` still answered `admin`.
		storageState: { cookies: [], origins: [] },
		httpCredentials: undefined,
		clientCertificates: undefined,
		extraHTTPHeaders: {
			Authorization: `Basic ${basic}`,
			'OCS-APIRequest': 'true',
		},
	}
}

/**
 * A request context that has never signed in.
 *
 * @param playwright The Playwright module, from the test fixture.
 * @param baseURL    The instance under test.
 * @return A context carrying no cookie, no credential and no request token.
 */
export async function anonymousContext(
	playwright: RequestFactory,
	baseURL: string,
): Promise<APIRequestContext> {
	return playwright.request.newContext(anonymousRequestOptions(baseURL))
}

/**
 * A request context signed in as an ordinary account with no admin rights.
 *
 * For a scenario whose subject is an ordinary colleague rather than a
 * stranger.
 *
 * 🔴 THE ACCOUNT IS THE ONE `ci-seed.sh` ACTUALLY PROVISIONS, which is what
 * `E2E_USER_NAME` / `E2E_USER_PASS` name and what the seed script then
 * REFUSES to continue without, having checked it holds no admin group. A spec
 * that reached for `NC_USER ?? 'user1'` was authenticating as an account that
 * does not exist on the instance, so its 4xx was "no such user" rather than
 * "this user may not", and it would have read exactly the same had the
 * permission been missing entirely.
 *
 * @param playwright The Playwright module, from the test fixture.
 * @param baseURL    The instance under test.
 * @return A context authenticated as that account.
 */
export async function unprivilegedContext(
	playwright: RequestFactory,
	baseURL: string,
): Promise<APIRequestContext> {
	const username = process.env.E2E_USER_NAME ?? 'e2euser'
	const password = process.env.E2E_USER_PASS ?? 'e2e-user-pass'
	return playwright.request.newContext(
		unprivilegedRequestOptions(baseURL, username, password),
	)
}
