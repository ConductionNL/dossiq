<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The setup wizard may not offer a step it cannot fulfil.
 *
 * `CnSetupWizard` renders `manifest.setup.steps[]` verbatim. It has no way to
 * hide a step at runtime and no notion of a step being unavailable, so a step
 * whose only possible outcome is an error is a broken affordance from the
 * moment it renders.
 *
 * The wizard shipped exactly that. Its `seed` step ran the `seed` action, which
 * read `caseTypes` out of the old `bezwaar_seed_data.json`, a key parked since
 * commit 382e953d curated an English demo in
 * `lib/Settings/register.d/46-demo-cases-english.json` instead. Every click
 * answered `422 Nothing to seed`, so the step went.
 *
 * The `seed` action now installs the on-request `bezwaar-beroep` package, so
 * it has something to install again. The wizard offers on-request packages
 * through the Case types step (`install-case-types`, one card per package),
 * so the single-package `seed` step stays out.
 *
 * Two assertions, and the second is the one that matters. The first says every
 * `run-action` step names an action the controller handles. The second says a
 * step may not be backed by an empty dataset.
 */
class SetupWizardStepsTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The shipped manifest, decoded.
	 *
	 * @return array<string, mixed> The manifest.
	 */
	private function manifest(): array {
		$manifest = json_decode((string)file_get_contents(self::ROOT . '/src/manifest.json'), true);
		$this->assertIsArray($manifest, 'src/manifest.json did not parse');

		return $manifest;
	}

	/**
	 * The steps the wizard offers that post a setup action, in order.
	 *
	 * A `run-action` step posts its `action`. A cards choice step posts its
	 * `loadAction` from each card's Load button (wizard-dataset-card-load), so
	 * it is held to the same rule.
	 *
	 * @return array<int, array{id: string, action: string}> The steps.
	 */
	private function runActionSteps(): array {
		$steps = [];
		foreach ((array)((array)($this->manifest()['setup'] ?? []))['steps'] ?? [] as $step) {
			$step = (array)$step;
			$action = '';
			if ((string)($step['type'] ?? '') === 'run-action') {
				$action = (string)($step['action'] ?? '');
			} else if (isset($step['loadAction']) === true) {
				$action = (string)$step['loadAction'];
			}

			if ($action === '') {
				continue;
			}

			$steps[] = [
				'id' => (string)($step['id'] ?? ''),
				'action' => $action,
			];
		}

		return $steps;
	}

	/**
	 * Every action-posting step names an action SetupController handles.
	 *
	 * An unhandled id falls through to the controller's `Unknown setup action`
	 * branch, which answers 404 — the same shape of dead step, arrived at a
	 * different way.
	 *
	 * @return void
	 */
	public function testEveryRunActionStepNamesAnActionTheControllerHandles(): void {
		$steps = $this->runActionSteps();
		$this->assertGreaterThan(0, count($steps), 'The sweep found no action-posting steps, so an all-clear says nothing');

		$controller = (string)file_get_contents(self::ROOT . '/lib/Controller/SetupController.php');

		$offenders = [];
		foreach ($steps as $step) {
			if (str_contains($controller, "'" . $step['action'] . "'") === true) {
				continue;
			}

			$offenders[] = sprintf('step "%s" runs action "%s", which SetupController never matches', $step['id'], $step['action']);
		}

		$this->assertSame(
			[],
			$offenders,
			"A step whose action id the controller does not handle answers 404 on every click:\n" . implode("\n", $offenders)
		);
	}

	/**
	 * No offered step is backed by an empty shipped dataset.
	 *
	 * Only the `seed` action is dataset-backed today, so the map has one entry.
	 * The check is written as a map rather than as one `if` so a second
	 * dataset-backed action arrives with its emptiness check already written.
	 *
	 * @return void
	 */
	public function testNoOfferedStepIsBackedByAnEmptyDataset(): void {
		// action id => [file, the key the seeder actually reads].
		$backing = ['seed' => ['lib/Settings/packages/bezwaar-beroep/case-type.json', 'caseType']];

		$offenders = [];
		$examined = 0;
		foreach ($this->runActionSteps() as $step) {
			$source = ($backing[$step['action']] ?? null);
			if ($source === null) {
				continue;
			}

			$examined++;
			$data = json_decode((string)file_get_contents(self::ROOT . '/' . $source[0]), true);
			$rows = (array)(((array)$data)[$source[1]] ?? []);
			if (count($rows) > 0) {
				continue;
			}

			$offenders[] = sprintf(
				'step "%s" runs "%s", which reads `%s` from %s. That key holds nothing, so the step can only answer 422.',
				$step['id'],
				$step['action'],
				$source[1],
				$source[0]
			);
		}

		$this->assertSame(
			[],
			$offenders,
			"The wizard offers a step it cannot fulfil:\n" . implode("\n", $offenders)
		);

		// The positive control. With the seed step removed nothing in the map
		// is currently offered, and an assertion over an empty loop is an
		// assertion about nothing, so pin that the backing still exists: the
		// package the `seed` action installs carries case types.
		if ($examined === 0) {
			$data = json_decode((string)file_get_contents(self::ROOT . '/' . $backing['seed'][0]), true);
			$this->assertNotSame(
				[],
				(array)(((array)$data)[$backing['seed'][1]] ?? []),
				'the package the `seed` action installs carries no case type'
			);
		}
	}

	/**
	 * The seed step is not offered until offering it is decided.
	 *
	 * The specific regression, pinned by id. Without it the sweep above would
	 * pass just as happily on a manifest that declares no setup block at all.
	 *
	 * @return void
	 */
	public function testTheWizardDoesNotOfferTheParkedBezwaarSeed(): void {
		$ids = [];
		foreach ((array)((array)($this->manifest()['setup'] ?? []))['steps'] ?? [] as $step) {
			$ids[] = (string)((array)$step)['id'];
		}

		$this->assertContains('demo-data', $ids, 'The demo-data offer is what actually shows a new reader the app working');
		$this->assertNotContains('seed', $ids, 'On-request packages are offered per card by the case-types step, not by a seed step');
	}
}
