<?php

/**
 * Failure paths of TermDashboardFigures: an unreadable penalty list and a term whose case type has no title.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermCaseTypeResolver;
use OCA\Dossiq\Service\Termijn\TermDashboardFigures;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for TermDashboardFigures when a read fails or a case type is unnamed.
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermDashboardFigures
 * @uses   \OCA\Dossiq\Service\Termijn\TermCaseTypeResolver
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class TermDashboardFiguresFailuresTest extends TestCase {

	/**
	 * Figures over a store, with a translator that renders plurals readably.
	 *
	 * @param FakeTermijnStore $objects The store.
	 *
	 * @return TermDashboardFigures
	 */
	private function figures(FakeTermijnStore $objects): TermDashboardFigures {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA => 'accrual',
				default => '',
			},
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => sprintf(str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural)), ...$parameters)
		);

		return new TermDashboardFigures(settingsService: $settings, caseTypes: new TermCaseTypeResolver(settingsService: $settings), l10n: $l10n);
	}//end figures()

	/**
	 * A penalty list that cannot be read counts as no running penalty.
	 *
	 * @return void
	 */
	public function testAnUnreadablePenaltyListIsNoRunningPenalty(): void {
		$objects = new class extends FakeTermijnStore {
			/**
			 * The penalty search fails; others answer normally.
			 *
			 * @param string               $registerSlug The register.
			 * @param string               $schemaSlug   The schema.
			 * @param array<string, mixed> $filters      The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
				if ($schemaSlug === 'accrual') {
					throw new RuntimeException('search down');
				}

				return parent::searchObjectsBySlug($registerSlug, $schemaSlug, $filters);
			}
		};
		$objects->seed('accrual', ['id' => 'a-1', 'status' => 'lopend', 'cumulativeAmount' => 1500]);

		$out = $this->figures(objects: $objects)->forDashboard(rows: [], today: new DateTimeImmutable('2026-06-10'), perType: []);

		self::assertSame(0.0, $out['penaltyRunningEuros']);
		self::assertSame(0, $out['penaltyRunningCount']);
		self::assertSame('', $out['penaltyRunningNote']);
	}//end testAnUnreadablePenaltyListIsNoRunningPenalty()

	/**
	 * A term due this week whose case type has no title is counted but left out of the note.
	 *
	 * @return void
	 */
	public function testAnUnnamedCaseTypeIsLeftOutOfTheWeekNote(): void {
		$objects = new FakeTermijnStore();
		$objects->seed('caseType', ['id' => 'ct-woo', 'title' => 'Woo-verzoek']);
		$objects->seed('case', ['id' => 'case-w', 'caseType' => 'ct-woo']);
		$objects->seed('case', ['id' => 'case-x', 'caseType' => 'ct-gone']);
		$rows = [
			['id' => 'ti-1', 'case' => 'case-x', 'status' => 'lopend', 'endDateCurrent' => '2026-06-11'],
			['id' => 'ti-2', 'case' => 'case-x', 'status' => 'lopend', 'endDateCurrent' => '2026-06-12'],
			['id' => 'ti-3', 'case' => 'case-w', 'status' => 'lopend', 'endDateCurrent' => '2026-06-13'],
		];

		$out = $this->figures(objects: $objects)->forDashboard(rows: $rows, today: new DateTimeImmutable('2026-06-10'), perType: []);

		self::assertSame(3, $out['dueThisWeekCount']);
		self::assertSame('1 at Woo-verzoek', $out['dueThisWeekNote']);
	}//end testAnUnnamedCaseTypeIsLeftOutOfTheWeekNote()

	/**
	 * When no term due this week has a named case type, the note is empty.
	 *
	 * @return void
	 */
	public function testOnlyUnnamedCaseTypesGiveNoNote(): void {
		$objects = new FakeTermijnStore();
		$objects->seed('case', ['id' => 'case-x', 'caseType' => 'ct-gone']);

		$out = $this->figures(objects: $objects)->forDashboard(
			rows: [['id' => 'ti-1', 'case' => 'case-x', 'status' => 'lopend', 'endDateCurrent' => '2026-06-11']],
			today: new DateTimeImmutable('2026-06-10'),
			perType: []
		);

		self::assertSame(1, $out['dueThisWeekCount']);
		self::assertSame('', $out['dueThisWeekNote']);
	}//end testOnlyUnnamedCaseTypesGiveNoNote()
}//end class
