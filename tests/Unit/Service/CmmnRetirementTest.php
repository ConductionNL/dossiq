<?php

/**
 * dossiq holds no case engine, and nothing may bring one back.
 *
 * The local CMMN runtime (`lib/Service/Cmmn/`, `CmmnCaseController`, the five
 * `/api/case/{caseId}/cmmn-plan*` routes) is gone. An adaptive case plan is
 * rows in OpenRegister's case layer, created by `CasePlanProjectionService`
 * and served on OpenRegister's `/api/cases`. This test fails on any file
 * under `lib/`, `src/` or `appinfo/` that names the retired runtime or the
 * `casePlanState` blob.
 *
 * The allowlist is empty and stays empty. It held the drain and the schema
 * declaration the drain read, for as long as a blob could still exist. Both
 * left in one commit, after the drain report showed zero blobs (design.md
 * section 4), and `testTheBlobAndItsDrainAreGone` keeps them out.
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
		.'/api/cases surface (src/services/casePlanApi.js, lib/Service/CasePlanProjectionService.php).';

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
	 * Files that may still name the retired runtime. None.
	 *
	 * @var array<string, string> Path => why it is still here.
	 */
	private const ALLOWED = [];

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
	 * Nothing names the retired runtime, and the allowlist is empty (REQ-RCMN-003).
	 *
	 * @return void
	 */
	public function testNothingNamesTheRetiredRuntime(): void {
		$this->assertSame([], self::ALLOWED, 'The allowlist is closed. '.self::DOOR);
		$this->assertSame([], $this->references(), self::DOOR);
	}//end testNothingNamesTheRetiredRuntime()

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
	 * The plan slot belongs to the panel over OpenRegister (REQ-RCMN-005).
	 *
	 * @return void
	 */
	public function testThePlanSlotIsServedByThePanelOverOpenRegister(): void {
		$manifest = (string) file_get_contents(self::ROOT.'/src/manifest.json');
		$this->assertStringContainsString('"widget-cmmn-case-plan": "CasePlanPanel"', $manifest);

		$panel = (string) file_get_contents(self::ROOT.'/src/components/case/CasePlanPanel.vue');
		$this->assertStringContainsString('services/casePlanApi.js', $panel);

		$client = (string) file_get_contents(self::ROOT.'/src/services/casePlanApi.js');
		$this->assertStringContainsString('/apps/openregister/api/cases', $client);
		$this->assertStringNotContainsString('/apps/dossiq/api', $client);
	}//end testThePlanSlotIsServedByThePanelOverOpenRegister()

	/**
	 * The blob and its drain are gone, in the shipped schema and the seed data (REQ-RCMN-004).
	 *
	 * @return void
	 */
	public function testTheBlobAndItsDrainAreGone(): void {
		$this->assertFalse($this->schemaDeclaresTheBlob());
		$this->assertDirectoryDoesNotExist(self::ROOT.'/lib/Service/CasePlanDrain');
		$this->assertFileDoesNotExist(self::ROOT.'/lib/Command/MigrateCasePlansCommand.php');
		$this->assertFileDoesNotExist(self::ROOT.'/lib/Repair/MigrateCmmnCasePlans.php');
		$this->assertStringNotContainsString('migrate-case-plans', (string) file_get_contents(self::ROOT.'/appinfo/info.xml'));

		$register = json_decode((string) file_get_contents(self::ROOT.'/lib/Settings/dossiq_register.json'), true);
		$handlingModel = ($register['components']['schemas']['caseType']['properties']['handlingModel'] ?? []);
		$this->assertSame(['bpmn', 'cmmn'], ($handlingModel['enum'] ?? null));
		$this->assertStringContainsString("OpenRegister's case layer", (string) ($handlingModel['description'] ?? ''));
	}//end testTheBlobAndItsDrainAreGone()

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
