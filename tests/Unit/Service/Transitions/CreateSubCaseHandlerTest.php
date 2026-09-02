<?php

/**
 * CreateSubCaseHandler Unit Tests
 *
 * Verifies the createSubCase action handler creates a deelzaak linked to its
 * parent via `hoofdzaak`, surfaces storage/config errors, and swallows
 * exceptions per the spec contract.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-20
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\FlowRunAsScope;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CreateSubCaseHandler;
use PHPUnit\Framework\TestCase;
use OCP\IUserManager;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CreateSubCaseHandler
 *
 * @uses \OCA\Dossiq\Service\FlowRunAsScope
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class CreateSubCaseHandlerTest extends TestCase {
	/**
	 * @return void
	 */
	public function testFailsWhenObjectServiceUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$handler = new CreateSubCaseHandler($settings, $this->bareScope(), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createSubCase', 'caseType' => 'ct-2'],
			case: ['id' => 'parent-1'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('storage_unavailable', $result->error);
	}//end testFailsWhenObjectServiceUnavailable()

	/**
	 * @return void
	 */
	public function testFailsWhenCaseSchemaNotConfigured(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				return ['id' => 'unreachable'];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn('');

		$handler = new CreateSubCaseHandler($settings, $this->bareScope(), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createSubCase'],
			case: ['id' => 'parent'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('case_schema_not_configured', $result->error);
	}//end testFailsWhenCaseSchemaNotConfigured()

	/**
	 * @return void
	 */
	public function testLinksSubCaseToParentViaHoofdzaak(): void {
		$recorded = null;
		$objectService = new class($recorded) {
			/** @var mixed */
			public $recorded;

			public function __construct(&$recorded) {
				$this->recorded = &$recorded;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->recorded = $object;
				return ['id' => 'sub-uuid'];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'case_schema' => 'case-schema',
				][$key] ?? '';
			}
		);

		$handler = new CreateSubCaseHandler($settings, $this->bareScope(), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createSubCase', 'caseType' => 'ct-9', 'title' => 'Onderzoek BAG'],
			case: ['id' => 'parent-42'],
			transitionContext: ['transitionLabel' => 'In Behandeling'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('sub-uuid', $result->data['subCaseId']);
		self::assertSame('parent-42', $recorded['hoofdzaak']);
		self::assertSame('ct-9', $recorded['caseType']);
		self::assertSame('Onderzoek BAG', $recorded['title']);
	}//end testLinksSubCaseToParentViaHoofdzaak()

	/**
	 * @return void
	 */
	public function testCatchesExceptionFromObjectService(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				throw new RuntimeException('boom');
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'case_schema' => 'case-schema',
				][$key] ?? '';
			}
		);

		$handler = new CreateSubCaseHandler($settings, $this->bareScope(), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createSubCase'],
			case: ['id' => 'p'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('create_sub_case_failed', $result->error);
	}//end testCatchesExceptionFromObjectService()

	/**
	 * A runAs scope that runs bare for an empty context.
	 *
	 * These tests hand contexts naming no acting identity, so the scope never
	 * resolves one and the class under test behaves as before the wrap.
	 *
	 * @return FlowRunAsScope The scope.
	 */
	private function bareScope(): FlowRunAsScope {
		return new FlowRunAsScope(
			$this->createMock(SettingsService::class),
			$this->createMock(IUserManager::class),
		);
	}//end bareScope()
}//end class
