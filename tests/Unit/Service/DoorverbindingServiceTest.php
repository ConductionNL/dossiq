<?php

/**
 * DoorverbindingService Unit Tests
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DoorverbindingService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;

/**
 * A warm transfer is created open, answered once, only by the employee it was
 * sent to, and every failure surfaces as a fixed message.
 *
 * @covers \OCA\Dossiq\Service\DoorverbindingService
 * @uses   \OCA\Dossiq\Service\Support\CastArray
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class DoorverbindingServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A fake object service holding at most one transfer.
	 *
	 * @param array<string,mixed>|null $stored The stored transfer, null when absent.
	 * @param \Throwable|null          $fail   Thrown by every write.
	 *
	 * @return object The fake.
	 */
	private function objectService(?array $stored = null, ?\Throwable $fail = null): object {
		return new class($stored, $fail) {
			/**
			 * Calls made.
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed>|null $stored The stored transfer.
			 * @param \Throwable|null          $fail   The write failure.
			 */
			public function __construct(private ?array $stored, private ?\Throwable $fail) {
			}

			/**
			 * Find an object.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string,mixed>
			 */
			public function find(string $id, string $register, string $schema): array {
				$this->calls[] = ['find', $id, $register, $schema];
				if ($this->stored === null) {
					throw new RuntimeException('no such object');
				}

				return $this->stored;
			}

			/**
			 * Save an object.
			 *
			 * @param array<string,mixed> $object   The object.
			 * @param string              $register The register.
			 * @param string              $schema   The schema.
			 *
			 * @return object
			 */
			public function saveObject(array $object, string $register, string $schema): object {
				$this->calls[] = ['saveObject', $object, $register, $schema];
				if ($this->fail !== null) {
					throw $this->fail;
				}

				return (object) ($object + ['id' => 'dv-new']);
			}

			/**
			 * Patch an object.
			 *
			 * @param string              $objectId The id.
			 * @param array<string,mixed> $data     The changes.
			 * @param string              $register The register.
			 * @param string              $schema   The schema.
			 *
			 * @return array<string,mixed>
			 */
			public function patchObject(ObjectPatch $patch): array {
				$objectId = $patch->objectId;
				$data = $patch->data;
				$register = $patch->target->register;
				$schema = $patch->target->schema;
				$this->calls[] = ['patchObject', $objectId, $data, $register, $schema];
				if ($this->fail !== null) {
					throw $this->fail;
				}

				return array_merge((array) $this->stored, $data);
			}
		};
	}//end objectService()

	/**
	 * The service under test.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $schema        The configured doorverbinding schema.
	 *
	 * @return DoorverbindingService The service.
	 */
	private function service(?object $objectService, string $schema = 'doorverbinding'): DoorverbindingService {
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['doorverbinding_schema', '', $schema],
			]
		);

		return new DoorverbindingService(settingsService: $this->settings, logger: $this->logger);
	}//end service()

	/**
	 * A new transfer is saved open with trimmed ids, defaults and a start time.
	 *
	 * @return void
	 */
	public function testInitiateWarmTransferSavesAnOpenTransfer(): void {
		$objects = $this->objectService();

		$result = $this->service(objectService: $objects)->initiateWarmTransfer(
			data: ['interactionId' => ' cm-1 ', 'fromEmployeeId' => ' alice ', 'toEmployeeId' => 'bob', 'transferReason' => 'Specialist']
		);

		$this->assertSame('dv-new', $result['id']);
		[$call, $record, $register, $schema] = $objects->calls[0];
		$this->assertSame(['saveObject', 'dossiq', 'doorverbinding'], [$call, $register, $schema]);
		$this->assertSame('cm-1', $record['interactionId']);
		$this->assertSame('alice', $record['fromEmployeeId']);
		$this->assertSame('bob', $record['toEmployeeId']);
		$this->assertNull($record['toQueue']);
		$this->assertSame('Specialist', $record['transferReason']);
		$this->assertSame('', $record['contextTransfer']);
		$this->assertSame('{}', $record['contextSnapshot']);
		$this->assertNull($record['accepted']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $record['warmTransferStarted']);
	}//end testInitiateWarmTransferSavesAnOpenTransfer()

	/**
	 * A transfer needs an interaction and a sending employee.
	 *
	 * @return void
	 */
	public function testInitiateWarmTransferRequiresTheIds(): void {
		$objects = $this->objectService();

		try {
			$this->service(objectService: $objects)->initiateWarmTransfer(data: ['interactionId' => 'cm-1', 'fromEmployeeId' => '  ']);
			$this->fail('Expected the missing employee to be refused');
		} catch (RuntimeException $e) {
			$this->assertSame('contactmomentId and vanMedewerkerId are required', $e->getMessage());
		}

		$this->assertSame([], $objects->calls);
	}//end testInitiateWarmTransferRequiresTheIds()

	/**
	 * A failing save is logged and surfaces as a fixed message.
	 *
	 * @return void
	 */
	public function testAFailingSaveSurfacesAFixedMessage(): void {
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: failed to initiate doorverbinding: quota',
			['app' => 'dossiq']
		);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Could not initiate doorverbinding');

		$this->service(objectService: $this->objectService(fail: new RuntimeException('quota')))
			->initiateWarmTransfer(data: ['interactionId' => 'cm-1', 'fromEmployeeId' => 'alice']);
	}//end testAFailingSaveSurfacesAFixedMessage()

	/**
	 * The addressed employee accepts an open transfer.
	 *
	 * @return void
	 */
	public function testAcceptTransferMarksItAccepted(): void {
		$objects = $this->objectService(stored: ['id' => 'dv-1', 'accepted' => null, 'toEmployeeId' => 'bob']);

		$result = $this->service(objectService: $objects)->acceptTransfer(doorverbindingId: 'dv-1', callerUid: 'bob');

		$this->assertTrue($result['accepted']);
		$this->assertSame(['find', 'dv-1', 'dossiq', 'doorverbinding'], $objects->calls[0]);
		$this->assertSame('patchObject', $objects->calls[1][0]);
		$this->assertSame(['accepted', 'acceptanceTime'], array_keys($objects->calls[1][2]));
	}//end testAcceptTransferMarksItAccepted()

	/**
	 * An unaddressed transfer, or one answered without a caller, can be accepted by anyone.
	 *
	 * @return void
	 */
	public function testAnUnaddressedTransferCanBeAcceptedByAnyone(): void {
		$result = $this->service(objectService: $this->objectService(stored: ['id' => 'dv-1', 'toQueue' => 'kcc']))
			->acceptTransfer(doorverbindingId: 'dv-1', callerUid: 'carol');

		$this->assertTrue($result['accepted']);
	}//end testAnUnaddressedTransferCanBeAcceptedByAnyone()

	/**
	 * Another employee may not answer a transfer addressed to someone else.
	 *
	 * @return void
	 */
	public function testAnotherEmployeeCannotAccept(): void {
		$objects = $this->objectService(stored: ['id' => 'dv-1', 'accepted' => null, 'toEmployeeId' => 'bob']);

		try {
			$this->service(objectService: $objects)->acceptTransfer(doorverbindingId: 'dv-1', callerUid: 'mallory');
			$this->fail('Expected another employee to be refused');
		} catch (RuntimeException $e) {
			$this->assertSame('Not authorized to answer this doorverbinding', $e->getMessage());
		}

		$this->assertCount(1, $objects->calls);
	}//end testAnotherEmployeeCannotAccept()

	/**
	 * An answered transfer cannot be accepted or rejected again.
	 *
	 * @return void
	 */
	public function testAnAnsweredTransferCannotBeAnsweredAgain(): void {
		$service = $this->service(objectService: $this->objectService(stored: ['id' => 'dv-1', 'accepted' => false]));

		foreach ([fn () => $service->acceptTransfer(doorverbindingId: 'dv-1'), fn () => $service->rejectTransfer(doorverbindingId: 'dv-1', reason: 'busy')] as $answer) {
			try {
				$answer();
				$this->fail('Expected an answered transfer to be refused');
			} catch (RuntimeException $e) {
				$this->assertSame('Doorverbinding already answered', $e->getMessage());
			}
		}
	}//end testAnAnsweredTransferCannotBeAnsweredAgain()

	/**
	 * A rejection stores the trimmed reason.
	 *
	 * @return void
	 */
	public function testRejectTransferStoresTheReason(): void {
		$objects = $this->objectService(stored: ['id' => 'dv-1', 'toEmployeeId' => 'bob']);

		$result = $this->service(objectService: $objects)->rejectTransfer(doorverbindingId: 'dv-1', reason: '  In a meeting ', callerUid: 'bob');

		$this->assertFalse($result['accepted']);
		$this->assertSame(['accepted' => false, 'rejectedReason' => 'In a meeting'], $objects->calls[1][2]);
	}//end testRejectTransferStoresTheReason()

	/**
	 * A rejection needs a reason and is refused before anything is read.
	 *
	 * @return void
	 */
	public function testRejectTransferRequiresAReason(): void {
		$objects = $this->objectService(stored: ['id' => 'dv-1']);

		try {
			$this->service(objectService: $objects)->rejectTransfer(doorverbindingId: 'dv-1', reason: '   ');
			$this->fail('Expected a blank reason to be refused');
		} catch (RuntimeException $e) {
			$this->assertSame('Rejection reason is required', $e->getMessage());
		}

		$this->assertSame([], $objects->calls);
	}//end testRejectTransferRequiresAReason()

	/**
	 * Another employee may not reject a transfer addressed to someone else.
	 *
	 * @return void
	 */
	public function testAnotherEmployeeCannotReject(): void {
		$this->expectExceptionMessage('Not authorized to answer this doorverbinding');

		$this->service(objectService: $this->objectService(stored: ['id' => 'dv-1', 'toEmployeeId' => 'bob']))
			->rejectTransfer(doorverbindingId: 'dv-1', reason: 'busy', callerUid: 'mallory');
	}//end testAnotherEmployeeCannotReject()

	/**
	 * Context notes are appended on a new line, stamped with time and specialist.
	 *
	 * @return void
	 */
	public function testAppendContextNotesAppendsAStampedLine(): void {
		$objects = $this->objectService(stored: ['id' => 'dv-1', 'contextTransfer' => 'Caller asked about permit']);

		$result = $this->service(objectService: $objects)->appendContextNotes(doorverbindingId: 'dv-1', notes: '  Needs a callback ', specialistUid: 'bob');

		$this->assertMatchesRegularExpression(
			'/^Caller asked about permit\n\[\d{4}-\d{2}-\d{2}T[^ ]+ bob\] Needs a callback$/',
			$result['contextTransfer']
		);
	}//end testAppendContextNotesAppendsAStampedLine()

	/**
	 * The first context note is the whole context.
	 *
	 * @return void
	 */
	public function testTheFirstContextNoteStandsAlone(): void {
		$result = $this->service(objectService: $this->objectService(stored: ['id' => 'dv-1']))
			->appendContextNotes(doorverbindingId: 'dv-1', notes: 'First', specialistUid: 'bob');

		$this->assertMatchesRegularExpression('/^\[[^ ]+ bob\] First$/', $result['contextTransfer']);
	}//end testTheFirstContextNoteStandsAlone()

	/**
	 * A transfer the store does not hold is reported as not found.
	 *
	 * @return void
	 */
	public function testAMissingTransferIsNotFound(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Doorverbinding not found');

		$this->service(objectService: $this->objectService())->acceptTransfer(doorverbindingId: 'dv-9');
	}//end testAMissingTransferIsNotFound()

	/**
	 * A failing update is logged and surfaces as a fixed message.
	 *
	 * @return void
	 */
	public function testAFailingUpdateSurfacesAFixedMessage(): void {
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: failed to update doorverbinding: conflict',
			['app' => 'dossiq']
		);
		$this->expectExceptionMessage('Could not update doorverbinding');

		$this->service(objectService: $this->objectService(stored: ['id' => 'dv-1'], fail: new RuntimeException('conflict')))
			->appendContextNotes(doorverbindingId: 'dv-1', notes: 'x', specialistUid: 'bob');
	}//end testAFailingUpdateSurfacesAFixedMessage()

	/**
	 * Without OpenRegister, or without a configured schema, nothing is attempted.
	 *
	 * @return void
	 */
	public function testAnUnavailableStoreIsReported(): void {
		try {
			$this->service(objectService: null)->acceptTransfer(doorverbindingId: 'dv-1');
			$this->fail('Expected a missing OpenRegister to be reported');
		} catch (RuntimeException $e) {
			$this->assertSame('OpenRegister is not available', $e->getMessage());
		}
	}//end testAnUnavailableStoreIsReported()

	/**
	 * Without a configured schema the transfer cannot be stored.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaIsReported(): void {
		$this->expectExceptionMessage('Doorverbinding schema is not configured');

		$this->service(objectService: $this->objectService(), schema: '')
			->initiateWarmTransfer(data: ['interactionId' => 'cm-1', 'fromEmployeeId' => 'alice']);
	}//end testAnUnconfiguredSchemaIsReported()
}//end class
