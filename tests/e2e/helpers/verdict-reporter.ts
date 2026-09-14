/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A reporter that refuses to let a run be read as more complete than it was.
 *
 * It does two things the built-in reporters do not.
 *
 * 1. A TEST THAT NEVER RAN FAILS THE RUN, BY NAME.
 *
 *    Playwright prints "31 did not run" as one yellow line under the tally,
 *    next to "0 failed". Nothing names those tests, the failure list does not
 *    contain them, and the skip census downstream counts them as skips. On
 *    2026-09-10 and 11, 51 of 106 E2E jobs ended with tests that never ran,
 *    and the only failure most of them listed was the one test that happened
 *    to be red, so a reader fixed that test and the next run truncated again.
 *
 *    Playwright's own run status already turns red when `globalTimeout` stops
 *    the run. What was missing is the SENTENCE: how many tests this run did
 *    not decide, which ones, and why. This reporter prints that, as a GitHub
 *    error annotation and in the job summary, and returns `failed` from
 *    `onEnd`, so the exit code is non-zero even on a path where Playwright's
 *    would not be. "Did not run" and "interrupted" are counted exactly the way
 *    Playwright's summary counts them, so the two can never disagree.
 *
 * 2. A TIMEOUT NAMES THE STEP IT HAPPENED IN.
 *
 *    "Test timeout of 60000ms exceeded." is the whole message when the budget
 *    runs out between Playwright calls, or while a call is still pending. The
 *    trace of case-flow-human-steps.spec.ts:205 on run 34578124024 shows the
 *    shape: two page loads of 21.8s and 21.0s, a third 15s into loading when
 *    the 60s budget ran out, and a message that named none of them. This
 *    reporter tracks which steps are open, and on a timeout prints the one
 *    that was running and the longest steps that spent the budget, so the
 *    failure says "a page load was slow" or "the teardown was on its case
 *    sweep" instead of only that time ran out.
 */

import type {
	FullConfig,
	FullResult,
	Reporter,
	Suite,
	TestCase,
	TestResult,
	TestStep,
} from '@playwright/test/reporter'

import * as fs from 'fs'

/** How many unrun tests to name in the log. All of them go to the summary. */
const LOG_LIMIT = 60

/**
 * The short, stable name a test is referred to by in CI output.
 *
 * @param test The test.
 */
function label(test: TestCase): string {
	const file = test.location.file.replace(/^.*\/tests\/e2e\//, '')
	const project = test.parent.project()?.name ?? ''
	const title = test.titlePath().slice(3).join(' › ')
	return `[${project}] ${file}:${test.location.line} ${title}`
}

/**
 * Seconds, one decimal.
 *
 * @param ms Milliseconds.
 */
function seconds(ms: number): string {
	return `${(ms / 1000).toFixed(1)}s`
}

/**
 * Whether a step is one worth naming: a Playwright call, an expectation or a
 * named `test.step`, rather than the hook and fixture wrappers around them.
 *
 * @param step The step.
 */
function nameable(step: TestStep): boolean {
	return ['pw:api', 'expect', 'test.step'].includes(step.category)
}

/**
 * Append a block to the GitHub job summary, when there is one.
 *
 * @param markdown The block.
 */
function summary(markdown: string): void {
	const file = process.env.GITHUB_STEP_SUMMARY
	if (!file) return
	try {
		fs.appendFileSync(file, `${markdown}\n`)
	} catch {
		// A summary that cannot be written must not change the verdict.
	}
}

/**
 * Emit a GitHub error annotation. Off CI the plain lines around it say the
 * same thing, so nothing is printed twice.
 *
 * @param title   Annotation title.
 * @param message One line.
 */
function annotate(title: string, message: string): void {
	if (process.env.GITHUB_ACTIONS !== 'true') return
	const clean = message.replace(/\r?\n/g, ' ')
	process.stdout.write(`::error title=${title}::${clean}\n`)
}

export default class VerdictReporter implements Reporter {
	private suite: Suite | undefined
	private globalTimeout = 0
	private readonly open = new Map<TestResult, Set<TestStep>>()
	private readonly timeouts: string[] = []

	onBegin(config: FullConfig, suite: Suite): void {
		this.suite = suite
		this.globalTimeout = config.globalTimeout
	}

	onStepBegin(_test: TestCase, result: TestResult, step: TestStep): void {
		if (!nameable(step)) return
		let steps = this.open.get(result)
		if (steps === undefined) {
			steps = new Set()
			this.open.set(result, steps)
		}
		steps.add(step)
	}

	onStepEnd(_test: TestCase, result: TestResult, step: TestStep): void {
		this.open.get(result)?.delete(step)
	}

	onTestEnd(test: TestCase, result: TestResult): void {
		const stillOpen = [...(this.open.get(result) ?? [])]
		this.open.delete(result)
		if (result.status !== 'timedOut') return

		// The step that was running when the budget ran out: the most recently
		// started one still open. A timeout between calls leaves none open, and
		// then the longest steps below are the whole answer.
		const running = stillOpen.sort(
			(a, b) => b.startTime.getTime() - a.startTime.getTime(),
		)[0]
		// Now, not the result's own end: a hook's time is not in its duration.
		const end = Date.now()

		const finished: TestStep[] = []
		const walk = (steps: TestStep[]): void => {
			for (const step of steps) {
				if (nameable(step) && step.duration >= 0) finished.push(step)
				walk(step.steps)
			}
		}
		walk(result.steps)
		const longest = finished
			.filter((step) => step !== running && step.steps.length === 0)
			.sort((a, b) => b.duration - a.duration)
			.slice(0, 4)
			.map((step) => `${step.title} (${seconds(step.duration)})`)

		const loads = finished.filter((step) => step.title.startsWith('Navigate'))
		const loadTime = loads.reduce((sum, step) => sum + step.duration, 0)

		// Name the running step with the named steps it sits inside, so a
		// teardown reads "teardown: remove 17 case row(s) › DELETE ..." rather
		// than one request out of context.
		const chain: string[] = []
		for (let step: TestStep | undefined = running; step; step = step.parent) {
			if (nameable(step)) chain.unshift(step.title)
		}
		const during = running
			? `during "${chain.join(' › ')}", ${seconds(end - running.startTime.getTime())} after it started`
			: 'between steps, with no Playwright call pending'
		// A hook that times out fails the last test in its file, with that
		// hook's budget rather than the test's, so the budget is read from the
		// error when the error names one.
		const hook = /"(\w+)" hook timeout of (\d+)ms/.exec(
			result.errors.map((error) => error.message ?? '').join('\n'),
		)
		const budget = hook
			? `its ${hook[1]} hook's ${seconds(Number(hook[2]))} budget`
			: `its ${seconds(test.timeout)} budget`
		const message =
			`${label(test)} ran out of ${budget} ${during}. `
			+ `It had made ${loads.length} page load(s) costing ${seconds(loadTime)}. `
			+ `Longest finished steps: ${longest.join(', ') || 'none'}.`

		this.timeouts.push(message)
		process.stdout.write(`\n  ⏱ ${message}\n`)
	}

	async onEnd(
		result: FullResult,
	): Promise<{ status: FullResult['status'] } | undefined> {
		if (this.suite === undefined) return undefined

		if (this.timeouts.length > 0) {
			summary(
				'### Timeouts, and the step each one was in\n\n'
					+ this.timeouts.map((line) => `- ${line}`).join('\n')
					+ '\n',
			)
		}

		// Counted exactly as Playwright's own summary counts them, so the two
		// numbers can never disagree: `interrupted` is a result with that
		// status, and a skip that no one declared (no result at all, or a
		// runtime skip of a test that expected to pass, such as a serial group
		// stopping at its first failure) is "did not run".
		const tests = this.suite.allTests()
		const interrupted: TestCase[] = []
		const didNotRun: TestCase[] = []
		for (const test of tests) {
			if (test.outcome() !== 'skipped') continue
			if (test.results.some((r) => r.status === 'interrupted')) {
				interrupted.push(test)
			} else if (
				test.results.length === 0
				|| test.expectedStatus !== 'skipped'
			) {
				didNotRun.push(test)
			}
		}

		// `--list` runs no test at all, so it would read as a whole suite that
		// never ran. Nothing ran and nothing was stopped: there is no verdict
		// to protect, so say nothing.
		const anyResult = tests.some((test) => test.results.length > 0)
		if (anyResult === false && result.status === 'passed') return undefined

		const undecided = didNotRun.length + interrupted.length
		if (undecided === 0) {
			process.stdout.write(
				`\n  Verdict: all ${tests.length} tests reached a verdict.\n`,
			)
			return undefined
		}

		const minutes = this.globalTimeout / 60_000
		const why =
			result.status === 'timedout'
				? `the run was stopped by its ${Number.isInteger(minutes) ? minutes : minutes.toFixed(1)} minute globalTimeout`
				: 'a project they depend on had failures, or a serial group stopped at its first failure'
		const headline =
			`${didNotRun.length} of ${tests.length} tests did not run and `
			+ `${interrupted.length} were interrupted, because ${why}. This run did not `
			+ 'decide them, so it cannot be read as green whatever the passed count says.'
		annotate('E2E run incomplete', headline)

		const named = [
			...interrupted.map((test) => `interrupted  ${label(test)}`),
			...didNotRun.map((test) => `did not run  ${label(test)}`),
		]
		process.stdout.write(`\n  ${headline}\n`)
		for (const line of named.slice(0, LOG_LIMIT)) {
			process.stdout.write(`    ${line}\n`)
		}
		if (named.length > LOG_LIMIT) {
			process.stdout.write(
				`    ... and ${named.length - LOG_LIMIT} more, listed in the job summary\n`,
			)
		}
		summary(
			'### E2E run incomplete\n\n'
				+ `${headline}\n\n`
				+ named.map((line) => `- \`${line}\``).join('\n')
				+ '\n',
		)

		return { status: 'failed' }
	}

	printsToStdio(): boolean {
		// The list reporter owns the terminal; this one only adds lines to it.
		return false
	}
}
