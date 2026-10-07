<?php

/**
 * The KCC quick actions write cases the real case schema accepts.
 *
 * Same defect as DsoCaseService: `executeNieuweZaak()` and
 * `executeKlachtRegistreren()` wrote the case type as the caller's code and
 * `status: 'intake'` as text, on a schema that declares both as uuid
 * references, and `startDate` as a moment on a `format: date` field. So
 * OpenRegister refused every case a KCC medewerker opened from a contact.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\QuickActionService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * QuickActionService writes the shape the case schema accepts.
 */
class QuickActionCaseWritesMatchTheRealSchemaTest extends TestCase {

	private const CASE_TYPE_ID = '5d6e7f80-1a2b-4c3d-8e9f-0a1b2c3d4e5f';
	private const INITIAL_STATUS_ID = '6e7f8091-2b3c-4d4e-9f0a-1b2c3d4e5f60';

	/**
	 * What the service handed to saveObject().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the real service with whatever collaborators its constructor takes.
	 *
	 * @param string $identifier The identifier the one case type carries.
	 *
	 * @return QuickActionService
	 */
	private function service(string $identifier): QuickActionService {
		$caseType = ['id' => self::CASE_TYPE_ID, 'identifier' => $identifier, 'isDraft' => false, 'initialStatus' => self::INITIAL_STATUS_ID];

		$entity = function (array $row): ObjectEntityInterface {
			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($row);
			return $entity;
		};

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			static fn (string|int $id): ?ObjectEntityInterface => ((string)$id === self::CASE_TYPE_ID ? $entity($caseType) : null)
		);
		$objectService->method('searchObjects')->willReturnCallback(
			static fn (array $query): array => (($query['identifier'] ?? null) === $identifier ? [$entity($caseType)] : [])
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($entity): ObjectEntityInterface {
				$this->saved[] = $object;
				return $entity(['id' => 'case-1'] + $object);
			}
		);

		$config = ['register' => '21', 'case_schema' => '113', 'case_type_schema' => '106'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('nowAsMoment')->willReturn('2026-10-08T09:30:00+02:00');
		$dates->method('todayAsCalendarDate')->willReturn('2026-10-08');
		$dates->method('today')->willReturn(new \DateTimeImmutable('2026-10-08'));
		$dates->method('formatCalendarDate')->willReturnCallback(static fn (\DateTimeInterface $moment): string => $moment->format('Y-m-d'));

		$available = [
			'settingsService' => $settings,
			'contactMomentService' => $this->createMock(ContactMomentService::class),
			'logger' => $this->createMock(LoggerInterface::class),
			'dates' => $dates,
			'timerService' => null,
		];
		if (class_exists(CaseTypeReferenceResolver::class) === true) {
			$available['caseTypes'] = new CaseTypeReferenceResolver(store: new CaseTypeStore(settingsService: $settings));
		}

		$args = [];
		foreach ((new \ReflectionClass(QuickActionService::class))->getConstructor()->getParameters() as $parameter) {
			$args[$parameter->getName()] = $available[$parameter->getName()];
		}

		return new QuickActionService(...$args);
	}//end service()

	/**
	 * A new case from a contact fits the case schema.
	 *
	 * @return void
	 */
	public function testANewCaseFromAContactFitsTheRealCaseSchema(): void {
		$this->service(identifier: 'MELDING-OPENBARE-RUIMTE')->executeNieuweZaak(
			caseType: 'MELDING-OPENBARE-RUIMTE',
			burgerId: '999993653',
			details: ['title' => 'Lantaarnpaal kapot']
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: $this->saved[0]));
		$this->assertSame(self::CASE_TYPE_ID, $this->saved[0]['caseType']);
		$this->assertSame(self::INITIAL_STATUS_ID, $this->saved[0]['status']);
	}//end testANewCaseFromAContactFitsTheRealCaseSchema()

	/**
	 * A complaint fits the case schema.
	 *
	 * @return void
	 */
	public function testAComplaintFitsTheRealCaseSchema(): void {
		$this->service(identifier: 'klacht-behandeling')->executeKlachtRegistreren(
			caseId: '7f809102-3c4d-4e5f-8a1b-2c3d4e5f6071',
			summary: 'Niet teruggebeld',
			burgerId: '999993653'
		);

		$this->assertNotSame([], $this->saved);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: $this->saved[0]));
		$this->assertSame(self::CASE_TYPE_ID, $this->saved[0]['caseType']);
	}//end testAComplaintFitsTheRealCaseSchema()

	/**
	 * A code that names no case type writes nothing and says so.
	 *
	 * @return void
	 */
	public function testAnUnknownCaseTypeWritesNothing(): void {
		$service = $this->service(identifier: 'SOMETHING-ELSE');

		try {
			$service->executeNieuweZaak(caseType: 'NOT-A-TYPE', burgerId: '999993653', details: []);
			$this->fail('an unknown case type must be refused');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('NOT-A-TYPE', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testAnUnknownCaseTypeWritesNothing()
}//end class
