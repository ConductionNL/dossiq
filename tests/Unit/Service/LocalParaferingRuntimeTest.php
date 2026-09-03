<?php

/**
 * The class-catching test for a local parafering runtime.
 *
 * THE DIRECTIVE, the twin of {@see LocalDecisionAuthoringTest}. dossiq owns
 * cases; the decision app owns the parafering RUNTIME. A voorstel's chain is
 * raised in the decision app ({@see \OCA\Dossiq\Service\Parafeer\ParaferingRaiseService},
 * {@see \OCA\Dossiq\Service\Parafeer\ParaferingDelegationService}) and what the
 * case stores is the outcome that app concluded, written as a projection by
 * {@see \OCA\Dossiq\Service\Parafeer\ParaferingConclusionService} when the
 * conclusion event arrives. Nothing in this app resolves a current step,
 * advances a route snapshot, or closes a chain any more.
 *
 * The migration that got here retired a real local engine —
 * BesluitvormingParafeerService, ParafeerActieService, the dossiq-local flow
 * projection and its gateway. Fixing instances one by one is how the next one
 * ships, so this test asserts the END STATE over every file under lib/: no
 * local route advancement can come back.
 *
 * THE RULE, mechanically. Two closed sets:
 *
 * 1. NO RETIRED CLASS RETURNS. None of the retired runtime classes may exist
 *    as a file under lib/ again. A file whose name matches one of them fails,
 *    naming it.
 * 2. NO LOCAL ADVANCEMENT LOGIC. A file under lib/ that performs storage work
 *    (saveObject/updateObject) AND advances a parafering chain — writes a
 *    `currentStep`, or writes a terminal voorstel status (`geaccordeerd` /
 *    `teruggestuurd`) — must sit in ALLOWED_ADVANCERS with the reason it may.
 *    Everything else raises the chain in the decision app and records its
 *    conclusion, it does not run one.
 *
 * WHAT THIS CANNOT SEE. The check is per file and lexical. A writer that hides
 * the status behind indirection passes; the per-surface unit tests carry the
 * finer assertions. This test exists so a NEW local runtime cannot ship
 * quietly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Structural assertion over source files, not behaviour.
 */
class LocalParaferingRuntimeTest extends TestCase {

	/**
	 * Storage work: calls that reach OpenRegister's ObjectService writers.
	 *
	 * @var string
	 */
	private const STORAGE_CALL_PATTERN = '/->\s*(saveObject|updateObject)\s*\(/';

	/**
	 * Local chain advancement: writing the step cursor, or a terminal voorstel
	 * status. Anchored to the quoted keys/values so a docblock mention alone
	 * does not flag.
	 *
	 * @var string
	 */
	private const ADVANCEMENT_PATTERN = "/'currentStep'\\s*=>|\"currentStep\"\\s*=>|'geaccordeerd'|'teruggestuurd'/";

	/**
	 * The retired runtime classes. None may exist as a file under lib/ again.
	 *
	 * @var array<int, string>
	 */
	private const RETIRED_CLASSES = [
		'BesluitvormingParafeerService',
		'ParafeerActieService',
		'ParafeerStepGuard',
		'ParaferingActionMapper',
		'ParaferingFlowGateway',
		'EndorsementRouteFlowMigrator',
		'ParaafFlowLinkage',
		'ParaafResumeListener',
		'DossiqAskParaafNode',
		'DossiqSetVoorstelStatusNode',
		'ParafeerActieController',
		'MigrateApprovalRoutesToFlowsCommand',
	];

	/**
	 * The CLOSED allowlist of files that may write a chain-advancing field,
	 * each with the reason it may. An entry whose file stops matching fails the
	 * suite, so the list cannot rot silently.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_ADVANCERS = [
		'lib/Service/Parafeer/ParaferingRaiseService.php' => 'THE raise: writes status in_parafering and currentStep 1 as the RECORD of what was raised in the decision app, then advances nothing — the chain runs there.',
		'lib/Service/Parafeer/ParaferingConclusionService.php' => 'THE sanctioned door: projects the decision app\'s concluded outcome onto the case (final status geaccordeerd/teruggestuurd, currentStep 0); records, never decides.',
	];

	/**
	 * 🔴 NO RETIRED RUNTIME CLASS COMES BACK.
	 *
	 * Re-add BesluitvormingParafeerService, the paraaf flow nodes, or any other
	 * retired engine file and this goes red naming it, before it meets a
	 * voorstel.
	 */
	public function testNoRetiredRuntimeClassExists(): void {
		$files = $this->libFiles();
		$offenders = [];

		foreach (self::RETIRED_CLASSES as $class) {
			foreach (array_keys($files) as $relative) {
				if (basename($relative, '.php') === $class) {
					$offenders[] = $relative;
				}
			}
		}

		self::assertSame(
			[],
			$offenders,
			"These files are retired parafering-runtime classes. The runtime lives in the decision app now: raise via ParaferingDelegationService, record the conclusion via ParaferingConclusionService. Do not bring the local engine back:\n - "
			. implode("\n - ", $offenders)
		);
	}//end testNoRetiredRuntimeClassExists()

	/**
	 * 🔴 NO FILE ADVANCES A PARAFERING CHAIN OUTSIDE THE CLOSED ALLOWLIST.
	 *
	 * Add a handler that writes `currentStep` or a terminal voorstel status
	 * itself and this goes red naming the file, before it ever runs a chain.
	 */
	public function testNoFileAdvancesAChainOutsideTheAllowlist(): void {
		$offenders = [];
		$flagged = [];

		foreach ($this->libFiles() as $relative => $source) {
			if (preg_match(self::STORAGE_CALL_PATTERN, $source) !== 1) {
				continue;
			}

			if (preg_match(self::ADVANCEMENT_PATTERN, $source) !== 1) {
				continue;
			}

			$flagged[] = $relative;

			if (array_key_exists($relative, self::ALLOWED_ADVANCERS) === true) {
				continue;
			}

			$offenders[] = $relative;
		}

		self::assertNotSame(
			[],
			$flagged,
			'The sweep found no chain-advancing writers at all: the detector is broken, not the tree clean.'
		);

		self::assertSame(
			[],
			$offenders,
			"These files advance a parafering chain locally (currentStep / a terminal voorstel status). dossiq owns cases; the decision app owns the parafering runtime. Raise the chain there and record its conclusion, do not run one. Only when the file verifiably records rather than advances may it join ALLOWED_ADVANCERS, with the reason:\n - "
			. implode("\n - ", $offenders)
		);
	}//end testNoFileAdvancesAChainOutsideTheAllowlist()

	/**
	 * The allowlist stays honest: every entry names a file that exists AND
	 * still matches both detectors, and carries a reason.
	 */
	public function testTheAllowlistCarriesNoStaleEntries(): void {
		$files = $this->libFiles();

		foreach (self::ALLOWED_ADVANCERS as $relative => $reason) {
			self::assertNotSame('', trim($reason), 'An allowlist entry must carry its reason: ' . $relative);
			self::assertArrayHasKey($relative, $files, 'Allowlisted file no longer exists — remove the entry: ' . $relative);
			self::assertSame(
				1,
				preg_match(self::STORAGE_CALL_PATTERN, $files[$relative]),
				'Allowlisted file no longer performs storage — remove the entry: ' . $relative
			);
			self::assertSame(
				1,
				preg_match(self::ADVANCEMENT_PATTERN, $files[$relative]),
				'Allowlisted file no longer writes a chain-advancing field — remove the entry: ' . $relative
			);
		}
	}//end testTheAllowlistCarriesNoStaleEntries()

	/**
	 * Every PHP source file under lib/, recursively.
	 *
	 * @return array<string, string> Relative path => source.
	 */
	private function libFiles(): array {
		$root = dirname(__DIR__, 3);
		$files = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $info) {
			if ($info->isFile() === false || $info->getExtension() !== 'php') {
				continue;
			}

			$source = file_get_contents($info->getPathname());
			self::assertIsString($source, 'Could not read ' . $info->getPathname());
			$files[substr($info->getPathname(), (strlen($root) + 1))] = $source;
		}

		self::assertNotSame([], $files, 'No PHP files under lib/: the sweep is scanning the wrong tree.');

		return $files;
	}//end libFiles()

}//end class
