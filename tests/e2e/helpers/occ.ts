/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the e2e suite reaches `occ`.
 *
 * The suite is otherwise pure HTTP, and deliberately so. This one file is the
 * exception, because OpenRegister's sanctioned way to destroy a record on an
 * archival schema is a CLI command and nothing else:
 *
 *     occ openregister:objects:purge <uuid>... --force --apply
 *
 * That is not an oversight to route around. `occ` needs shell access to the
 * server, which is an authorization boundary an HTTP caller cannot cross by
 * sending a header, and `dossiq/case` — the entity this whole suite exists to
 * exercise — declares `x-openregister-archival`. Every HTTP delete route
 * refuses it (see openregister#3428). So the fixtures have to be able to run a
 * command on the instance they are testing, and this module is the only place
 * that knows how.
 *
 * ── Resolution order ─────────────────────────────────────────────────────────
 *
 *  1. `DOSSIQ_E2E_OCC` — a complete command prefix, e.g.
 *     `docker compose -p dq-e2e exec -T -u www-data nextcloud php occ`. This is
 *     the escape hatch for any rig shape the two guesses below do not cover,
 *     and it always wins.
 *  2. `NEXTCLOUD_CONTAINER` — a container name, turned into
 *     `docker exec -u www-data <name> php occ`. The common developer-rig case.
 *  3. `php occ` from the Nextcloud server root — `NEXTCLOUD_ROOT` when set,
 *     otherwise two directories above the app, which is where the server root
 *     sits both on CI (`server/apps/dossiq`) and in the docker dev images
 *     (`server/apps-extra/dossiq`). This is the CI case: the shared workflow
 *     serves Nextcloud with `php -S` on the runner itself, and
 *     `tests/e2e/ci-seed.sh` already runs `php occ` that way.
 *
 * ── When none of them works ──────────────────────────────────────────────────
 *
 * The suite fails, loudly, naming what it tried. It does NOT quietly leave
 * fixtures behind: an unreachable `occ` means teardown cannot remove a case at
 * all, and a run that cannot clean up is one that poisons the next run on the
 * same instance — the exact failure dossiq#1824 existed to end.
 *
 * The reachability probe is `openregister:objects:purge --help`, not `status`,
 * so "occ works" and "this OpenRegister build has the purge command" are one
 * answer rather than two. An OpenRegister older than #3428 fails it with
 * Symfony's own "command is not defined", which names the real problem.
 */

import { request } from '@playwright/test'
import { execFile } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { promisify } from 'util'
import { IS_SHARED_INSTANCE, SHARED_INSTANCE_FLAG } from '../base-url.ts'

const execFileAsync = promisify(execFile)

/** Repository root of the dossiq app (tests/e2e/helpers → app root). */
const APP_ROOT = path.resolve(__dirname, '..', '..', '..')

/** How long a single occ invocation may take on a loaded developer box. */
const OCC_TIMEOUT_MS = 180_000

/**
 * Raised when the suite cannot run `occ` against the instance under test.
 *
 * A distinct type so teardown can tell "this object survived" (a finding worth
 * naming) from "the suite has no way to remove any object" (a rig problem that
 * must abort rather than accumulate one survivor message per fixture).
 */
export class OccUnavailableError extends Error {
	constructor(message: string) {
		super(message)
		this.name = 'OccUnavailableError'
	}
}

/** One candidate way of invoking occ. */
interface OccInvocation {
	/** argv, already split — `execFile` is used, so nothing is shell-parsed. */
	argv: string[]
	/** Working directory the invocation needs. */
	cwd: string
	/** Human-readable origin, for the failure message. */
	source: string
}

/** Cached resolution: `undefined` = not probed yet, `null` = probed and failed. */
let resolved: OccInvocation | null | undefined
/** Why every candidate was rejected, kept for the error message. */
let rejections: string[] = []

/**
 * Split a command prefix on whitespace. Deliberately not a shell parser: the
 * invocation is passed to `execFile`, so there is no shell, no quoting rules
 * and no injection surface. A path with a space needs `DOSSIQ_E2E_OCC` to be
 * expressed some other way, which is a fair trade for not spawning a shell.
 *
 * @param value The raw command prefix.
 */
function splitCommand(value: string): string[] {
	return value
		.trim()
		.split(/\s+/)
		.filter((part) => part !== '')
}

/**
 * Every invocation worth trying, in priority order.
 */
function candidates(): OccInvocation[] {
	const found: OccInvocation[] = []

	const explicit = process.env.DOSSIQ_E2E_OCC ?? process.env.NEXTCLOUD_OCC
	if (explicit !== undefined && explicit.trim() !== '') {
		found.push({
			argv: splitCommand(explicit),
			cwd: APP_ROOT,
			source: 'DOSSIQ_E2E_OCC',
		})
	}

	const container =
		process.env.DOSSIQ_E2E_CONTAINER ?? process.env.NEXTCLOUD_CONTAINER
	if (container !== undefined && container.trim() !== '') {
		found.push({
			argv: [
				'docker',
				'exec',
				'-u',
				'www-data',
				container.trim(),
				'php',
				'occ',
			],
			cwd: APP_ROOT,
			source: `NEXTCLOUD_CONTAINER=${container.trim()}`,
		})
	}

	const roots = [process.env.NEXTCLOUD_ROOT, path.resolve(APP_ROOT, '..', '..')]
	for (const root of roots) {
		if (root === undefined || root.trim() === '') continue
		const abs = path.resolve(root)
		if (fs.existsSync(path.join(abs, 'occ')) === false) continue
		found.push({
			argv: ['php', 'occ'],
			cwd: abs,
			source: `php occ in ${abs}`,
		})
	}

	return found
}

/**
 * Run one occ invocation and return its exit code and combined output.
 *
 * @param invocation The resolved invocation.
 * @param args       Arguments to append after the occ prefix.
 */
async function run(
	invocation: OccInvocation,
	args: string[],
): Promise<{ code: number; output: string }> {
	const [bin, ...prefix] = invocation.argv
	try {
		const { stdout, stderr } = await execFileAsync(bin, [...prefix, ...args], {
			cwd: invocation.cwd,
			timeout: OCC_TIMEOUT_MS,
			maxBuffer: 16 * 1024 * 1024,
		})
		return { code: 0, output: `${stdout}${stderr}` }
	} catch (error: any) {
		const output = `${error?.stdout ?? ''}${error?.stderr ?? ''}${
			error?.stdout === undefined && error?.stderr === undefined
				? String(error?.message ?? error)
				: ''
		}`
		return { code: typeof error?.code === 'number' ? error.code : 1, output }
	}
}

/**
 * Resolve — once per process — how this environment reaches occ.
 *
 * Probing costs one occ bootstrap, so the answer is cached including the
 * negative one: a rig with no occ should say so once, not once per fixture.
 */
async function resolveOcc(): Promise<OccInvocation | null> {
	if (resolved !== undefined) return resolved

	rejections = []
	for (const candidate of candidates()) {
		const probe = await run(candidate, ['openregister:objects:purge', '--help'])
		if (probe.code === 0) {
			resolved = candidate
			return resolved
		}
		rejections.push(
			`  - ${candidate.source} (${candidate.argv.join(' ')}) exited `
				+ `${probe.code}: ${probe.output.trim().split('\n').slice(0, 3).join(' / ')}`,
		)
	}

	if (rejections.length === 0) {
		rejections.push('  - no candidate invocation was even configured')
	}
	resolved = null
	return resolved
}

/**
 * The message a rig operator needs when occ cannot be reached.
 */
function unavailableMessage(): string {
	return (
		'The e2e suite cannot run `occ` against the instance under test, so it '
		+ 'cannot remove the cases it creates.\n'
		+ '`dossiq/case` declares x-openregister-archival, and every OpenRegister '
		+ 'HTTP delete route refuses an archival record (openregister#3428). The '
		+ 'only sanctioned removal is '
		+ '`occ openregister:objects:purge <uuid> --force --apply`.\n'
		+ 'Tried:\n'
		+ rejections.join('\n')
		+ '\nSet one of:\n'
		+ '  DOSSIQ_E2E_OCC="docker compose -p <project> exec -T -u www-data nextcloud php occ"\n'
		+ '  NEXTCLOUD_CONTAINER=<container name>   (becomes: docker exec -u www-data <name> php occ)\n'
		+ '  NEXTCLOUD_ROOT=/path/to/nextcloud      (becomes: php occ, run from there)\n'
		+ 'If the probe failed with "command is not defined", the instance runs an '
		+ 'OpenRegister older than #3428 — upgrade it rather than working around this.'
	)
}

/**
 * Run an arbitrary occ command on the resolved invocation.
 *
 * Exported so the instance report can read a version or a config key without
 * every caller repeating the resolution logic.
 *
 * @param args Arguments after the occ prefix.
 * @return The command's exit code and combined output.
 * @throws OccUnavailableError When no invocation answers on this rig.
 */
export async function occRun(
	args: string[],
): Promise<{ code: number; output: string }> {
	const invocation = await resolveOcc()
	if (invocation === null) {
		throw new OccUnavailableError(unavailableMessage())
	}
	return run(invocation, args)
}

/**
 * Read one system config value through occ.
 *
 * @param key The config key, e.g. `instanceid`.
 * @return The trimmed value, or `null` when the command did not answer.
 */
export async function occSystemGet(key: string): Promise<string | null> {
	const result = await occRun(['config:system:get', key]).catch(() => null)
	if (result === null || result.code !== 0) return null
	const value = result.output.trim()
	return value === '' ? null : value
}

/**
 * Read the instance id of the Nextcloud answering on `baseURL`, over HTTP.
 *
 * Nextcloud names its PHP session after its own instance id, so the first
 * `Set-Cookie` whose name looks like `oc<alnum>` and is not one of the fixed
 * `oc_*` cookies IS the instance id. No login and no write is needed, which is
 * what makes this usable as a pre-flight check.
 *
 * @param baseURL The instance under test.
 * @return The instance id, or `null` when no session cookie came back.
 */
export async function readInstanceIdOverHttp(
	baseURL: string,
): Promise<string | null> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/index.php/login`, {
			failOnStatusCode: false,
		})
		for (const header of res.headersArray()) {
			if (header.name.toLowerCase() !== 'set-cookie') continue
			const name = header.value.split('=')[0]?.trim() ?? ''
			if (/^oc[a-z0-9]{6,}$/i.test(name) && name.startsWith('oc_') === false) {
				return name
			}
		}
		return null
	} catch {
		return null
	} finally {
		await ctx.dispose()
	}
}

/**
 * The message an operator reads when occ answered on the wrong Nextcloud.
 *
 * @param invocation How occ was reached.
 * @param baseURL    The instance under test.
 * @param http       The instance id the HTTP target reported, if any.
 * @param cli        The instance id occ reported, if any.
 * @return The full error text.
 */
function wrongInstanceMessage(
	invocation: string,
	baseURL: string,
	http: string | null,
	cli: string | null,
): string {
	const observed =
		http !== null && cli !== null
			? `${baseURL} reports instance ${http}. occ reports instance ${cli}.\n`
			: `The instance ids could not both be read (http=${http ?? 'unknown'}, `
				+ `occ=${cli ?? 'unknown'}).\n`

	return (
		'[dossiq e2e] occ is not talking to the instance under test.\n'
		+ observed
		+ `occ was reached via ${invocation}.\n`
		+ 'Teardown purges archival cases through occ, so a wrong target means this '
		+ 'run destroys records on one Nextcloud while testing another.\n\n'
		+ 'Name the container that serves the instance you aimed at:\n\n'
		+ '    DOSSIQ_E2E_CONTAINER=nextcloud\n\n'
		+ 'or spell the whole prefix out:\n\n'
		+ '    DOSSIQ_E2E_OCC="docker exec -u www-data nextcloud php occ"\n\n'
		+ 'Find the container with `docker ps` and confirm it with '
		+ '`docker exec -u www-data <name> php occ config:system:get instanceid`.'
	)
}

/**
 * Confirm occ is reachable AND lands on the instance under test, or throw with
 * the whole diagnosis.
 *
 * Called from `global-setup.ts` so a wrong or unreachable occ fails ONE step
 * before any spec runs, instead of surfacing as a teardown failure 30 minutes
 * in.
 *
 * The binding check exists because "occ answered" and "occ answered on the right
 * Nextcloud" are different questions, and on a dev box carrying five containers
 * the second one is the one that matters. Nextcloud names its session cookie
 * after its instance id, so both halves can be read without logging in and
 * without writing anything.
 *
 * A mismatch is fatal on a shared instance and a warning elsewhere. On a
 * disposable rig a wrong occ costs you a dirty rig you were going to throw
 * away; on the shared container it costs somebody else their records.
 *
 * @param baseURL The instance under test.
 * @return A human-readable description of the invocation that answered.
 */
export async function assertOccReachable(baseURL: string): Promise<string> {
	const invocation = await resolveOcc()
	if (invocation === null) {
		throw new OccUnavailableError(unavailableMessage())
	}
	const described = `${invocation.source} (${invocation.argv.join(' ')})`

	const http = await readInstanceIdOverHttp(baseURL)
	const cli = await occSystemGet('instanceid')
	const bound = http !== null && cli !== null && http === cli

	if (bound === false) {
		if (IS_SHARED_INSTANCE === true) {
			throw new OccUnavailableError(
				wrongInstanceMessage(described, baseURL, http, cli)
					+ `\nThis is fatal because ${SHARED_INSTANCE_FLAG} is set: the target is `
					+ 'shared with other people.',
			)
		}
		console.warn(wrongInstanceMessage(described, baseURL, http, cli))
		return described
	}

	return `${described}, bound to instance ${cli}`
}

/**
 * Permanently destroy the named objects through the sanctioned CLI purge.
 *
 * `--force` is required and passed deliberately: it is what makes the suite say
 * out loud that it is destroying records on an archival schema. `--apply` turns
 * off the command's dry-run default.
 *
 * The exit code is REPORTED, not trusted. The command exits 1 if any named uuid
 * was refused OR simply no longer exists, and "already gone" is a perfectly good
 * outcome for a teardown that may be sweeping the same id twice. Every caller
 * decides by re-reading the object instead.
 *
 * @param ids Object uuids to purge.
 * @return The command's exit code and combined output.
 */
export async function occPurge(
	ids: string[],
): Promise<{ code: number; output: string }> {
	if (ids.length === 0) return { code: 0, output: '' }

	const invocation = await resolveOcc()
	if (invocation === null) {
		throw new OccUnavailableError(unavailableMessage())
	}

	return run(invocation, [
		'openregister:objects:purge',
		...ids,
		'--force',
		'--apply',
	])
}
