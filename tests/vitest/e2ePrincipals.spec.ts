/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The e2e suite's refused principals carry no credentials.
 *
 * WHY THIS IS A UNIT TEST AND NOT AN E2E. The claim under test is not about
 * an instance, it is about the options handed to `request.newContext`. That is
 * exactly what went wrong: eleven specs built their "anonymous" context with
 * `newContext({ baseURL })`, Playwright filled `storageState` in from the
 * project's `use:` block, which names the admin's captured session, and the
 * context signed in. A running instance cannot report that, because a signed
 * in caller succeeds and success is what those specs were reading.
 *
 * 🔴 THE ASSERTION THAT CARRIES THE REQUIREMENT is
 * "every credential-bearing option is PRESENT and empty", not "storageState is
 * empty". The merge Playwright performs is `if (!(key in options))`, so an
 * option this builder leaves out is the one that gets filled from the config.
 * Asserting only on `storageState` would pass a builder that omits
 * `httpCredentials`, which is the same defect one field over.
 */

import { describe, expect, it } from 'vitest'
import {
	anonymousRequestOptions,
	CREDENTIAL_OPTIONS,
	unprivilegedRequestOptions,
} from '../e2e/helpers/principals.ts'

const BASE_URL = 'http://localhost:8080'

describe('the anonymous principal', () => {
	it('names every credential-bearing option, so none is filled from the config', () => {
		const options = anonymousRequestOptions(BASE_URL)

		for (const key of CREDENTIAL_OPTIONS) {
			// `in`, not a truthiness check: Playwright's merge tests presence,
			// so an option present with the value `undefined` suppresses the
			// project default and an option omitted does not.
			expect(
				key in options,
				`${key} is absent, so Playwright fills it from use: in playwright.config.ts`,
			).toBe(true)
		}
	})

	it('carries no cookie, no credential and no header', () => {
		const options = anonymousRequestOptions(BASE_URL)

		expect(options.storageState).toEqual({ cookies: [], origins: [] })
		expect(options.httpCredentials).toBeUndefined()
		expect(options.clientCertificates).toBeUndefined()
		expect(options.extraHTTPHeaders).toEqual({})
	})

	it('still resolves a relative path', () => {
		// Without the base URL every request fails to resolve, and the spec
		// then reads a refusal that has nothing to do with authorization.
		expect(anonymousRequestOptions(BASE_URL).baseURL).toBe(BASE_URL)
	})

	it('carries no Authorization header under any casing', () => {
		// The ordinary-account builder sends `Authorization`, so this asserts
		// the two builders have not been confused for one another.
		const headers = anonymousRequestOptions(BASE_URL).extraHTTPHeaders as Record<
			string,
			string
		>
		const names = Object.keys(headers).map((name) => name.toLowerCase())

		expect(names).not.toContain('authorization')
		expect(names).not.toContain('requesttoken')
	})
})

describe('the ordinary account', () => {
	it('sends its credentials in the request rather than waiting to be challenged', () => {
		const options = unprivilegedRequestOptions(BASE_URL, 'e2euser', 'pass')
		const headers = options.extraHTTPHeaders as Record<string, string>

		expect(headers.Authorization).toBe(
			`Basic ${Buffer.from('e2euser:pass').toString('base64')}`,
		)
		// `httpCredentials` waits for a 401 challenge that OCS never sends.
		expect(options.httpCredentials).toBeUndefined()
	})

	it('brings no session of its own, so the instance answers the credentials', () => {
		// The admin's captured session would otherwise ride along beside the
		// basic credentials, and Nextcloud answers from the SESSION.
		const options = unprivilegedRequestOptions(BASE_URL, 'e2euser', 'pass')

		expect(options.storageState).toEqual({ cookies: [], origins: [] })
	})
})
