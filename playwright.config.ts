import { defineConfig, devices } from '@playwright/test'
import path from 'path'

const STORAGE_STATE = path.join(__dirname, 'tests/e2e/.auth/user.json')

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 30000,
	expect: { timeout: 10000 },
	fullyParallel: false,
	retries: 1,
	workers: 1,
	reporter: [
		// Output paths match the shared quality.yml workflow's artifact-upload
		// paths (server/apps/<app>/playwright-report and .../test-results) so
		// the HTML report + failure screenshots/traces actually get uploaded.
		['html', { open: 'never', outputFolder: 'playwright-report' }],
		['junit', { outputFile: 'test-results/results.xml' }],
	],
	outputDir: 'test-results',
	globalSetup: './tests/e2e/global-setup.ts',

	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		storageState: STORAGE_STATE,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		// Default regression project — excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts'],
			use: { ...devices['Desktop Chrome'] },
		},

		// Documentation capture project (ADR-030). Opt-in run:
		//
		//   npx playwright test --project docs-capture
		//
		// Output lands in `docs/static/screenshots/tutorials/{user,admin}/`.
		// See `tests/e2e/docs-screenshots.spec.ts`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 90_000,
		},
	],
})
