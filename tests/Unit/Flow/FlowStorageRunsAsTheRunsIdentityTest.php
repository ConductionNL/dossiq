<?php

/**
 * The class-catching test for the runAs defect family.
 *
 * THE DEFECT CLASS. OpenRegister's permission gate reads the AMBIENT SESSION
 * user, not any parameter, and under FlowRunWorker that session carries
 * nobody. So every flow handler or node that touches ObjectService storage
 * without routing through {@see \OCA\Dossiq\Service\FlowRunAsScope} is
 * refused as "User 'Anonymous' does not have permission" the moment a flow
 * runs on the worker — however legitimate the run's declared identity. This
 * shipped four separate times: SetStatusHandler, DossiqAskPersonNode and
 * DossiqRequestDecisionNode were fixed by DQ#1625, and MergeTemplateHandler
 * still stopped the seeded case flow at `besluit-document` (run f087ae22).
 * Fixing instances one by one is how the fourth one shipped, so this test
 * asserts the INVARIANT over every file in the three flow-facing directories.
 *
 * THE RULE. A file under lib/Flow, lib/Service/Transitions or
 * lib/Service/Actions that performs storage work — directly through
 * ObjectService, or by delegating to a collaborator known to write through
 * it — must reference `runAsScope->call(`, or sit in the closed allowlist
 * below with a reason that names why the worker never executes it.
 *
 * WHAT THIS CANNOT SEE. The check is per file: a file that wraps one storage
 * call and ships a second bare one still passes. The per-handler regression
 * tests (e.g. MergeTemplateHandlerTest, SetStatusHandlerTest) carry that
 * finer assertion; this test exists so a NEW handler cannot ship with no
 * scope at all.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Structural assertion over source files, not behaviour.
 */
class FlowStorageRunsAsTheRunsIdentityTest extends TestCase {

	/**
	 * The directories whose classes execute under FlowRunWorker.
	 *
	 * lib/Flow holds the nodes the engine runs; lib/Service/Transitions and
	 * lib/Service/Actions hold the handlers those nodes are thin wrappers
	 * around (see DossiqFlowNodeBase: the flow context is handed to them as
	 * `$transitionContext`).
	 *
	 * @var string[]
	 */
	private const FLOW_DIRECTORIES = [
		'lib/Flow',
		'lib/Service/Transitions',
		'lib/Service/Actions',
	];

	/**
	 * Direct storage work: calls that reach OpenRegister's ObjectService.
	 *
	 * @var string
	 */
	private const STORAGE_CALL_PATTERN = '/->\s*(saveObject|updateObject|deleteObject|find|findAll|searchObjectsPaginated|buildSearchQuery|getObjectService)\s*\(/';

	/**
	 * Collaborators that perform ObjectService storage on the caller's
	 * behalf. A flow file that hands its work to one of these performs
	 * storage just as surely as one calling saveObject() itself — the
	 * BesluitvormingPublishHandler variant of the defect.
	 *
	 * @var string[]
	 */
	private const STORAGE_COLLABORATORS = [
		'ParaafFlowLinkage',
		'PublicationService',
		'BesluitvormingParafeerService',
		'CaseStatusStore',
		'StatusTypeLookup',
		'DecisionTableService',
	];

	/**
	 * The CLOSED allowlist of storage-performing flow files that run bare,
	 * each with the reason the worker never executes it. An entry here is a
	 * claim to re-verify when the file's callers change — and an entry whose
	 * file stops matching (or disappears) fails the suite, so the list
	 * cannot rot silently.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_BARE = [
		'lib/Service/Transitions/CaseStatusStore.php' => 'Called only by StatusTransitionService on the interactive request path, where the logged-in session user is the acting identity.',
		'lib/Service/Transitions/StatusTypeLookup.php' => 'A read helper that runs inside its caller\'s scope: SetStatusHandler invokes it within its own runAsScope->call().',
		'lib/Service/Transitions/ChecklistGuard.php' => 'A transition guard evaluated by GuardRegistry on the interactive transition path (StatusTransitionService / WorkflowEngineService), never by a flow node.',
		'lib/Service/Actions/AutomaticActionFlowMigrator.php' => 'A one-shot migration run from the occ command (MigrateAutomaticActionsToFlowsCommand), an admin CLI context with an operator at the keyboard.',
	];

	/**
	 * 🔴 EVERY STORAGE-PERFORMING FLOW FILE ROUTES THROUGH FlowRunAsScope.
	 *
	 * Unwrap any wrapped handler — delete its `runAsScope->call(` — and this
	 * goes red naming the file. Add a new handler with a bare saveObject()
	 * and it goes red before the handler ever meets the worker.
	 */
	public function testEveryStoragePerformingFlowFileRoutesThroughTheRunAsScope(): void {
		$bare = [];
		$flagged = [];

		foreach ($this->flowFiles() as $relative => $source) {
			if ($this->performsStorage(source: $source) === false) {
				continue;
			}

			$flagged[] = $relative;

			if (str_contains($source, 'runAsScope->call(') === true) {
				continue;
			}

			if (array_key_exists($relative, self::ALLOWED_BARE) === true) {
				continue;
			}

			$bare[] = $relative;
		}//end foreach

		self::assertNotSame(
			[],
			$flagged,
			'The sweep found no storage-performing flow files at all: the detector is broken, not the tree clean.'
		);

		self::assertSame(
			[],
			$bare,
			"These flow files perform ObjectService storage without routing through FlowRunAsScope. Under FlowRunWorker their work is refused as 'Anonymous' no matter whose rights the run declares. Wrap the storage work in \$this->runAsScope->call(context: ..., operation: ...) the way SetStatusHandler does, or — only when the worker truly never executes the file — add it to ALLOWED_BARE with the reason:\n - "
			. implode("\n - ", $bare)
		);
	}//end testEveryStoragePerformingFlowFileRoutesThroughTheRunAsScope()

	/**
	 * The allowlist stays honest: every entry still names a file that exists
	 * AND still performs storage. A stale entry is a claim nobody is
	 * checking any more, so it fails rather than lingers.
	 */
	public function testTheAllowlistCarriesNoStaleEntries(): void {
		$files = $this->flowFiles();

		foreach (self::ALLOWED_BARE as $relative => $reason) {
			self::assertNotSame('', trim($reason), 'An allowlist entry must carry its reason: ' . $relative);
			self::assertArrayHasKey(
				$relative,
				$files,
				'Allowlisted file no longer exists — remove the entry: ' . $relative
			);
			self::assertTrue(
				$this->performsStorage(source: $files[$relative]),
				'Allowlisted file no longer performs storage — remove the entry: ' . $relative
			);
		}
	}//end testTheAllowlistCarriesNoStaleEntries()

	/**
	 * Whether a source file performs storage work, directly or by delegating
	 * to a collaborator that writes through ObjectService.
	 *
	 * @param string $source The file's source code.
	 *
	 * @return bool True when the file performs storage work.
	 */
	private function performsStorage(string $source): bool {
		if (preg_match(self::STORAGE_CALL_PATTERN, $source) === 1) {
			return true;
		}

		foreach (self::STORAGE_COLLABORATORS as $collaborator) {
			// The import line, so a docblock mention alone does not flag.
			if (preg_match('/^use .*\\\\' . preg_quote($collaborator, '/') . ';$/m', $source) === 1) {
				return true;
			}
		}

		return false;
	}//end performsStorage()

	/**
	 * Every PHP source file in the flow-facing directories.
	 *
	 * @return array<string, string> Relative path => source.
	 */
	private function flowFiles(): array {
		$root = dirname(__DIR__, 3);
		$files = [];

		foreach (self::FLOW_DIRECTORIES as $directory) {
			$paths = glob($root . '/' . $directory . '/*.php');
			self::assertNotFalse($paths, 'Could not list ' . $directory);
			self::assertNotSame([], $paths, 'No PHP files under ' . $directory . ': the sweep is scanning the wrong tree.');

			foreach ($paths as $path) {
				$source = file_get_contents($path);
				self::assertIsString($source, 'Could not read ' . $path);
				$files[substr($path, (strlen($root) + 1))] = $source;
			}
		}

		return $files;
	}//end flowFiles()
}//end class
