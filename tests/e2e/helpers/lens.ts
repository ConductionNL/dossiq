/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Choosing a lens on an index page, wherever the lens is rendered.
 *
 * Since e66fa9d43 ("four lenses in the strip, eleven behind a chip") the Cases
 * index sets `quickFilterMaxVisible: 4`. CnQuickFilterBar then renders only the
 * first four lenses as `role="tab"` pills; every other lens is a plain button
 * inside the panel of the `⋯` chip (`data-testid="cn-quick-filter-more"`), and
 * an active hidden lens moves its label onto that chip. A spec that looks for
 * `getByRole('tab', { name: 'Mine' })` therefore waits for a tab that the page
 * no longer has, and times out on the click.
 *
 * These helpers ask the page where the lens is instead of assuming. Which
 * lenses sit in the strip is the manifest's decision (the order of
 * `quickFilters`), and `tests/vitest/caseListLenses.spec.js` pins it, so the
 * e2e layer only has to reach the lens, not to know where it lives.
 */

import type { Locator, Page } from '@playwright/test'

import { expect } from '@playwright/test'

/**
 * The lens as an inline tab, when it is one.
 *
 * @param scope The page, or the element that holds the filter bar.
 * @param label The lens label, in either language.
 */
function inlineTab(scope: Page | Locator, label: RegExp): Locator {
	return scope.getByRole('tab', { name: label })
}

/**
 * The `⋯` overflow chip of the quick-filter bar.
 *
 * @param scope The page, or the element that holds the filter bar.
 */
export function lensOverflowChip(scope: Page | Locator): Locator {
	return scope.getByTestId('cn-quick-filter-more')
}

/**
 * The lens as an entry in the overflow panel. The panel is a popover, so it is
 * looked up from the page and not from the bar.
 *
 * @param page  The page.
 * @param label The lens label, in either language.
 */
function overflowEntry(page: Page, label: RegExp): Locator {
	return page.getByTestId('cn-quick-filter-more-item').filter({
		has: page.locator('.cn-quick-filter-bar__label', { hasText: label }),
	})
}

/**
 * Wait for the quick-filter bar to render, then report whether the lens is an
 * inline tab.
 *
 * @param page  The page.
 * @param label The lens label, in either language.
 * @param scope Where the bar is, the page by default.
 */
async function isInline(
	page: Page,
	label: RegExp,
	scope: Page | Locator,
): Promise<boolean> {
	await expect(scope.locator('.cn-quick-filter-bar').first()).toBeVisible({
		timeout: 30_000,
	})
	return (await inlineTab(scope, label).count()) > 0
}

/**
 * Choose a lens: click its tab, or open the overflow chip and click its entry.
 *
 * @param page  The page.
 * @param label The lens label, in either language.
 * @param scope Where the bar is, the page by default.
 */
export async function chooseLens(
	page: Page,
	label: RegExp,
	scope: Page | Locator = page,
): Promise<void> {
	if (await isInline(page, label, scope)) {
		await inlineTab(scope, label).click()
		return
	}
	await lensOverflowChip(scope).click()
	const entry = overflowEntry(page, label)
	await expect(
		entry,
		`the lens ${label} is neither a tab nor in the overflow panel`,
	).toHaveCount(1)
	await entry.click()
}

/**
 * Assert that a lens is the active one, wherever it is rendered: an inline tab
 * carries `aria-selected="true"`, a hidden lens names itself on the overflow
 * chip.
 *
 * @param page  The page.
 * @param label The lens label, in either language.
 * @param scope Where the bar is, the page by default.
 */
export async function expectLensActive(
	page: Page,
	label: RegExp,
	scope: Page | Locator = page,
): Promise<void> {
	if (await isInline(page, label, scope)) {
		await expect(inlineTab(scope, label)).toHaveAttribute(
			'aria-selected',
			'true',
		)
		return
	}
	await expect(
		lensOverflowChip(scope).locator('.cn-quick-filter-bar__label'),
	).toHaveText(label)
}

/**
 * Assert that a lens is active and that it is the ONLY active lens.
 *
 * A lens in the strip is the only `aria-selected` tab and leaves the overflow
 * chip bare. A lens behind the chip leaves every tab unselected and names
 * itself on the chip; two active hidden lenses would read "2 filters" there
 * instead, which `expectLensActive` already refuses.
 *
 * @param page  The page.
 * @param label The lens label, in either language.
 */
export async function expectOnlyLensActive(
	page: Page,
	label: RegExp,
): Promise<void> {
	await expectLensActive(page, label)
	const selected = page
		.getByRole('tab')
		.and(page.locator('[aria-selected="true"]'))
	if (await isInline(page, label, page)) {
		await expect(selected).toHaveCount(1)
		await expect(
			lensOverflowChip(page).locator('.cn-quick-filter-bar__label'),
		).toHaveCount(0)
		return
	}
	await expect(selected).toHaveCount(0)
}
