/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Say out loud what this run is actually testing.
 *
 * The problem this solves is specific to the shared development container. It
 * bind-mounts the host checkouts under `apps-extra/`, so the dossiq code it
 * serves is whatever is checked out THERE, on whatever branch that checkout
 * happens to be on. Your clone is not in the picture at all.
 *
 * An operator who runs the suite against `localhost:8080` from a feature branch
 * therefore believes they tested their branch, and did not. The run is honest
 * about that only if it says so, so this module prints the instance, the
 * Nextcloud and dossiq versions, and a fingerprint of the bundle the instance
 * actually serves next to the one this checkout built. Two different
 * fingerprints is the sentence that saves the afternoon.
 */

import { request } from '@playwright/test'
import { execFileSync } from 'child_process'
import { createHash } from 'crypto'
import * as fs from 'fs'
import * as path from 'path'
import { IS_SHARED_INSTANCE, SHARED_INSTANCE_FLAG } from '../base-url.ts'
import { occRun, occSystemGet } from './occ.ts'

/** Repository root of this dossiq checkout (tests/e2e/helpers to app root). */
const APP_ROOT = path.resolve(__dirname, '..', '..', '..')

/**
 * Where Nextcloud serves an app bundle from.
 *
 * NOT `/apps/dossiq/js/…`: that path answers `200 text/html` with the single
 * page shell for any filename at all, so a check against it passes on an
 * instance shipping no bundle. Apps mounted from `custom_apps` serve the real
 * file, and the content type is what tells the two apart.
 */
const SERVED_BUNDLE = '/custom_apps/dossiq/js/dossiq-main.js'

/** The bundle this checkout builds. */
const LOCAL_BUNDLE = path.join(APP_ROOT, 'js', 'dossiq-main.js')

/** What the instance serves at the bundle path. */
export interface ServedBundle {
	/** HTTP status of the request. */
	status: number
	/** Response content type, lowercased. */
	contentType: string
	/** Byte length of the body. */
	bytes: number
	/** First 12 hex characters of the sha256 of the body. */
	digest: string
	/** Whether this is a real script rather than the single page shell. */
	isScript: boolean
}

/**
 * Fetch the bundle the instance serves and fingerprint it.
 *
 * @param baseURL The instance under test.
 * @return The fingerprint, or `null` when the request failed outright.
 */
export async function fetchServedBundle(
	baseURL: string,
): Promise<ServedBundle | null> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}${SERVED_BUNDLE}`, {
			failOnStatusCode: false,
		})
		const body = await res.body()
		const contentType = (res.headers()['content-type'] ?? '').toLowerCase()
		return {
			status: res.status(),
			contentType,
			bytes: body.length,
			digest: createHash('sha256').update(body).digest('hex').slice(0, 12),
			isScript: res.ok() && /javascript|ecmascript/.test(contentType),
		}
	} catch {
		return null
	} finally {
		await ctx.dispose()
	}
}

/**
 * Fingerprint the bundle this checkout built, if it built one.
 *
 * @return Bytes and digest, or `null` when no local bundle exists.
 */
export function fingerprintLocalBundle(): { bytes: number; digest: string } | null {
	if (fs.existsSync(LOCAL_BUNDLE) === false) return null
	const body = fs.readFileSync(LOCAL_BUNDLE)
	return {
		bytes: body.length,
		digest: createHash('sha256').update(body).digest('hex').slice(0, 12),
	}
}

/**
 * Describe this checkout: branch and short commit.
 *
 * @return A label, or `unknown` when git will not answer.
 */
function describeCheckout(): string {
	try {
		const branch = execFileSync('git', ['rev-parse', '--abbrev-ref', 'HEAD'], {
			cwd: APP_ROOT,
			encoding: 'utf8',
		}).trim()
		const sha = execFileSync('git', ['rev-parse', '--short', 'HEAD'], {
			cwd: APP_ROOT,
			encoding: 'utf8',
		}).trim()
		return `${branch} @ ${sha}`
	} catch {
		return 'unknown'
	}
}

/**
 * Read the Nextcloud version the instance reports.
 *
 * @param baseURL The instance under test.
 * @return The version string, or `null`.
 */
async function nextcloudVersion(baseURL: string): Promise<string | null> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, {
			failOnStatusCode: false,
		})
		const body = await res.json().catch(() => ({}))
		return typeof body?.versionstring === 'string' ? body.versionstring : null
	} catch {
		return null
	} finally {
		await ctx.dispose()
	}
}

/**
 * Print the instance banner, and return the served bundle so the caller can
 * decide whether the run may continue.
 *
 * Everything here is best effort except the bundle fetch: a banner that aborts
 * a run because a version string would not parse is a banner nobody keeps.
 *
 * @param baseURL The instance under test.
 * @return The served bundle fingerprint, or `null` when it could not be read.
 */
export async function reportInstanceUnderTest(
	baseURL: string,
): Promise<ServedBundle | null> {
	const lines: string[] = []
	const label = IS_SHARED_INSTANCE
		? `SHARED instance, permitted by ${SHARED_INSTANCE_FLAG}`
		: 'instance owned by this run'

	lines.push(`instance      ${baseURL}  (${label})`)

	const ncVersion = await nextcloudVersion(baseURL)
	if (ncVersion !== null) lines.push(`nextcloud     ${ncVersion}`)

	const appVersion = await occRun([
		'config:app:get',
		'dossiq',
		'installed_version',
	])
		.then((r) => (r.code === 0 ? r.output.trim() : null))
		.catch(() => null)
	if (appVersion !== null) lines.push(`dossiq        ${appVersion}`)

	const appPath = await occRun(['app:getpath', 'dossiq'])
		.then((r) => (r.code === 0 ? r.output.trim() : null))
		.catch(() => null)
	if (appPath !== null)
		lines.push(`app path      ${appPath} (as the instance sees it)`)

	const instanceId = await occSystemGet('instanceid').catch(() => null)
	if (instanceId !== null) lines.push(`instance id   ${instanceId}`)

	const served = await fetchServedBundle(baseURL)
	if (served === null) {
		lines.push(`served bundle ${SERVED_BUNDLE} could not be fetched`)
	} else {
		lines.push(
			`served bundle ${served.status} ${served.contentType || 'no content type'} `
				+ `${served.bytes} bytes sha256:${served.digest}`,
		)
	}

	const local = fingerprintLocalBundle()
	lines.push(`this checkout ${describeCheckout()}  ${APP_ROOT}`)
	lines.push(
		local === null
			? 'local bundle  not built'
			: `local bundle  ${local.bytes} bytes sha256:${local.digest}`,
	)

	if (IS_SHARED_INSTANCE === true) {
		lines.push('')
		lines.push(
			'🔴 The shared container serves the HOST checkout under apps-extra, not this clone.',
		)
		if (served !== null && local !== null && served.digest !== local.digest) {
			lines.push(
				`   The served bundle and your local bundle differ (${served.digest} against `
					+ `${local.digest}). You are testing the host checkout's code, on whatever `
					+ 'branch it is on. Your branch is not under test.',
			)
		} else if (served !== null && local !== null) {
			lines.push(
				'   The served bundle matches your local build byte for byte, so the host '
					+ 'checkout is on the same code you have here. That can change the moment '
					+ 'somebody rebuilds on the host.',
			)
		} else {
			lines.push(
				'   No local bundle to compare against, so what the instance serves cannot '
					+ 'be tied to this checkout at all.',
			)
		}
		lines.push(
			'   Check what it is serving with: git -C <host apps-extra checkout> status',
		)
	}

	console.log(`\n[dossiq e2e] instance under test\n  ${lines.join('\n  ')}\n`)
	return served
}
