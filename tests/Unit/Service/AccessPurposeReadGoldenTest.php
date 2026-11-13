<?php

/**
 * What recording and checking an access purpose read before they write.
 *
 * Golden over CaseAccessPurposeService::record() and check() before they
 * share their read: without OpenRegister, the audit mapper, the register or
 * a schema, both refuse as not recorded and read nothing; a case the reader
 * cannot open is refused with 404; an unreadable case type refuses as
 * indeterminate; a case whose type asks no purpose answers that nothing was
 * recorded, each in its own answer shape. Nothing is written in any of these.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessPurposeService;
use OCA\Dossiq\Service\People\CaseRoleHolder;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The shared read of record() and check(), and its refusals.
 *
 * @covers \OCA\Dossiq\Service\CaseAccessPurposeService
 *
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
 */
class AccessPurposeReadGoldenTest extends TestCase {
	/**
	 * The audit events written.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $written = [];

	/**
	 * The two gestures, called with a case id.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function gestures(): array {
		return ['record' => ['record'], 'check' => ['check']];
	}//end gestures()

	/**
	 * Without a piece of the configuration both refuse as not recorded, and no case is read.
	 *
	 * @param string $gesture The gesture.
	 *
	 * @dataProvider gestures
	 *
	 * @return void
	 */
	public function testAnUnconfiguredInstanceRefusesBeforeReading(string $gesture): void {
		foreach (['objectService', 'mapper', 'register', 'case_schema', 'case_type_schema'] as $missing) {
			$reads = 0;
			try {
				$this->call(gesture: $gesture, service: $this->service(missing: $missing, reads: $reads), caseId: 'case-1');
				self::fail('An unconfigured ' . $missing . ' must refuse.');
			} catch (RefusedException $e) {
				self::assertSame(expected: ['access-purpose-not-recorded', RefusedException::STATUS_INDETERMINATE, 0], actual: [$e->getRule(), $e->getStatus(), $reads]);
			}
		}

		self::assertSame(expected: [], actual: $this->written);
	}//end testAnUnconfiguredInstanceRefusesBeforeReading()

	/**
	 * A case the reader cannot open is refused with 404.
	 *
	 * @param string $gesture The gesture.
	 *
	 * @dataProvider gestures
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIsNotFound(string $gesture): void {
		$reads = 0;
		try {
			$this->call(gesture: $gesture, service: $this->service(missing: '', reads: $reads), caseId: 'case-hidden');
			self::fail('An unreadable case must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(expected: ['case-not-readable', 404, 1], actual: [$e->getRule(), $e->getStatus(), $reads]);
		}

		self::assertSame(expected: [], actual: $this->written);
	}//end testAnUnreadableCaseIsNotFound()

	/**
	 * An unreadable case type refuses as indeterminate.
	 *
	 * @param string $gesture The gesture.
	 *
	 * @dataProvider gestures
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeIsIndeterminate(string $gesture): void {
		$reads = 0;
		try {
			$this->call(gesture: $gesture, service: $this->service(missing: '', reads: $reads), caseId: 'case-orphan');
			self::fail('An unreadable case type must refuse.');
		} catch (RefusedException $e) {
			self::assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
		}

		self::assertSame(expected: [], actual: $this->written);
	}//end testAnUnreadableCaseTypeIsIndeterminate()

	/**
	 * A case whose type asks no purpose records nothing, each gesture in its own answer shape.
	 *
	 * @return void
	 */
	public function testACaseThatAsksNoPurposeRecordsNothing(): void {
		$reads = 0;
		$service = $this->service(missing: '', reads: $reads);

		self::assertSame(
			expected: [['recorded' => false, 'required' => false], ['required' => false, 'recorded' => false]],
			actual: [$this->call(gesture: 'record', service: $service, caseId: 'case-2'), $this->call(gesture: 'check', service: $service, caseId: 'case-2')]
		);
		self::assertSame(expected: [], actual: $this->written);
	}//end testACaseThatAsksNoPurposeRecordsNothing()

	/**
	 * Collect an audit event the mapper would write.
	 *
	 * @param ObjectAuditEvent $event The event.
	 *
	 * @return void
	 */
	public function collect(ObjectAuditEvent $event): void {
		$this->written[] = [$event->action, $event->context];
	}//end collect()

	/**
	 * Call one gesture as reader pieter, recording the handling purpose.
	 *
	 * @param string                    $gesture 'record' or 'check'.
	 * @param CaseAccessPurposeService  $service The service.
	 * @param string                    $caseId  The case.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function call(string $gesture, CaseAccessPurposeService $service, string $caseId): array {
		if ($gesture === 'record') {
			return $service->record(caseId: $caseId, readerId: 'pieter', purpose: 'handling', note: '');
		}

		return $service->check(caseId: $caseId, readerId: 'pieter');
	}//end call()

	/**
	 * The service over an in-memory register, with one piece of the configuration missing.
	 *
	 * @param string $missing The missing piece ('' for none).
	 * @param int    $reads   Counts the object reads.
	 *
	 * @return CaseAccessPurposeService The service.
	 */
	private function service(string $missing, int &$reads): CaseAccessPurposeService {
		$objects = [
			'case-1' => ['title' => 'Melding Jeugdwet', 'caseType' => 'ct-jw'],
			'ct-jw' => ['title' => 'Melding Jeugdwet', 'specialDataCategories' => ['health']],
			'case-2' => ['title' => 'Dakkapel', 'caseType' => 'ct-ov'],
			'ct-ov' => ['title' => 'Omgevingsvergunning'],
			'case-orphan' => ['title' => 'Wees', 'caseType' => 'ct-gone'],
		];
		$objectService = new class($objects, $reads) {
			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, mixed>> $objects The stored objects.
			 * @param int                                 $reads   Counts the reads.
			 */
			public function __construct(private array $objects, private int &$reads) {
			}//end __construct()

			/**
			 * Find a stored object, or throw.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object The entity.
			 */
			public function find(string $id, string $register, string $schema): object {
				$this->reads++;
				if (isset($this->objects[$id]) === false) {
					throw new RuntimeException('Object not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($this->objects[$id]);

				return $entity;
			}//end find()
		};
		$test = $this;
		$mapper = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param AccessPurposeReadGoldenTest $test Collects the events.
			 */
			public function __construct(private AccessPurposeReadGoldenTest $test) {
			}//end __construct()

			/**
			 * Record an audit event.
			 *
			 * @param ObjectAuditEvent $event The event.
			 *
			 * @return AuditTrail The trail.
			 */
			public function recordObjectEvent(ObjectAuditEvent $event): AuditTrail {
				$this->test->collect(event: $event);

				return new AuditTrail();
			}//end recordObjectEvent()
		};
		$config = ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];
		unset($config[$missing]);
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($missing === 'objectService' ? null : $objectService);
		$settings->method('getOpenRegisterClass')->willReturn($missing === 'mapper' ? null : $mapper);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));

		return new CaseAccessPurposeService(settingsService: $settings, logger: new NullLogger(), roles: $this->createMock(originalClassName: CaseRoleHolder::class));
	}//end service()
}//end class
