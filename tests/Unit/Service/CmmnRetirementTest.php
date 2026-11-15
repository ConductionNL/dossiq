<?php

/**
 * dossiq holds no case engine, and nothing may bring one back.
 *
 * The local CMMN runtime (`lib/Service/Cmmn/`, `CmmnCaseController`, the five
 * `/api/case/{caseId}/cmmn-plan*` routes) is gone. An adaptive case plan is
 * rows in OpenRegister's case layer, created by `CasePlanProjectionService`
 * and served on OpenRegister's `/api/cases`. This test fails on any file
 * under `lib/`, `src/` or `appinfo/` that names the retired runtime or reads
 * the `casePlanState` blob, outside a closed allowlist in which every entry
 * says why it is still there.
 *
 * The allowlist is the drain and the schema declaration it reads, and the two
 * leave together. OpenRegister drops a property the schema does not declare
 * on the next save, so removing the declaration while a blob remains destroys
 * that blob. `testTheSchemaKeepsTheBlobForAsLongAsTheDrainShips` ties the two
 * mechanically: the property may only go in the commit that removes the drain,
 * and the drain may only go once its report shows zero blobs (design.md
 * section 4).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-003-no-cmmn-runtime-remains
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class CmmnRetirementTest extends TestCase {
	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__.'/../../..';

	/**
	 * What to say to whoever trips the guard.
	 *
	 * @var string
	 */
	private const DOOR = 'dossiq holds no case engine. Read and move a case plan through OpenRegister\'s '
		.'/api/cases surface (the library plan widget, lib/Service/CasePlanProjectionService.php).';

	/**
	 * What counts as a reference to the retired runtime.
	 *
	 * @var array<string, string> Label => pattern.
	 */
	private const RETIRED = [
		'the Service\Cmmn namespace' => '/Service\\\\+Cmmn\b/',
		'CmmnCaseController' => '/\bCmmnCaseController\b/',
		'the casePlanState blob' => '/\bcasePlanState\b/',
		'a cmmn-plan route' => '/\bcmmn-plan\b|\bcmmnCase#/',
	];

	/**
	 * The drain: the only code that may still read the blob.
	 *
	 * @var array<string, string> Path => why it is still here.
	 */
	private const DRAIN = [
		'lib/Service/CasePlanDrain/CasePlanMigrationService.php' => 'the drain reads each blob, hands it to OpenRegister and clears it once the rows are verified',
		'lib/Service/CasePlanDrain/CasePlanBlob.php' => 'the drain decodes the blob; docblocks name the property',
		'lib/Command/MigrateCasePlansCommand.php' => 'the occ entry of the drain; its help text names the property',
		'lib/Repair/MigrateCmmnCasePlans.php' => 'the upgrade entry of the drain; its docblock names the property',
	];

	/**
	 * The schema files that still declare or describe the blob.
	 *
	 * @var array<string, string> Path => why it is still here.
	 */
	private const DECLARATION = [
		'lib/Settings/dossiq_register.json' => 'declares case.casePlanState so no save strips a blob the drain has not moved yet',
		'lib/Settings/dossiq_mock_register.json' => 'mirrors the declaration and carries three seeded example values',
		'lib/Settings/register.d/39-search-declarations.json' => 'its note lists the property among the JSON blobs that declare no match type',
		'lib/Settings/register.d/70-cmmn-case-model.json' => 'the caseModel description names where the runtime state used to live; reworded with the declaration',
		'appinfo/info.xml' => 'the comment on the drain repair step names the property',
	];

	/**
	 * Every file under one directory, relative to the root.
	 *
	 * @param string $directory The directory, relative to the root.
	 *
	 * @return array<int, string> The paths.
	 */
	private function filesUnder(string $directory): array {
		$files = [];
		$base = self::ROOT.'/'.$directory;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->isFile() === true) {
				$files[] = $directory.'/'.ltrim(substr($file->getPathname(), strlen($base)), '/');
			}
		}

		sort($files);
		return $files;
	}//end filesUnder()

	/**
	 * Every file that names the retired runtime, with what it names.
	 *
	 * @return array<string, string> Path => the labels found.
	 */
	private function references(): array {
		$found = [];
		$files = array_merge($this->filesUnder(directory: 'lib'), $this->filesUnder(directory: 'src'), $this->filesUnder(directory: 'appinfo'));
		foreach ($files as $path) {
			$source = (string) file_get_contents(self::ROOT.'/'.$path);
			$labels = [];
			foreach (self::RETIRED as $label => $pattern) {
				if (preg_match($pattern, $source) === 1) {
					$labels[] = $label;
				}
			}

			if ($labels !== []) {
				$found[$path] = implode(', ', $labels);
			}
		}

		return $found;
	}//end references()

	/**
	 * Whether the shipped case schema declares the blob.
	 *
	 * @return bool True when `case.casePlanState` is declared.
	 */
	private function schemaDeclaresTheBlob(): bool {
		$register = json_decode((string) file_get_contents(self::ROOT.'/lib/Settings/dossiq_register.json'), true);
		$schemas = ($register['components']['schemas'] ?? []);

		return isset($schemas['case']['properties']['casePlanState']);
	}//end schemaDeclaresTheBlob()

	/**
	 * Nothing outside the allowlist names the retired runtime (REQ-RCMN-003).
	 *
	 * @return void
	 */
	public function testNothingOutsideTheAllowlistNamesTheRetiredRuntime(): void {
		$allowed = array_merge(self::DRAIN, self::DECLARATION);
		$unlisted = array_diff_key($this->references(), $allowed);

		$this->assertSame([], $unlisted, self::DOOR);
	}//end testNothingOutsideTheAllowlistNamesTheRetiredRuntime()

	/**
	 * Every allowlist entry still earns its place, and says why.
	 *
	 * An entry whose file no longer names the runtime is stale: it would let
	 * a later reference in through a door nobody is watching.
	 *
	 * @return void
	 */
	public function testEveryAllowlistEntryIsStillNeededAndGivesItsReason(): void {
		$allowed = array_merge(self::DRAIN, self::DECLARATION);
		$stale = array_keys(array_diff_key($allowed, $this->references()));

		$this->assertSame([], $stale, 'These allowlist entries name nothing retired any more; remove them.');
		foreach ($allowed as $path => $reason) {
			$this->assertGreaterThan(20, strlen($reason), $path.' needs a reason.');
		}
	}//end testEveryAllowlistEntryIsStillNeededAndGivesItsReason()

	/**
	 * Only the drain reaches the allowlist with the engine's names, never a class.
	 *
	 * The allowlist exists for blob access. No entry may name the retired
	 * namespace, the controller or a route: those have no reason to survive.
	 *
	 * @return void
	 */
	public function testTheAllowlistCoversBlobAccessOnly(): void {
		$allowed = array_merge(self::DRAIN, self::DECLARATION);
		foreach (array_intersect_key($this->references(), $allowed) as $path => $labels) {
			$this->assertSame('the casePlanState blob', $labels, $path.' names more than the blob. '.self::DOOR);
		}
	}//end testTheAllowlistCoversBlobAccessOnly()

	/**
	 * The retired code is gone (REQ-RCMN-003, REQ-RCMN-004).
	 *
	 * @return void
	 */
	public function testTheRetiredCodeIsGone(): void {
		$this->assertDirectoryDoesNotExist(self::ROOT.'/lib/Service/Cmmn');
		$this->assertFileDoesNotExist(self::ROOT.'/lib/Controller/CmmnCaseController.php');
		$this->assertFileDoesNotExist(self::ROOT.'/lib/Command/RollbackCasePlansCommand.php');
		$this->assertFileDoesNotExist(self::ROOT.'/lib/Service/CasePlanRollbackService.php');
		$this->assertFileDoesNotExist(self::ROOT.'/src/services/cmmnApi.js');
		$this->assertFileDoesNotExist(self::ROOT.'/src/views/cases/components/CmmnCasePlanPanel.vue');
		$this->assertFileDoesNotExist(self::ROOT.'/src/views/cases/components/CmmnPlanItemNode.vue');

		$routes = (string) file_get_contents(self::ROOT.'/appinfo/routes.php');
		$this->assertStringNotContainsString('cmmn-plan', $routes);
		$this->assertStringNotContainsString('rollback-case-plans', (string) file_get_contents(self::ROOT.'/appinfo/info.xml'));
	}//end testTheRetiredCodeIsGone()

	/**
	 * The plan cell is the library plan widget over OpenRegister (REQ-RCMN-005).
	 *
	 * @return void
	 */
	public function testThePlanSlotIsServedByThePanelOverOpenRegister(): void {
		$manifest = json_decode((string) file_get_contents(self::ROOT.'/src/manifest.json'), true);
		$found    = null;
		foreach (($manifest['pages'] ?? []) as $page) {
			foreach (($page['config']['widgets'] ?? []) as $widget) {
				if (($widget['id'] ?? '') === 'cmmn-case-plan') {
					$found = $widget;
				}
			}
		}

		$this->assertIsArray($found, 'The case page declares the cmmn-case-plan widget.');
		$this->assertSame('plan', $found['type'] ?? null);
		$this->assertFileDoesNotExist(self::ROOT.'/src/components/case/CasePlanPanel.vue');
		$this->assertFileDoesNotExist(self::ROOT.'/src/services/casePlanApi.js');
	}//end testThePlanSlotIsServedByThePanelOverOpenRegister()

	/**
	 * The blob stays declared for exactly as long as the drain ships (REQ-RCMN-004).
	 *
	 * Removing the declaration while the drain still has work destroys the
	 * blobs it has not moved. Keeping the drain after the declaration is gone
	 * leaves code that reads a property OpenRegister no longer returns. So
	 * they leave in one commit, and both lists are then empty.
	 *
	 * @return void
	 */
	public function testTheSchemaKeepsTheBlobForAsLongAsTheDrainShips(): void {
		$drainShips = false;
		foreach (array_keys(self::DRAIN) as $path) {
			$drainShips = ($drainShips === true || is_file(self::ROOT.'/'.$path) === true);
		}

		$this->assertSame(
			$drainShips,
			$this->schemaDeclaresTheBlob(),
			'case.casePlanState and the drain leave together: OpenRegister strips an undeclared property on save.'
		);
		$this->assertSame($drainShips, self::DRAIN !== [] || self::DECLARATION !== []);
	}//end testTheSchemaKeepsTheBlobForAsLongAsTheDrainShips()

	/**
	 * A BPMN case never touches the case layer (REQ-RCMN-005).
	 *
	 * The status-transition path names no case-plan class, so a BPMN caseType
	 * cannot reach the case layer through it. The projection is the only
	 * entry, and it steps aside for anything that is not `handlingModel: cmmn`.
	 *
	 * @return void
	 */
	public function testTheStatusTransitionPathNeverReachesTheCaseLayer(): void {
		$paths = array_merge(['lib/Service/StatusTransitionService.php'], $this->filesUnder(directory: 'lib/Service/Transitions'));
		foreach ($paths as $path) {
			$source = (string) file_get_contents(self::ROOT.'/'.$path);
			$this->assertDoesNotMatchRegularExpression('/CasePlan|caseModel|handlingModel/', $source, $path);
		}

		$projection = (string) file_get_contents(self::ROOT.'/lib/Service/CasePlanProjectionService.php');
		$this->assertMatchesRegularExpression("/\['handlingModel'\] \?\? 'bpmn'\) !== 'cmmn'/", $projection);
	}//end testTheStatusTransitionPathNeverReachesTheCaseLayer()
}//end class
