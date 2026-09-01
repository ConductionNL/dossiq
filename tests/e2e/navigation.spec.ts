import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const sidebarNav = (page: Page) => page.locator('[id^="app-navigation"]').first()

test.describe('Sidebar Navigation', () => {
	test('shows all navigation items', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq')
		const nav = sidebarNav(page)

		// Main route entries that render as visible app-navigation links.
		// ("Documentation" is a `section: "footer"` external link in the
		// manifest — it lives in a collapsed footer area and is not a visible
		// top-level nav entry, so it is asserted separately below.)
		// Labels and visibility measured on a CI runner (2026-08-04). The
		// manifest ships "My work" (lower-case w), NOT "My Work", and a flat
		// "Cases" leaf — the "All cases" label this spec used to assert was
		// removed when the "Cases" GROUP IA was reverted, so it matched zero
		// links and the assertion could only ever fail.
		//
		// Only "Dashboard" and "Cases" are top-level VISIBLE leaves. The rest
		// render inside collapsed groups ("Work queue", "Personal settings"),
		// so they are present in the DOM but `display:none` until their group
		// is expanded — asserted separately below.
		for (const label of ['Dashboard', 'Cases']) {
			await expect(
				nav.getByRole('link', { name: label, exact: true }),
			).toBeVisible()
		}

		// Collapsed-group leaves: present in the navigation, hidden until the
		// group is expanded. They must be matched by CSS, NOT by getByRole —
		// `display:none` removes an element from the accessibility tree, so
		// `getByRole` resolves to 0 elements even under `toHaveCount`.
		//
		// `/doorlooptijd` USED TO BE HERE and is deliberately gone (ADR-112,
		// dossiq#1583). Reports are no longer a nav group with one leaf per
		// report; they are one page of cards, reached from the footer. The
		// page stays routable — every other spec still reaches it with a
		// direct GET — but it no longer has a navigation entry of its own,
		// and asserting one is asserting the IA we retired.
		for (const href of ['/my-work', '/workflow-board']) {
			await expect(nav.locator(`a[href$="${href}"]`)).toHaveCount(1)
		}

		// And the group headers themselves are visible toggles. "Reports" is
		// NOT among them any more: it is a footer link to the reports page,
		// asserted below.
		for (const group of ['Work queue']) {
			await expect(
				nav.getByRole('link', { name: group, exact: true }),
			).toBeVisible()
		}

		// The reports page itself, in the footer between Documentation and
		// Features & roadmap. This is the entry the three retired report
		// leaves were replaced BY, so its absence would mean the reports
		// became unreachable rather than regrouped.
		//
		// Matched by TEXT, like the Documentation footer link below it and for
		// the same measured reason: a `section: "footer"` entry does not
		// render as a plain top-level `<a>` the way a nav leaf does, so an
		// href locator is the wrong instrument here.
		await expect(nav.getByText('Reports', { exact: true })).toHaveCount(1)

		// The Documentation footer link is present in the navigation DOM.
		await expect(nav.getByText('Documentation', { exact: true })).toHaveCount(1)
	})

	test('sidebar links point to correct URLs', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq')
		const nav = sidebarNav(page)

		// Hrefs measured on a CI runner (2026-08-04). Note the rendered hrefs
		// carry the `/index.php` prefix — the bare `/apps/dossiq/...` values
		// this spec used to assert never matched what the nav actually emits.
		// "Tasks" is not a top-level nav entry; its /tasks route stays
		// deep-linkable and is covered by pages.spec.ts.
		// "Cases" is a visible top-level leaf, so it can be matched by role.
		await expect(
			nav.getByRole('link', { name: 'Cases', exact: true }),
		).toHaveAttribute('href', '/index.php/apps/dossiq/cases')

		// The rest live in collapsed groups and are therefore absent from the
		// accessibility tree — assert their href wiring via the DOM, checking
		// the label text travels with the expected link.
		const byHref = async (href: string, label: string) => {
			const link = nav.locator(`a[href="${href}"]`)
			await expect(link).toHaveCount(1)
			await expect(link).toHaveText(new RegExp(label))
		}
		await byHref('/index.php/apps/dossiq/my-work', 'My work')
		await byHref('/index.php/apps/dossiq/workflow-board', 'Workflow board')
	})

	test('settings button is visible', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq')
		// Settings button at the bottom of the app sidebar — the platform gear
		// foldout, which legitimately says "Settings".
		//
		// The testid is still the right target, but the collision it was
		// guarding against is gone: the SettingsMenu nav entry was relabelled
		// "Configuration" under ADR-079 D4, precisely because two things
		// reading "Settings" rendered "Settings > Settings".
		await expect(
			page
				.getByTestId('cn-nav-settings')
				.getByRole('button', { name: 'Settings' }),
		).toBeVisible()
	})

	test('clicking nav item navigates', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq')

		// A "Support Dossiq" dialog can auto-open over the app and intercept
		// pointer events on the navigation. Dismiss it if present.
		const supportDialog = page.locator('[data-testid-modal="cn-support-dialog"]')
		if (await supportDialog.isVisible().catch(() => false)) {
			await supportDialog.getByRole('button', { name: 'Close' }).click()
			await expect(supportDialog).toBeHidden()
		}

		const nav = sidebarNav(page)
		// "Cases" is a flat, visible, directly-navigating leaf — the "All
		// cases" label this used to click does not exist in the rendered nav.
		await nav.getByRole('link', { name: 'Cases', exact: true }).click()
		await expect(page).toHaveURL(/.*cases/)
	})
})
