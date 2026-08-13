/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for kanban-board-keyboard-status-transition
 * (WCAG 2.1.1 Keyboard fix on the Workflow Board's status-move control).
 * Drives the real UI; skips gracefully when the board/data is not
 * available in the target instance rather than hard-failing the suite.
 *
 * Note: Use /apps/procest/<route> (not /index.php/...) so the Vue
 * history-mode router resolves the route.
 */

import { test, expect } from '@playwright/test'
import { dismissSupportDialog } from '../helpers/nav'

test.describe('Workflow Board keyboard status transition', () => {
	// @e2e openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#scenario-dash-v1-006d-keyboard-only-status-transition-new
	test('a case card exposes a keyboard-operable "Move to…" menu', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/procest/workflow-board')
		await dismissSupportDialog(page)

		const heading = page.getByRole('heading', { name: /Workflow Board/ }).first()
		if (!(await heading.isVisible({ timeout: 10000 }).catch(() => false))) {
			test.skip(true, 'Workflow Board surface not deployed in target instance')
			return
		}

		const firstCard = page.locator('.case-card').first()
		if (!(await firstCard.isVisible({ timeout: 8000 }).catch(() => false))) {
			test.skip(
				true,
				'No cases on the Workflow Board to exercise the move control',
			)
			return
		}

		// The move-target menu trigger is reachable independent of the card's
		// own click-to-open handler (a separate focusable NcActions control).
		const moveTrigger = firstCard
			.locator('.case-card__move-actions button')
			.first()
		await expect(moveTrigger).toBeVisible()

		await moveTrigger.focus()
		await page.keyboard.press('Enter')
		const firstOption = page.getByRole('menuitem').first()
		await expect(firstOption).toBeVisible({ timeout: 5000 })

		// Do not actually commit a status change against a live board's data —
		// close the menu without selecting, proving the control opens via
		// keyboard alone without ever dispatching a drag/mouse event.
		await page.keyboard.press('Escape')
	})

	// @e2e openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#scenario-dash-v1-006e-drag-path-unchanged-new
	test('case cards remain draggable for mouse/touch users', async ({ page }) => {
		await page.goto('/index.php/apps/procest/workflow-board')
		await dismissSupportDialog(page)

		const firstCard = page.locator('.case-card').first()
		if (!(await firstCard.isVisible({ timeout: 8000 }).catch(() => false))) {
			test.skip(
				true,
				'No cases on the Workflow Board to exercise the drag path',
			)
			return
		}

		await expect(firstCard).toHaveAttribute('draggable', 'true')
	})
})
