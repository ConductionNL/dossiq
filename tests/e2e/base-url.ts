/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ONE place that decides which Nextcloud the dossiq e2e suite talks to, and
 * whether that Nextcloud is shared with other people.
 *
 * Why this file exists
 * --------------------
 * Before this file, every entry point computed its own target:
 *
 *   playwright.config.ts        `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`
 *   tests/e2e/global-setup.ts   `NEXTCLOUD_URL ?? NC_BASE_URL ?? 'http://localhost:8080'`
 *   6 spec files                their own copy of the same expression, for the
 *                               `request.newContext({ baseURL })` API probes
 *
 * Three things were wrong with that:
 *
 *  1. `BASE_URL`, the variable the shared ConductionNL/.github quality
 *     workflow actually exports for both the "Seed test data" step and the
 *     Playwright run step, was accepted by NONE of them. It only worked on CI
 *     because the workflow happens to export `NEXTCLOUD_URL` as well; a
 *     resolver that omits `BASE_URL` is one workflow change away from
 *     hard-failing every run. openconnector adopted a `PLAYWRIGHT_BASE_URL`-only
 *     resolver and its "E2E Tests (Playwright)" job has failed on every run
 *     since with "Error: PLAYWRIGHT_BASE_URL is not set."
 *  2. `PLAYWRIGHT_BASE_URL`, the variable every runbook in this programme uses
 *     to point a suite at a disposable instance, was ignored outright.
 *     Exporting it did nothing.
 *  3. The `|| 'http://localhost:8080'` default is the SHARED development
 *     container on a Conduction dev box. It bind-mounts real host checkouts, so
 *     a suite that quietly falls back to it creates fixture cases, caseTypes,
 *     statusTypes and workflowTemplates in somebody else's environment, and
 *     `tests/e2e/fixtures.ts` then deletes what it finds. Two apps in this
 *     programme were found doing exactly this.
 *
 * So: off CI the target must be stated explicitly. A missing variable is a hard
 * error naming the fix, not a silent redirect onto somebody else's instance.
 *
 * The one exception is CI. A GitHub runner has no shared instance. The shared
 * workflow starts a throwaway Nextcloud on the runner's own `php -S
 * 0.0.0.0:8080`, so falling back there is safe, and it keeps the suite runnable
 * if a future workflow revision renames its exported variable again.
 *
 * Aiming at the shared container on purpose
 * -----------------------------------------
 * Refusing by accident is not the same as permitting on purpose. There are real
 * reasons to point this suite at the shared dev container: it is the only rig
 * that carries the demo caseload, and it serves the host checkouts everyone on
 * the box is editing. So a shared origin is reachable, but only when you say so
 * in a second variable:
 *
 *     DOSSIQ_E2E_ALLOW_SHARED_INSTANCE=http://localhost:8080 \
 *     PLAYWRIGHT_BASE_URL=http://localhost:8080 \
 *     DOSSIQ_E2E_CONTAINER=nextcloud \
 *     npx playwright test
 *
 * The flag holds the ORIGIN you are permitting, not `1`. A bare `1` left in a
 * shell profile goes on permitting every shared instance the suite ever meets;
 * an origin permits the one you typed and nothing else. Point the suite at a
 * different shared instance and the flag stops matching, so you have to type
 * the new one.
 *
 * Setting the flag changes how the suite behaves, and the changes are not
 * cosmetic:
 *
 *   - Teardown deletes only the object ids this run created. The run prefix
 *     stays in every seeded title so you can still recognise residue by eye,
 *     but it is no longer what teardown deletes by. See
 *     `helpers/fixtures.ts#cleanupRunObjects`.
 *   - The cross-run residue sweep reports instead of deleting. It cannot tell
 *     your leftovers from a colleague's.
 *   - `case-flow-live-journeys.spec.ts` refuses to run. It needs the case flow
 *     enabled, and enabling that on a shared instance runs it on every case
 *     anybody creates.
 *   - `occ` has to be pointed at the shared container, and the suite proves it
 *     landed on the right one before any spec runs.
 *   - The bundle is not rebuilt. The container serves the HOST checkout, not
 *     this clone, so building here would change nothing and hide that fact.
 */

/** The variable that permits a shared instance. Holds an origin, not a boolean. */
export const SHARED_INSTANCE_FLAG = 'DOSSIQ_E2E_ALLOW_SHARED_INSTANCE'

const CI_DEFAULT_BASE_URL = 'http://localhost:8080'

/**
 * Origins that belong to the shared Conduction development stack.
 *
 * Port 8080 is the `nextcloud` container every dev box runs, and port 80 is the
 * same stack when it is published without a port. Both bind-mount host
 * checkouts and both carry data other people rely on.
 *
 * A disposable rig gets its own high port (8095, 8614, 8731 and so on), so it
 * never matches this list and needs no flag.
 */
const SHARED_ORIGINS = [
	'http://localhost:8080',
	'http://localhost:80',
] as const

/** Loopback spellings that all mean the same host. */
const LOOPBACK = new Set(['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'])

/**
 * Reduce a URL to `scheme://host:port`, with loopback spellings folded onto
 * `localhost` and the default port made explicit.
 *
 * Returns the trimmed input when it will not parse, so a malformed value fails
 * later on the HTTP probe with its own message rather than here.
 *
 * @param value A base URL.
 * @return The normalised origin.
 */
export function normaliseOrigin(value: string): string {
	let url: URL
	try {
		url = new URL(value)
	} catch {
		return value.trim().replace(/\/+$/, '')
	}
	const host = LOOPBACK.has(url.hostname) ? 'localhost' : url.hostname
	const port = url.port !== '' ? url.port : url.protocol === 'https:' ? '443' : '80'
	return `${url.protocol}//${host}:${port}`
}

/**
 * Whether this URL names an instance shared with other people.
 *
 * @param value A base URL.
 * @return `true` when the origin is on the shared list.
 */
export function isSharedOrigin(value: string): boolean {
	const origin = normaliseOrigin(value)
	return SHARED_ORIGINS.some((shared) => normaliseOrigin(shared) === origin)
}

/**
 * Whether this process runs on a CI runner.
 *
 * On a runner `localhost:8080` is the runner's own throwaway Nextcloud, started
 * by the shared workflow, so none of the shared-instance rules apply.
 *
 * @return `true` on GitHub Actions or any CI that exports `CI`.
 */
export function isCI(): boolean {
	return Boolean(process.env.CI) || Boolean(process.env.GITHUB_ACTIONS)
}

/**
 * The message an operator reads when they aimed at a shared instance without
 * saying so.
 *
 * @param target The base URL they asked for.
 * @param flagValue Whatever the flag currently holds.
 * @return The full error text.
 */
function refusalMessage(target: string, flagValue: string | undefined): string {
	const origin = normaliseOrigin(target)
	const mismatch =
		flagValue !== undefined && flagValue.trim() !== ''
			? `${SHARED_INSTANCE_FLAG} is set to "${flagValue.trim()}", which normalises to `
				+ `${normaliseOrigin(flagValue)} and does not match ${origin}.\n`
			: ''

	return (
		`[dossiq e2e] ${target} is the SHARED development container, and this run `
		+ 'did not say it meant to go there.\n'
		+ mismatch
		+ 'That instance bind-mounts host checkouts and holds data your colleagues '
		+ 'are working on. This suite seeds and deletes OpenRegister objects.\n'
		+ 'Two apps in this programme were caught corrupting a shared environment '
		+ 'exactly this way, so a base URL on its own is not enough.\n\n'
		+ 'Point the suite at your own disposable rig:\n\n'
		+ '    PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test\n\n'
		+ 'Or aim at the shared container on purpose, naming the origin you permit:\n\n'
		+ `    ${SHARED_INSTANCE_FLAG}=${origin} \\\n`
		+ `    PLAYWRIGHT_BASE_URL=${target} \\\n`
		+ '    DOSSIQ_E2E_CONTAINER=nextcloud \\\n'
		+ '    npx playwright test\n\n'
		+ 'Read the header of tests/e2e/base-url.ts before you do. The flag changes '
		+ 'what teardown deletes, refuses one spec outright, and stops rebuilding '
		+ 'the bundle.'
	)
}

/**
 * Resolve the Nextcloud base URL for this run.
 *
 * @return the base URL, without a trailing slash
 * @throws when no target is configured outside CI
 * @throws when the target is a shared instance and the opt-in flag does not name it
 */
export function resolveBaseURL(): string {
	const explicit =
		process.env.PLAYWRIGHT_BASE_URL
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
		// Exported by the shared ConductionNL/.github quality workflow.
		?? process.env.BASE_URL

	if (explicit) {
		const target = explicit.replace(/\/+$/, '')
		if (isCI() === false && isSharedOrigin(target) === true) {
			const flag = process.env[SHARED_INSTANCE_FLAG]
			const permitted =
				flag !== undefined
				&& flag.trim() !== ''
				&& normaliseOrigin(flag) === normaliseOrigin(target)
			if (permitted === false) {
				throw new Error(refusalMessage(target, flag))
			}
		}
		return target
	}

	if (isCI()) {
		console.warn(
			'[dossiq e2e] no PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / NC_BASE_URL / BASE_URL set; '
				+ `using the CI-local default ${CI_DEFAULT_BASE_URL}.`,
		)
		return CI_DEFAULT_BASE_URL
	}

	throw new Error(
		'[dossiq e2e] No target Nextcloud configured. Set PLAYWRIGHT_BASE_URL (preferred), '
			+ 'NEXTCLOUD_URL, NC_BASE_URL or BASE_URL to the instance you want to test, e.g.\n\n'
			+ '    PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test\n\n'
			+ 'There is deliberately no default: the historic one was http://localhost:8080, '
			+ 'the SHARED development container, and this suite seeds AND DELETES OpenRegister '
			+ "objects. Running it there corrupts other people's environments. To go there on "
			+ `purpose, set ${SHARED_INSTANCE_FLAG} to that origin as well and read the header `
			+ 'of tests/e2e/base-url.ts first.',
	)
}

/** The resolved base URL for this run. */
export const BASE_URL = resolveBaseURL()

/**
 * Whether this run deliberately targets an instance shared with other people.
 *
 * False on CI even at `localhost:8080`: there the instance is the runner's own
 * throwaway, created and destroyed by the job.
 *
 * Every safety rule in this suite keys off this one constant.
 */
export const IS_SHARED_INSTANCE = isCI() === false && isSharedOrigin(BASE_URL)

/**
 * Whether this run owns the instance it is testing, and may therefore sweep
 * residue it cannot prove it created.
 */
export const OWNS_INSTANCE = IS_SHARED_INSTANCE === false

/**
 * Refuse to run when the target is shared with other people.
 *
 * For work a spec must never do on the shared container, where a comment saying
 * so has already been tried and did not stop anybody. Call it from a
 * `test.beforeAll` so the refusal is reported as a failure, with the reason, and
 * not as a skip. A skip reads as "nothing to see here", which is the opposite of
 * the message.
 *
 * @param what   The spec or capability being refused.
 * @param reason Why it must not run on a shared instance.
 * @throws when this run targets a shared instance.
 */
export function refuseOnSharedInstance(what: string, reason: string): void {
	if (IS_SHARED_INSTANCE === false) return
	throw new Error(
		`[dossiq e2e] ${what} must not run on ${BASE_URL}.\n`
			+ `${reason}\n`
			+ `${SHARED_INSTANCE_FLAG} permits the suite on a shared instance. It does `
			+ 'not permit this.\n'
			+ 'Start a disposable rig and point the suite at that instead:\n\n'
			+ '    PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test\n',
	)
}
