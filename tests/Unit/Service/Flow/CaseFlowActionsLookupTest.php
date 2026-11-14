<?php

/**
 * Unit tests for how CaseFlowActions reads a case and its type from OpenRegister.
 *
 * OpenRegister's ObjectService answers in several shapes (an array, an entity
 * that serialises itself, a plain object, or nothing), and an unconfigured
 * instance has no object service at all. These cases pin each one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use JsonSerializable;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use OCA\Dossiq\Service\Flow\PlannedSeriesLedger;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Flow\CaseFlowActions
 * @uses \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument
 * @uses \OCA\Dossiq\Service\Flow\PlannedSeriesLedger
 */
class CaseFlowActionsLookupTest extends TestCase {

	/**
	 * The service, over an object service that answers from the given map.
	 *
	 * @param array<string, mixed> $objects       What find() returns per id.
	 * @param bool                 $objectService Whether an object service is configured.
	 *
	 * @return CaseFlowActions
	 */
	private function service(array $objects, bool $objectService = true): CaseFlowActions {
		$flows = new class {
			public function find(string $uuid): object {
				return new class ($uuid) {
					public function __construct(private string $uuid) {
					}

					public function getUuid(): string {
						return $this->uuid;
					}

					public function getName(): string {
						return 'Flow ' . $this->uuid;
					}

					public function getDescription(): ?string {
						return null;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($flows): object {
				if ($name === 'OCA\OpenRegister\Service\Flow\FlowService') {
					return $flows;
				}

				throw new RuntimeException('not registered: ' . $name);
			}
		);

		$store = new class ($objects) {
			public function __construct(private array $objects) {
			}

			public function find(string $id, $register = null, $schema = null): mixed {
				return $this->objects[$id] ?? null;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService === true ? $store : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => '4', 'case_schema' => '10', 'case_type_schema' => '11'][$key] ?? '')
		);

		$logger = $this->createMock(LoggerInterface::class);
		$document = new PlannedFollowUpDocument();

		return new CaseFlowActions(
			$container,
			$settings,
			$document,
			new PlannedSeriesLedger($container, $settings, $document, $this->createMock(IAppConfig::class), $logger),
			$logger
		);
	}//end service()

	/**
	 * Without an object service the case cannot be found.
	 *
	 * @return void
	 */
	public function testWithoutAnObjectServiceTheCaseIsNotFound(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_found');

		$this->service([], objectService: false)->startableFlows('case-1');
	}//end testWithoutAnObjectServiceTheCaseIsNotFound()

	/**
	 * A case the object service answers with nothing is not found.
	 *
	 * @return void
	 */
	public function testACaseFoundAsNullIsNotFound(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_found');

		$this->service(['case-1' => null])->startableFlows('case-1');
	}//end testACaseFoundAsNullIsNotFound()

	/**
	 * An entity that serialises itself is read through jsonSerialize, a plain object by its properties.
	 *
	 * @return void
	 */
	public function testEntitiesAndPlainObjectsAreReadAsArrays(): void {
		$case = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['caseType' => 'type-1'];
			}
		};
		$type = (object)['startableFlows' => ['f-1']];

		$result = $this->service(['case-1' => $case, 'type-1' => $type])->startableFlows('case-1');

		self::assertSame(['results' => [['id' => 'f-1', 'title' => 'Flow f-1', 'description' => '']], 'total' => 1], $result);
	}//end testEntitiesAndPlainObjectsAreReadAsArrays()

	/**
	 * An entity whose serialisation is not an array falls back to its properties.
	 *
	 * @return void
	 */
	public function testAnEntitySerialisingToAScalarIsReadByItsProperties(): void {
		$case = new class implements JsonSerializable {
			public string $caseType = 'type-1';

			public function jsonSerialize(): string {
				return 'case-1';
			}
		};

		$result = $this->service(['case-1' => $case, 'type-1' => ['startableFlows' => ['f-9']]])->startableFlows('case-1');

		self::assertSame(1, $result['total']);
		self::assertSame('f-9', $result['results'][0]['id']);
	}//end testAnEntitySerialisingToAScalarIsReadByItsProperties()

	/**
	 * A case whose type does not resolve has nothing to start.
	 *
	 * @return void
	 */
	public function testACaseWhoseTypeIsMissingHasNoStartableFlows(): void {
		$result = $this->service(['case-1' => ['caseType' => 'type-gone']])->startableFlows('case-1');

		self::assertSame(['results' => [], 'total' => 0], $result);
	}//end testACaseWhoseTypeIsMissingHasNoStartableFlows()
}//end class
