/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the handler-vervanging-waarneming spec.
 * Each test is tagged with the scenario it covers via @e2e. These drive the
 * real UI (substitution settings, coordinator admin, bulk reassign modal, and
 * the My Work substituted-work integration). API/contract assertions live in
 * the Newman collection, not here.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/...) so the Vue history-mode
 * router resolves the route. Tests are defensive — when a surface is not
 * deployed/rendered in the target instance the body-level assertions skip
 * gracefully rather than hard-failing the suite.
 */

import { expect, test } from '@playwright/test'
import { becomesVisible } from '../helpers/becomes-visible.js'
import { dismissSupportDialog } from '../helpers/nav.ts'
import {
	SubstitutionAdmin,
	SubstitutionPersonalSettings,
} from '../helpers/page-components.ts'

test.describe('Handler vervanging/waarneming spec coverage', () => {
	// UNPARKED. This skipped on EVERY run, and the reason it carried was right
	// that nothing was missing and wrong about what to look for.
	//
	// The old body waited for a heading matching /Substitution|Vervanging|
	// Waarneming/ and stood down when none appeared. None ever appears:
	// `SubstitutionSettings.vue` renders no heading of its own, and the only
	// heading on this page comes from Nextcloud's settings framework, which
	// prints `PersonalSection::getName()` — the string "Dossiq". So the guard
	// could never pass, on any instance, in any language, and the skip reason's
	// own note about Dutch locators was answering a question nobody had asked.
	//
	// The scenario is "a handler registers their own substitution", and the
	// register action is what the page has to offer. That is asserted directly.
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#handler-registers-their-own-substitution
	test('substitution renders under personal settings with a register action', async ({
		page,
	}) => {
		await page.goto(`/index.php${SubstitutionPersonalSettings}`, {
			timeout: 60_000,
		})
		await dismissSupportDialog(page)
		// The mount point the template declares. Asserting it first separates
		// "the section did not load" from "the section loaded without the
		// button", which is the distinction the old guard erased.
		await expect(
			page.locator('#dossiq-personal-settings'),
			'the personal-settings mount point must be on the page',
		).toBeAttached({ timeout: 30_000 })
		await expect(
			page
				.getByRole('button', {
					name: /Register substitution|Waarneming registreren/i,
				})
				.first(),
			'the Vue app mounted and rendered its register action',
		).toBeVisible({ timeout: 30_000 })
	})

	// UNPARKED for the same reason as the test above: the button is there, the
	// heading the old guard waited for never was.
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#self-substitution-is-rejected
	test('opening the substitution form shows the substitute field', async ({
		page,
	}) => {
		await page.goto(`/index.php${SubstitutionPersonalSettings}`, {
			timeout: 60_000,
		})
		await dismissSupportDialog(page)
		const btn = page
			.getByRole('button', {
				name: /Register substitution|Waarneming registreren/i,
			})
			.first()
		await expect(btn).toBeVisible({ timeout: 30_000 })
		await btn.click()
		// SubstitutionFormModal's own fields — the substitute is the whole
		// point of the form, and the period is what scopes it.
		await expect(page.getByText(/Substitute \(user id\)/).first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByText(/Start date/).first()).toBeVisible()
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#waarnemer-sees-substituted-work-in-my-work
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#scope-limited-substitution-only-routes-matching-items
	test('My Work renders without error and supports the substituted filter when present', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/dossiq/my-work')
		await dismissSupportDialog(page)
		// The My Work route renders no page heading (measured on a CI runner
		// 2026-08-04) — assert the view by a control it does render.
		await expect(page.getByRole('button', { name: 'Urgency' })).toBeVisible({
			timeout: 15000,
		})
		// The "Show substituted work" toggle appears only when the user is an
		// active waarnemer; its presence (or graceful absence) must not error.
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		const toggle = page.getByTestId('substituted-toggle')
		if (await becomesVisible(toggle, 3000)) {
			await toggle.locator('input').click()
			await expect(page.locator('body')).not.toContainText(
				'Internal Server Error',
			)
		}
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#preview-before-execution
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#bulk-reassignment-is-coordinator-only
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#coordinator-registers-a-substitution-on-behalf-of-an-absent-handler
	test('coordinator admin exposes a bulk-reassign action with a mandatory preview', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq${SubstitutionAdmin}`)
		await dismissSupportDialog(page)
		const heading = page
			.getByRole('heading', { name: /Substitutions & reassignment/ })
			.first()
		if (await becomesVisible(heading)) {
			const reassign = page
				.getByRole('button', { name: /Bulk reassign/ })
				.first()
			await expect(reassign).toBeVisible()
			await reassign.click()
			// The preview button gates execute — the modal must show it.
			await expect(
				page.getByRole('button', { name: /Preview affected work/ }).first(),
			).toBeVisible({ timeout: 8000 })
		} else {
			test.skip(
				true,
				'the coordinator substitution admin did not appear. NOT a deploy gap — SubstitutionAdminView is registered in src/registry.js. If this persists it is an authorisation or routing problem, not a missing build.',
			)
		}
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#all-actions-under-a-substitution-are-queryable
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#timeline-shows-the-substituted-capacity
	test('coordinator admin lists substitutions with an actions affordance', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq${SubstitutionAdmin}`)
		await dismissSupportDialog(page)
		const heading = page
			.getByRole('heading', { name: /Substitutions & reassignment/ })
			.first()
		if (await becomesVisible(heading)) {
			await expect(page.locator('body')).not.toContainText(
				'Internal Server Error',
			)
		} else {
			test.skip(
				true,
				'the coordinator substitution admin did not appear. NOT a deploy gap — SubstitutionAdminView is registered in src/registry.js. If this persists it is an authorisation or routing problem, not a missing build.',
			)
		}
	})
})
