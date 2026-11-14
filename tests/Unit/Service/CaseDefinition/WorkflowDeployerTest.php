<?php

/**
 * Workflow template deployer tests.
 *
 * Pins what WorkflowDeployer::deploy() answers for every configuration and
 * every mix of templates, which templates it writes and in which order, and
 * that a failing template rolls back the ones created before it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\WorkflowDeployer;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * Characterisation tests for WorkflowDeployer::deploy().
 *
 * @covers \OCA\Dossiq\Service\CaseDefinition\WorkflowDeployer
 * @uses \OCA\Dossiq\Service\CaseDefinition\RowTarget
 * @uses \OCA\Dossiq\Service\CaseDefinition\WorkflowEntry
 */
class WorkflowDeployerTest extends TestCase {

	/**
	 * The object service double: what it holds, and every call made to it.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The archives opened during the test, closed afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $paths = [];

	/**
	 * Build the object service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/**
			 * @var array<int, string>
			 */
			public array $calls = [];

			/**
			 * @var array<int, string>
			 */
			public array $held = ['tpl-held'];

			/**
			 * @param string $id The uuid.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, string $register='', string $schema='', bool $_rbac=true): ?array {
				$this->calls[] = 'find '.$id.' '.$register.'/'.$schema;
				return in_array($id, $this->held, true) === true ? ['id' => $id] : null;
			}

			/**
			 * @param array<string, mixed> $object The row.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register='', string $schema='', ?string $uuid=null): array {
				$this->calls[] = 'save '.($object['title'] ?? '').' '.$register.'/'.$schema.' '.($uuid ?? 'new');
				if (($object['title'] ?? '') === 'refused') {
					throw new \RuntimeException('schema says no');
				}

				return ['@self' => ['uuid' => ($uuid ?? 'uuid-'.($object['title'] ?? ''))]];
			}

			/**
			 * @param string $id The uuid.
			 *
			 * @return bool Always.
			 */
			public function deleteObject(string $id, string $register=''): bool {
				$this->calls[] = 'delete '.$id.' '.$register;
				return true;
			}
		};
	}//end setUp()

	/**
	 * Remove the archives.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			@unlink($path);
		}
	}//end tearDown()

	/**
	 * A deployer over the double, with the given configuration.
	 *
	 * @param array<string, string> $config  The app config.
	 * @param bool                  $service Whether OpenRegister is there.
	 *
	 * @return WorkflowDeployer The deployer.
	 */
	private function deployer(array $config=['register' => 'dossiq', 'workflow_template_schema' => 'wf'], bool $service=true): WorkflowDeployer {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($service === true ? $this->objects : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => ($config[$key] ?? $default)
		);

		return new WorkflowDeployer(new NullLogger(), $settings);
	}//end deployer()

	/**
	 * An archive holding the given entries.
	 *
	 * @param array<string, string> $entries The entries by name.
	 *
	 * @return ZipArchive The opened archive.
	 */
	private function zip(array $entries): ZipArchive {
		$path = tempnam(sys_get_temp_dir(), 'wfzip');
		$this->paths[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$zip->open($path);

		return $zip;
	}//end zip()

	/**
	 * Without OpenRegister or its configuration nothing is deployed, and the archive is not read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredInstanceDeploysNothing(): void {
		$refusal = ['status' => 'error', 'message' => 'Workflows were not deployed: this instance has no workflow template schema configured.'];
		$zip = $this->zip(['workflows/a.json' => '{"title":"a"}']);

		self::assertSame($refusal, $this->deployer(service: false)->deploy($zip, 'skip'));
		self::assertSame($refusal, $this->deployer(['register' => '', 'workflow_template_schema' => 'wf'])->deploy($zip, 'skip'));
		self::assertSame($refusal, $this->deployer(['register' => 'dossiq'])->deploy($zip, 'skip'));
		self::assertSame([], $this->objects->calls);
	}//end testAnUnconfiguredInstanceDeploysNothing()

	/**
	 * A package without workflow templates is skipped; other entries are not templates.
	 *
	 * @return void
	 */
	public function testAPackageWithoutWorkflowsIsSkipped(): void {
		$zip = $this->zip(['manifest.json' => '{}', 'workflows/readme.txt' => 'x', 'other/workflows/a.json' => '{}']);

		self::assertSame(['status' => 'skipped', 'message' => 'The package carries no workflows.'], $this->deployer()->deploy($zip, 'skip'));
		self::assertSame([], $this->objects->calls);
	}//end testAPackageWithoutWorkflowsIsSkipped()

	/**
	 * New templates are created, a held one is skipped or replaced by the strategy.
	 *
	 * @return void
	 */
	public function testTemplatesAreCreatedSkippedOrReplaced(): void {
		$zip = $this->zip(
			[
				'workflows/a.json' => '{"title":"a"}',
				'workflows/held.json' => '{"title":"held","id":"tpl-held"}',
				'workflows/b.json' => '{"title":"b","@self":{"uuid":"tpl-b"}}',
			]
		);

		self::assertSame(
			['status' => 'success', 'message' => '2 workflow(s) deployed, 0 replaced', 'created' => ['uuid-a', 'tpl-b'], 'replaced' => []],
			$this->deployer()->deploy($zip, 'skip')
		);
		self::assertSame(
			['save a dossiq/wf new', 'find tpl-held dossiq/wf', 'find tpl-b dossiq/wf', 'save b dossiq/wf tpl-b'],
			$this->objects->calls
		);

		self::assertSame(
			['status' => 'success', 'message' => '2 workflow(s) deployed, 1 replaced', 'created' => ['uuid-a', 'tpl-b'], 'replaced' => ['tpl-held']],
			$this->deployer()->deploy($zip, 'replace')
		);

		self::assertSame(
			['status' => 'skipped', 'message' => '1 workflow(s) already present, left alone.'],
			$this->deployer()->deploy($this->zip(['workflows/held.json' => '{"id":"tpl-held"}']), 'skip')
		);
	}//end testTemplatesAreCreatedSkippedOrReplaced()

	/**
	 * A template that cannot be read, parsed or saved rolls back the ones created before it.
	 *
	 * @return void
	 */
	public function testAFailingTemplateRollsTheDeploymentBack(): void {
		$zip = $this->zip(
			[
				'workflows/a.json' => '{"title":"a"}',
				'workflows/held.json' => '{"id":"tpl-held"}',
				'workflows/r.json' => '{"title":"refused"}',
				'workflows/c.json' => '{"title":"c"}',
			]
		);

		self::assertSame(
			['status' => 'error', 'message' => "Workflow 'workflows/r.json' could not be deployed: schema says no"],
			$this->deployer()->deploy($zip, 'skip')
		);
		self::assertSame(
			['save a dossiq/wf new', 'find tpl-held dossiq/wf', 'save refused dossiq/wf new', 'delete uuid-a dossiq'],
			$this->objects->calls
		);

		self::assertSame(
			['status' => 'error', 'message' => "Workflow 'workflows/bad.json' is not valid JSON."],
			$this->deployer()->deploy($this->zip(['workflows/bad.json' => 'not json']), 'skip')
		);
	}//end testAFailingTemplateRollsTheDeploymentBack()
}//end class
