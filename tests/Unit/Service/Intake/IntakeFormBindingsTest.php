<?php

/**
 * The published case types that bind an intake form are listed, and a binding is taken off.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use JsonSerializable;
use OCA\Dossiq\Service\Intake\IntakeFormBinding;
use OCA\Dossiq\Service\Intake\IntakeFormBindings;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Intake\IntakeFormBindings
 * @uses   \OCA\Dossiq\Service\Intake\IntakeFormBinding
 * @uses   \OCA\Dossiq\Service\CaseFieldWriter
 */
class IntakeFormBindingsTest extends TestCase {

	/**
	 * The logger, to see the overflow reported.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A fake ObjectService answering one search and recording patches.
	 *
	 * @param mixed $found What searchObjects() answers.
	 *
	 * @return object The fake.
	 */
	private function objectService(mixed $found): object {
		return new class ($found) {
			/** @var array<int, array<string, mixed>> */
			public array $searches = [];

			/** @var array<int, array<string, mixed>> */
			public array $patches = [];

			/**
			 * @param mixed $found The search answer.
			 */
			public function __construct(private mixed $found) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return mixed
			 */
			public function searchObjects(array $query): mixed {
				$this->searches[] = $query;
				return $this->found;
			}

			/**
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The changes.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				$this->patches[] = ['id' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema];
				return ['id' => $objectId] + $data;
			}
		};
	}//end objectService()

	/**
	 * The service under test, on a configured or unconfigured register.
	 *
	 * @param object|null $objectService The ObjectService, or null when OpenRegister is absent.
	 * @param string      $register      The configured register.
	 *
	 * @return IntakeFormBindings
	 */
	private function bindings(?object $objectService, string $register = 'dossiq'): IntakeFormBindings {
		/** @var SettingsService&MockObject $settings */
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => $register, 'case_type_schema' => 'caseType'][$key] ?? ''
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new IntakeFormBindings($settings, new IntakeFormBinding(), $this->logger);
	}//end bindings()

	/**
	 * Without OpenRegister or a register nothing is listed, and that is refused rather than answered empty.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterIsRefused(): void {
		foreach ([$this->bindings(objectService: null), $this->bindings(objectService: $this->objectService(found: []), register: '')] as $bindings) {
			try {
				$bindings->boundCaseTypes();
				self::fail('An unconfigured register must be refused');
			} catch (RuntimeException $e) {
				self::assertSame('The case type register is not configured.', $e->getMessage());
			}
		}
	}//end testAnUnconfiguredRegisterIsRefused()

	/**
	 * A search that answers no list is refused.
	 *
	 * @return void
	 */
	public function testASearchWithoutAListIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The case types could not be listed.');

		$this->bindings(objectService: $this->objectService(found: null))->boundCaseTypes();
	}//end testASearchWithoutAListIsRefused()

	/**
	 * Only published case types that name a form are bound, however the rows come back.
	 *
	 * @return void
	 */
	public function testOnlyPublishedCaseTypesThatNameAFormAreBound(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'ct-entity', IntakeFormBinding::FORM_REF => 'form-2'];
			}
		};
		$objects = $this->objectService(
			found: [
				'results' => [
					['id' => 'ct-1', IntakeFormBinding::FORM_REF => 'form-1'],
					$entity,
					['id' => 'ct-draft', 'isDraft' => true, IntakeFormBinding::FORM_REF => 'form-3'],
					['id' => 'ct-unbound'],
					'not a row',
				],
				'total' => 5,
			]
		);
		$bindings = $this->bindings(objectService: $objects);
		$this->logger->expects(self::never())->method('warning');

		$bound = $bindings->boundCaseTypes();

		self::assertSame(['ct-1', 'ct-entity'], array_column($bound, 'id'));
		self::assertSame([['@self' => ['register' => 'dossiq', 'schema' => 'caseType'], '_limit' => 1000]], $objects->searches);
	}//end testOnlyPublishedCaseTypesThatNameAFormAreBound()

	/**
	 * A full page warns that forms bound to case types beyond it are not judged.
	 *
	 * @return void
	 */
	public function testAFullPageIsReported(): void {
		$rows = [];
		for ($i = 0; $i < 1000; $i++) {
			$rows[] = ['id' => 'ct-' . $i];
		}

		$rows[0][IntakeFormBinding::FORM_REF] = 'form-1';
		$bindings = $this->bindings(objectService: $this->objectService(found: $rows));
		$this->logger->expects(self::once())->method('warning')->with(
			'Dossiq intake: more case types than one read lists; forms bound to the rest are not judged',
			['listed' => 1000]
		);

		self::assertSame(['ct-0'], array_column($bindings->boundCaseTypes(), 'id'));
	}//end testAFullPageIsReported()

	/**
	 * Taking a form off a case type clears only its form reference.
	 *
	 * @return void
	 */
	public function testUnbindClearsOnlyTheFormReference(): void {
		$objects = $this->objectService(found: []);

		$this->bindings(objectService: $objects)->unbind(caseTypeId: 'ct-1');

		self::assertSame(
			[['id' => 'ct-1', 'data' => [IntakeFormBinding::FORM_REF => ''], 'register' => 'dossiq', 'schema' => 'caseType']],
			$objects->patches
		);
	}//end testUnbindClearsOnlyTheFormReference()

	/**
	 * Without a case type id or a register, nothing is written and the unbind is refused.
	 *
	 * @return void
	 */
	public function testUnbindWithoutTarget(): void {
		$objects = $this->objectService(found: []);
		$cases   = [
			[$this->bindings(objectService: $objects), ''],
			[$this->bindings(objectService: null), 'ct-1'],
		];
		foreach ($cases as [$bindings, $caseTypeId]) {
			try {
				$bindings->unbind(caseTypeId: $caseTypeId);
				self::fail('An unbind without a target must be refused');
			} catch (RuntimeException $e) {
				self::assertSame('The intake form of case type ' . $caseTypeId . ' could not be taken off.', $e->getMessage());
			}
		}

		self::assertSame([], $objects->patches);
	}//end testUnbindWithoutTarget()
}//end class
