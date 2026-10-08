<?php

/**
 * A KCC complaint lands on a case type dossiq actually ships.
 *
 * `QuickActionService` filed complaints under the code
 * `klacht_ex_artikel_9_1_awb`. No case type in `dossiq_register.json` carries
 * that identifier, so on a fresh install every "Klacht registreren" answered
 * "No case type answers to ..." and wrote nothing. The register ships the Awb
 * chapter 9 complaint type as `klacht-behandeling`.
 *
 * The fake object store answers from the case types and statuses in the
 * shipped register file itself, so a rename on either side turns this red.
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
 * @spec openspec/changes/dso-single-intake-path/specs/kcc-werkplek/spec.md
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
 * The complaint quick action resolves against the shipped register.
 */
class QuickActionComplaintFindsAShippedCaseTypeTest extends TestCase {

	/**
	 * What the service handed to saveObject().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Shipped objects, keyed by slug, each given a stable uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Load the shipped case types and statuses, `@ref:` turned into uuids.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$ids = [];
		foreach ($register['components']['objects'] as $object) {
			$slug = (string)($object['@self']['slug'] ?? '');
			if ($slug !== '') {
				$hash = md5($slug);
				$ids[$slug] = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3)
					. '-8' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
			}
		}

		foreach ($register['components']['objects'] as $object) {
			$slug = (string)($object['@self']['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			foreach ($object as $key => $value) {
				if (is_string($value) === true && str_starts_with($value, '@ref:') === true) {
					$object[$key] = ($ids[substr($value, 5)] ?? '');
				}
			}

			$object['id'] = $ids[$slug];
			$this->rows[$slug] = $object;
		}
	}//end setUp()

	/**
	 * Build the real service over a store that answers from the shipped rows.
	 *
	 * @return QuickActionService
	 */
	private function service(): QuickActionService {
		$entity = function (array $row): ObjectEntityInterface {
			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($row);
			return $entity;
		};

		$rows = $this->rows;
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			static function (string|int $id) use ($rows, $entity): ?ObjectEntityInterface {
				foreach ($rows as $row) {
					if ($row['id'] === (string)$id) {
						return $entity($row);
					}
				}

				return null;
			}
		);
		$objectService->method('searchObjects')->willReturnCallback(
			static function (array $query) use ($rows, $entity): array {
				$found = [];
				foreach ($rows as $row) {
					if (($row['@self']['schema'] ?? '') === 'caseType'
						&& isset($query['identifier']) === true
						&& ($row['identifier'] ?? null) === $query['identifier']
					) {
						$found[] = $entity($row);
					}
				}

				return $found;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($entity): ObjectEntityInterface {
				$this->saved[] = $object;
				return $entity(['id' => 'complaint-1'] + $object);
			}
		);

		$config = ['register' => '21', 'case_schema' => '113', 'case_type_schema' => '106'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('todayAsCalendarDate')->willReturn('2026-10-08');
		$dates->method('today')->willReturn(new \DateTimeImmutable('2026-10-08'));
		$dates->method('formatCalendarDate')->willReturnCallback(static fn (\DateTimeInterface $moment): string => $moment->format('Y-m-d'));

		return new QuickActionService(
			settingsService: $settings,
			contactMomentService: $this->createMock(ContactMomentService::class),
			logger: $this->createMock(LoggerInterface::class),
			dates: $dates,
			caseTypes: new CaseTypeReferenceResolver(store: new CaseTypeStore(settingsService: $settings)),
			timerService: null,
		);
	}//end service()

	/**
	 * The complaint is filed on the shipped Awb chapter 9 type, at its initial status.
	 *
	 * @return void
	 */
	public function testAComplaintIsFiledOnTheShippedAwbChapterNineCaseType(): void {
		$this->service()->executeKlachtRegistreren(
			caseId: '7f809102-3c4d-4e5f-8a1b-2c3d4e5f6071',
			summary: 'Niet teruggebeld',
			burgerId: '999993653'
		);

		$this->assertCount(1, $this->saved, 'one complaint case is written');
		$complaintType = $this->rows['klacht-behandeling'];
		$this->assertSame($complaintType['id'], $this->saved[0]['caseType']);
		$this->assertSame($this->rows['klacht-ontvangen']['id'], $this->saved[0]['status']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: $this->saved[0]));
	}//end testAComplaintIsFiledOnTheShippedAwbChapterNineCaseType()

	/**
	 * The seeded quick action names the same case type the service files on.
	 *
	 * @return void
	 */
	public function testTheSeededComplaintActionNamesAShippedCaseType(): void {
		$seed = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/kcc_werkplek_seed_data.json'),
			true
		);

		$identifiers = [];
		foreach ($this->rows as $row) {
			if (($row['@self']['schema'] ?? '') === 'caseType') {
				$identifiers[] = $row['identifier'];
			}
		}

		$checked = 0;
		array_walk_recursive(
			$seed,
			function ($value, $key) use ($identifiers, &$checked): void {
				if ($key === 'targetCaseType' && $value !== '') {
					$checked++;
					$this->assertContains($value, $identifiers, 'a seeded quick action names a case type the register ships');
				}
			}
		);
		$this->assertGreaterThan(0, $checked);
	}//end testTheSeededComplaintActionNamesAShippedCaseType()
}//end class
