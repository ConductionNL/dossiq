/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every Berichtenbox call the browser makes has to be a route the server
 * declares.
 *
 * `berichtenboxApi.js` carried two calls that no entry in `appinfo/routes.php`
 * answered: `POST /api/berichtenbox/poll/{id}` and `GET
 * /api/berichtenbox/types`. Nothing imported them, so nobody ever got the 404,
 * and nothing in the repository could have said so: a JS module and a PHP
 * route array are not compared by any build step, any linter or any gate. The
 * client was written from the spec's wording and the route was later declared
 * with a different shape, which is drift that only a reader can see.
 *
 * This reads both files as text and compares them, so the next divergence is a
 * red test instead of a 404 in somebody's console.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const CLIENT_PATH = path.join(ROOT, 'src', 'services', 'berichtenboxApi.js')
const ROUTES_PATH = path.join(ROOT, 'appinfo', 'routes.php')

const clientSource = fs.readFileSync(CLIENT_PATH, 'utf8')
const routesSource = fs.readFileSync(ROUTES_PATH, 'utf8')

const PREFIX = '/api/berichtenbox'

/**
 * A path with its parameters blanked, so `/messages/${id}` and
 * `/messages/{messageId}` compare equal.
 *
 * @param {string} value The path as written in either file.
 * @return {string} The comparable path.
 */
function normalise(value) {
	return value.replace(/\$\{[^}]*\}/g, '{}').replace(/\{[^}]*\}/g, '{}')
}

/**
 * Every axios call the client makes against the Berichtenbox base url.
 *
 * Only the code is read: the file's own docblock names both retired paths on
 * purpose, and a substring search would have called that a call.
 *
 * @return {Array<{verb: string, path: string}>} The calls, in file order.
 */
function clientCalls() {
	const code = clientSource.replace(/\/\*[\s\S]*?\*\//g, '')
	const pattern = /axios\.(get|post|put|patch|delete)\(\s*`\$\{baseUrl\}([^`]*)`/g
	const found = []
	let match = pattern.exec(code)
	while (match !== null) {
		found.push({ verb: match[1].toUpperCase(), path: normalise(match[2]) })
		match = pattern.exec(code)
	}
	return found
}

/**
 * Every Berichtenbox route the server declares.
 *
 * @return {Array<{verb: string, path: string, name: string}>} The routes.
 */
function declaredRoutes() {
	const pattern =
		/'name'\s*=>\s*'(berichtenbox#[a-zA-Z]+)'.*?'url'\s*=>\s*'([^']+)'.*?'verb'\s*=>\s*'([A-Z]+)'/g
	const found = []
	let match = pattern.exec(routesSource)
	while (match !== null) {
		found.push({
			name: match[1],
			path: normalise(match[2].slice(PREFIX.length)),
			verb: match[3],
		})
		match = pattern.exec(routesSource)
	}
	return found
}

describe('berichtenboxApi calls only routes the server declares', () => {
	it('finds calls and routes at all, so a silent zero cannot pass', () => {
		// Both readers are regexes over source. A regex that stops matching
		// returns an empty list, and an empty list satisfies every assertion
		// below without touching either file. This is the control.
		expect(clientCalls().length).toBeGreaterThan(0)
		expect(declaredRoutes().length).toBeGreaterThan(0)
	})

	it('matches every client call to a declared route, verb included', () => {
		const routes = declaredRoutes()
		for (const call of clientCalls()) {
			const target = routes.find((route) => route.path === call.path)
			expect(
				target,
				`${call.verb} ${PREFIX}${call.path} is called by `
					+ 'src/services/berichtenboxApi.js and declared by no route in '
					+ 'appinfo/routes.php',
			).toBeTruthy()
			expect(
				target.verb,
				`${PREFIX}${call.path} is declared ${target.verb}, called ${call.verb}`,
			).toBe(call.verb)
		}
	})

	it('does not poll read status from the browser, and names no /types', () => {
		// Named rather than left to the rule above, because both were removed
		// for a reason the rule cannot state. Read status arrives by event
		// through DigitalPostDeliveredListener; IntegriqAdapter answers
		// getReadStatus with `unknown`, so a poll from a page would stamp
		// readPolledAt and tell the handler nothing while looking like it
		// asked. `/types` was never routed at all; the compose dialog holds
		// its own list.
		const calls = clientCalls()
		expect(calls.some((call) => call.path.startsWith('/poll'))).toBe(false)
		expect(calls.some((call) => call.path === '/types')).toBe(false)
	})
})
