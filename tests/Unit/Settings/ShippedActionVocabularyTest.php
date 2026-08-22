<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Procest\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Settings;

use OCA\Procest\Repair\Vth\VthWorkflowGraphResolver;
use PHPUnit\Framework\TestCase;

/**
 * Asserts every automatic action procest SHIPS is one the engine can RUN.
 *
 * This is the control that was missing. Nothing — not the schema, not the
 * seeder, not a gate — checked a catalog file's action `type` against the
 * vocabulary the dispatcher implements, so `spawnCase` sat in the shipped VTH
 * catalog answering to nothing: the transition reported `ok`, persisted a
 * dispatched-actions record, and spawned no handhavingstraject at all.
 *
 * The accepted grammar has to be the executable one. Here the executable
 * grammar is read from ActionHandlerRegistry's own map rather than restated,
 * so adding a handler widens this test automatically and removing one narrows
 * it — a copy of the list would drift the same way the catalog did.
 */
class ShippedActionVocabularyTest extends TestCase {
	/**
	 * Every JSON file procest ships under lib/Settings.
	 *
	 * @return array<int, string> Absolute file paths.
	 */
	private function shippedJsonFiles(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
		foreach ($iterator as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'json') {
				$files[] = $file->getPathname();
			}
		}

		sort($files);

		return $files;
	}

	/**
	 * The action types the dispatcher can actually resolve.
	 *
	 * Read from ActionHandlerRegistry's constructor body — the single place the
	 * live map is written — instead of being restated here.
	 *
	 * @return array<int, string> The executable action types.
	 */
	private function executableTypes(): array {
		$source = (string)file_get_contents(
			__DIR__ . '/../../../lib/Service/Transitions/ActionHandlerRegistry.php'
		);
		$matches = [];
		preg_match('/\$this->handlers\s*=\s*\[(.*?)\];/s', $source, $matches);
		$this->assertNotEmpty($matches, 'Could not read the live handler map from ActionHandlerRegistry.');

		$types = [];
		preg_match_all("/'([a-zA-Z][a-zA-Z0-9_]*)'\s*=>/", $matches[1], $types);

		return $types[1];
	}

	/**
	 * Collect every automaticActions[]/autoActions[] entry in a decoded file.
	 *
	 * @param mixed $node The current JSON node.
	 * @param string $path The JSON pointer walked so far.
	 * @param array<int, array{path: string, action: array<string, mixed>}> $found Accumulator.
	 *
	 * @return void
	 */
	private function collectActions(mixed $node, string $path, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			if (($key === 'automaticActions' || $key === 'autoActions') && is_array($value) === true) {
				foreach ($value as $index => $action) {
					if (is_array($action) === true) {
						$found[] = ['path' => $path . '.' . $key . '[' . $index . ']', 'action' => $action];
					}
				}
			}

			$this->collectActions(node: $value, path: $path . '.' . (string)$key, found: $found);
		}
	}

	/**
	 * Every shipped inline action names a type the dispatcher implements.
	 *
	 * The only exemptions are the types VthWorkflowGraphResolver declares it
	 * REWRITES before anything is stored (`spawnCase` names its target by
	 * template slug, which only the seeder can resolve to a caseType UUID). The
	 * exemption list is read from the resolver, so a type can only be exempt if
	 * the code that rewrites it exists — and VthWorkflowGraphResolverTest is
	 * what proves the rewrite actually happens.
	 *
	 * @return void
	 */
	public function testEveryShippedActionTypeIsExecutable(): void {
		$executable = $this->executableTypes();
		$this->assertNotEmpty($executable, 'The live handler map came back empty.');

		$offenders = [];
		foreach ($this->shippedJsonFiles() as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			$found = [];
			$this->collectActions(node: $data, path: '', found: $found);
			foreach ($found as $entry) {
				$type = (string)($entry['action']['type'] ?? '');
				if ($type === ''
					|| in_array($type, VthWorkflowGraphResolver::NORMALISED_TYPES, true) === true
					|| in_array($type, $executable, true) === true
				) {
					continue;
				}

				$offenders[] = basename($file) . ' ' . $entry['path'] . ' type=' . $type;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"Shipped automatic actions name types no handler implements. Each one is a SILENT no-op:\n"
			. implode("\n", $offenders)
			. "\nExecutable types: " . implode(', ', $executable)
		);
	}

	/**
	 * Every shipped `spawnCase` carries a target the seeder can normalise.
	 *
	 * Without `targetWorkflowSlug` the resolver drops the action, so a catalog
	 * entry that omits it ships a promise nothing keeps.
	 *
	 * @return void
	 */
	public function testEverySpawnCaseNamesAKnownTemplate(): void {
		$templateSlugs = [];
		foreach (glob(__DIR__ . '/../../../lib/Settings/seed/vth-workflow-templates/*.json') ?: [] as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === true && ($data['caseTypeSlug'] ?? '') !== '') {
				$templateSlugs[] = (string)($data['slug'] ?? '');
			}
		}

		$offenders = [];
		foreach ($this->shippedJsonFiles() as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			$found = [];
			$this->collectActions(node: $data, path: '', found: $found);
			foreach ($found as $entry) {
				if ((string)($entry['action']['type'] ?? '') !== 'spawnCase') {
					continue;
				}

				$target = (string)($entry['action']['config']['targetWorkflowSlug'] ?? '');
				if ($target === '' || in_array($target, $templateSlugs, true) === false) {
					$offenders[] = basename($file) . ' ' . $entry['path'] . ' target=' . ($target ?: '(missing)');
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"spawnCase actions naming an unknown template are dropped at seed time:\n"
			. implode("\n", $offenders)
			. "\nKnown templates: " . implode(', ', $templateSlugs)
		);
	}
}
