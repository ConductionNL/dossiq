<?php

/**
 * The KCC case cover sheet's open cases.
 *
 * Pins what CaseVoorbladService reads for one burger reference (the filter it
 * searches with), which cases it keeps (closed ones dropped), how it
 * summarises and orders them, the cap and the suggested topic, and every way
 * it answers nothing (no reference, no OpenRegister, no register or schema,
 * a failed read with its log line).
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseVoorbladService;
use OCA\Dossiq\Service\ContactMomentService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\CaseVoorbladService
 */
class CaseVoorbladServiceTest extends TestCase {

	/**
	 * The searches the object service saw.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $searches = [];

	/**
	 * The error lines logged.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $errors = [];

	/**
	 * Open cases are summarised, the closed ones dropped, newest action first,
	 * capped, and the topic follows the newest one.
	 *
	 * @return void
	 */
	public function testOpenCasesAreSummarisedSortedCappedAndTopical(): void {
		$rows = [
			['id' => 'c1', 'title' => 'Dakkapel', 'status' => 'open', 'lastActionDate' => '2026-09-01', 'caseType' => 'vth'],
			['uuid' => 'c2', 'titel' => 'Bezwaar', 'status' => 'Gesloten', 'updated' => '2026-09-05'],
			['uuid' => 'c3', 'titel' => 'Kapvergunning', 'status' => 'in behandeling', 'updated' => '2026-09-09', 'caseType' => 'kap'],
			['id' => 'c4', 'status' => 'CLOSED'],
			['id' => 'c5', 'status' => 'handled'],
			['id' => 'c6', 'caseType' => 'woo', 'lastActionDate' => '2026-08-01'],
		];
		$sheet = $this->service(rows: $rows, settings: ['max_zaken_voorblad' => '2'])->getCaseVoorblad(burgerId: 'b-1');

		self::assertSame([['register-slug', 'case-slug', ['initiator' => 'b-1', '_limit' => 50]]], $this->searches);
		self::assertSame(
			[
				['id' => 'c3', 'title' => 'Kapvergunning', 'status' => 'in behandeling', 'laatsteActie' => '2026-09-09', 'caseType' => 'kap'],
				['id' => 'c1', 'title' => 'Dakkapel', 'status' => 'open', 'laatsteActie' => '2026-09-01', 'caseType' => 'vth'],
			],
			$sheet['openZaken']
		);
		self::assertSame('Waarschijnlijk statusvraag over Kapvergunning', $sheet['suggestedTopic']);
		self::assertSame('b-1', $sheet['burgerId']);
	}//end testOpenCasesAreSummarisedSortedCappedAndTopical()

	/**
	 * Every way the sheet reads no cases, and the one that logs.
	 *
	 * @return void
	 */
	public function testEveryWayToReadNothing(): void {
		self::assertSame([], $this->service(rows: [['id' => 'x']], settings: [])->getCaseVoorblad(burgerId: '')['openZaken']);
		self::assertSame([], $this->service(rows: [['id' => 'x']], settings: ['no-or' => '1'])->getCaseVoorblad(burgerId: 'b-1')['openZaken']);
		self::assertSame([], $this->service(rows: [['id' => 'x']], settings: ['register' => ''])->getCaseVoorblad(burgerId: 'b-1')['openZaken']);
		self::assertSame([], $this->service(rows: [['id' => 'x']], settings: ['case_schema' => ''])->getCaseVoorblad(burgerId: 'b-1')['openZaken']);
		self::assertSame([], $this->searches);
		self::assertSame([], $this->errors);

		$sheet = $this->service(rows: null, settings: [])->getCaseVoorblad(burgerId: 'b-1');
		self::assertSame([], $sheet['openZaken']);
		self::assertSame('', $sheet['suggestedTopic']);
		self::assertSame([['Dossiq: failed to fetch zaken for voorblad: store down', ['app' => 'dossiq']]], $this->errors);
	}//end testEveryWayToReadNothing()

	/**
	 * The service over a store holding these rows (null: the read fails).
	 *
	 * @param array<int, array<string, mixed>>|null $rows     The rows, or null to fail.
	 * @param array<string, string>                 $settings Overrides of the settings.
	 *
	 * @return CaseVoorbladService
	 */
	private function service(?array $rows, array $settings): CaseVoorbladService {
		$store = new class($this, $rows) {
			/**
			 * @param CaseVoorbladServiceTest                $test The test that keeps the searches.
			 * @param array<int, array<string, mixed>>|null $rows The rows.
			 */
			public function __construct(private readonly CaseVoorbladServiceTest $test, private readonly ?array $rows) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->test->searches[] = [$register, $schema, $filters];
				if ($this->rows === null) {
					throw new RuntimeException('store down');
				}

				return $this->rows;
			}
		};

		$values = array_merge(['register' => 'register-slug', 'case_schema' => 'case-slug', 'max_zaken_voorblad' => '10', 'max_contactmomenten_history' => '5'], $settings);
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getObjectService')->willReturn(isset($values['no-or']) === true ? null : $store);
		$settingsService->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($values[$key] ?? $default));
		$settingsService->method('getKccConfigValue')->willReturnCallback(static fn (string $key): string => ($values[$key] ?? ''));

		$contacts = $this->createMock(ContactMomentService::class);
		$contacts->method('listForBurger')->willReturn([]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		return new CaseVoorbladService(settingsService: $settingsService, contactMomentService: $contacts, logger: $logger);
	}//end service()
}//end class
