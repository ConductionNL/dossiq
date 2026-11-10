<?php

/**
 * BezwaarCreationHook Unit Tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use OCA\Dossiq\Tests\Unit\Service\FakeStoredObject;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for BezwaarCreationHook.
 *
 * @covers \OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook
 */
class BezwaarCreationHookTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var BezwaarCreationHook
	 */
	private BezwaarCreationHook $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new BezwaarCreationHook(
			$this->settingsService,
			$this->userSession,
			$this->logger
		);

		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => '1',
					'case_schema' => '10',
					'decision_schema' => '20',
					'objection_schema' => '30',
					default => '',
				};
			}
		);
	}//end setUp()

	/**
	 * Empty ids are rejected.
	 *
	 * @return void
	 */
	public function testRejectsEmptyIds(): void {
		$this->expectException(RuntimeException::class);
		$this->service->onBezwaarCreated('', 'decision-1');
	}//end testRejectsEmptyIds()

	/**
	 * The hook links the primair besluit case into relatedCases and
	 * creates an objection with canonical case/contestedDecision refs.
	 *
	 * @return void
	 */
	public function testLinksPrimairBesluitAndCreatesObjection(): void {
		$objectService = new class {

			/**
			 * @var array<string, mixed>|null
			 */
			public ?array $savedCase = null;

			/**
			 * @var array<string, mixed>|null
			 */
			public ?array $savedObjection = null;

			/**
			 * Entity-shaped find, mirroring the real ObjectService contract.
			 *
			 * @return FakeStoredObject
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
				if ($id === 'decision-1') {
					return new FakeStoredObject(['case' => 'primair-1']);
				}

				return new FakeStoredObject(['relatedCases' => ['other-9']]);
			}

			/**
			 * Persist an object (OpenRegister object-first signature).
			 *
			 * @param array<string, mixed> $object Object payload.
			 * @param array<string, mixed> $extend Extend parameters.
			 * @param string|null $register Register id.
			 * @param string|null $schema Schema id.
			 * @param string|null $uuid Optional object uuid.
			 *
			 * @return FakeStoredObject
			 */
			public function saveObject(array $object,ObjectSaveOptions $options = new ObjectSaveOptions()): FakeStoredObject {
				$schema = $options->target->schema;
				if ((string)$schema === '10') {
					$this->savedCase = $object;
				}

				if ((string)$schema === '30') {
					$this->savedObjection = $object;
				}

				return new FakeStoredObject($object);
			}//end saveObject()
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$objection = $this->service->onBezwaarCreated(
			'bezwaar-1',
			'decision-1',
			['grounds' => 'In strijd met bestemmingsplan', 'case' => 'attacker-tries-to-override']
		);

		// relatedCases gained the primair besluit case and kept the existing one.
		$this->assertContains('primair-1', $objectService->savedCase['relatedCases']);
		$this->assertContains('other-9', $objectService->savedCase['relatedCases']);

		// Objection references cannot be overridden by caller payload.
		$this->assertSame('bezwaar-1', $objection['case']);
		$this->assertSame('decision-1', $objection['contestedDecision']);
		$this->assertSame('In strijd met bestemmingsplan', $objection['grounds']);
		$this->assertSame('bezwaar-1', $objectService->savedObjection['case']);
	}//end testLinksPrimairBesluitAndCreatesObjection()

	/**
	 * With the relation service wired, the link is a typed relation, not a bare array on the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/remedy-lodging/spec.md#requirement-a-remedy-is-lodged-against-a-decided-case-req-rml-001
	 */
	public function testTheLinkGoesThroughTheRelationService(): void {
		$objectService = new class {

			/**
			 * @var list<string>
			 */
			public array $savedSchemas = [];

			/**
			 * Find.
			 *
			 * @return FakeStoredObject
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
				return new FakeStoredObject(['case' => 'primair-1']);
			}

			/**
			 * Save.
			 *
			 * @return FakeStoredObject
			 */
			public function saveObject(array $object,ObjectSaveOptions $options = new ObjectSaveOptions()): FakeStoredObject {
				$schema = $options->target->schema;
				$this->savedSchemas[] = (string)$schema;
				return new FakeStoredObject($object);
			}//end saveObject()
		};
		$this->settingsService->method('getObjectService')->willReturn($objectService);
		$relations = $this->createMock(\OCA\Dossiq\Service\CaseRelationService::class);
		$relations->expects($this->once())->method('addRelation')->with('bezwaar-1', 'primair-1', 'vervolg')->willReturn(['ok' => true]);
		$hook = new BezwaarCreationHook(
			$this->settingsService,
			$this->createMock(IUserSession::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$relations,
		);

		$hook->onBezwaarCreated('bezwaar-1', 'decision-1', []);

		// Only the objection is saved; the case is not rewritten with an array.
		$this->assertSame(['30'], $objectService->savedSchemas);
	}//end testTheLinkGoesThroughTheRelationService()

	/**
	 * When the contested decision has no parent case, no relatedCases
	 * write happens but the objection is still created.
	 *
	 * @return void
	 */
	public function testCreatesObjectionWhenDecisionHasNoParentCase(): void {
		$objectService = new class {

			public int $caseWrites = 0;

			/**
			 * Entity-shaped find, mirroring the real ObjectService contract.
			 *
			 * @return FakeStoredObject
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
				return new FakeStoredObject(['case' => '']);
			}

			/**
			 * Persist an object (OpenRegister object-first signature).
			 *
			 * @param array<string, mixed> $object Object payload.
			 * @param array<string, mixed> $extend Extend parameters.
			 * @param string|null $register Register id.
			 * @param string|null $schema Schema id.
			 * @param string|null $uuid Optional object uuid.
			 *
			 * @return FakeStoredObject
			 */
			public function saveObject(array $object,ObjectSaveOptions $options = new ObjectSaveOptions()): FakeStoredObject {
				$schema = $options->target->schema;
				if ((string)$schema === '10') {
					$this->caseWrites++;
				}

				return new FakeStoredObject($object);
			}//end saveObject()
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$objection = $this->service->onBezwaarCreated('bezwaar-1', 'decision-1');

		$this->assertSame(0, $objectService->caseWrites);
		$this->assertSame('decision-1', $objection['contestedDecision']);
	}//end testCreatesObjectionWhenDecisionHasNoParentCase()

	/**
	 * A missing contested decision throws.
	 *
	 * @return void
	 */
	public function testThrowsWhenDecisionNotFound(): void {
		$objectService = new class {

			/**
			 * A miss THROWS DoesNotExistException, exactly like the live
			 * ObjectService — a null return is a shape live never produces.
			 *
			 * @throws DoesNotExistException Always.
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
				throw new DoesNotExistException('Object ' . $id . ' does not exist');
			}

			/**
			 * Persist an object (OpenRegister object-first signature).
			 *
			 * @param array<string, mixed> $object Object payload.
			 * @param array<string, mixed> $extend Extend parameters.
			 * @param string|null $register Register id.
			 * @param string|null $schema Schema id.
			 * @param string|null $uuid Optional object uuid.
			 *
			 * @return FakeStoredObject
			 */
			public function saveObject(array $object,ObjectSaveOptions $options = new ObjectSaveOptions()): FakeStoredObject {
				return new FakeStoredObject($object);
			}//end saveObject()
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		$this->expectException(RuntimeException::class);
		$this->service->onBezwaarCreated('bezwaar-1', 'decision-missing');
	}//end testThrowsWhenDecisionNotFound()
	/**
	 * The objection carries no status the caller sends: the objection schema
	 * declares neither a status nor a lifecycle. An id the caller sends opens a
	 * new objection and leaves the one it names as it was.
	 *
	 * @return void
	 */
	public function testTheObjectionKeepsNoCallerStatusOrIdentity(): void {
		$register = new LifecycleRegister();
		$register->seed(schema: 'decision', uuid: 'decision-1', row: ['case' => 'primair-1']);
		$register->seed(schema: 'case', uuid: 'bezwaar-1', row: ['title' => 'Bezwaar']);
		$register->seed(schema: 'objection', uuid: 'victim-1', row: ['case' => 'other-case', 'grounds' => 'Ander bezwaar']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'decision_schema' => 'decision',
				'objection_schema' => 'objection',
				default => '',
			}
		);
		$hook = new BezwaarCreationHook($settings, $this->userSession, $this->logger);

		$created = $hook->onBezwaarCreated(
			'bezwaar-1',
			'decision-1',
			['id' => 'victim-1', 'uuid' => 'victim-1', '@self' => ['id' => 'victim-1'], 'grounds' => 'Te laat', 'status' => 'Decision on objection']
		);

		$this->assertNotSame('victim-1', $created['id']);
		$this->assertSame(['case' => 'other-case', 'grounds' => 'Ander bezwaar', 'id' => 'victim-1'], $register->row('objection', 'victim-1'));
		$stored = $register->row('objection', (string)$created['id']);
		$this->assertArrayNotHasKey('status', $stored);
		$this->assertArrayNotHasKey('uuid', $stored);
		$this->assertSame('Te laat', $stored['grounds']);
		$this->assertSame('bezwaar-1', $stored['case']);
	}//end testTheObjectionKeepsNoCallerStatusOrIdentity()
}//end class
