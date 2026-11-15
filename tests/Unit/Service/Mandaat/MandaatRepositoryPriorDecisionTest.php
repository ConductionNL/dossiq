<?php

/**
 * The prior adopted decision of a decision number
 *
 * Pins which stored decision MandaatRepository::findPriorDecision answers:
 * the first determined one, skipping the excluded id, and none when the
 * store is absent, unconfigured or cannot be read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Mandaat
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

namespace OCA\Dossiq\Tests\Unit\Service\Mandaat;

use OCA\Dossiq\Service\Mandaat\MandaatRepository;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which decision counts as the prior adopted one.
 *
 * @covers \OCA\Dossiq\Service\Mandaat\MandaatRepository
 *
 * @spec openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md
 */
final class MandaatRepositoryPriorDecisionTest extends TestCase {
	/**
	 * The repository over a store that answers the given rows, or throws.
	 *
	 * @param array|\Throwable|null $rows   The rows the slug search answers, the error it throws, or null for no store.
	 * @param array                 $config The config values.
	 *
	 * @return MandaatRepository
	 */
	private function repository(array|\Throwable|null $rows, array $config = ['register' => 'dossiq', 'mandaterings_besluit_schema' => 'besluit']): MandaatRepository {
		$store = null;
		if ($rows !== null) {
			$store = new class ($rows) {
				/**
				 * Constructor.
				 *
				 * @param array|\Throwable $rows The answer.
				 */
				public function __construct(private array|\Throwable $rows) {
				}

				/**
				 * The rows of the decision number asked for.
				 *
				 * @return array
				 */
				public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
					if ($this->rows instanceof \Throwable) {
						throw $this->rows;
					}

					return array_values(array_filter($this->rows, static fn (array $row): bool => ($row['decisionNumber'] ?? '') === $filters['decisionNumber']));
				}
			};
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new MandaatRepository(settingsService: $settings, logger: $this->createMock(LoggerInterface::class));
	}//end repository()

	/**
	 * The first determined decision is the prior one; the excluded id and other statuses are skipped.
	 *
	 * @return void
	 */
	public function testTheFirstDeterminedDecisionOutsideTheExcludedIdIsThePriorOne(): void {
		$rows = [
			['id' => 'd-1', 'decisionNumber' => 'B-7', 'status' => 'draft'],
			['id' => 'd-2', 'decisionNumber' => 'B-7', 'status' => 'determined'],
			['id' => 'd-3', 'decisionNumber' => 'B-7', 'status' => 'determined'],
			['id' => 'd-4', 'decisionNumber' => 'B-8', 'status' => 'determined'],
			['decisionNumber' => 'B-9', 'status' => 'determined'],
		];
		$repository = $this->repository(rows: $rows);

		$this->assertSame('d-2', $repository->findPriorDecision('B-7')['id']);
		$this->assertSame('d-3', $repository->findPriorDecision('B-7', 'd-2')['id']);
		$this->assertSame('d-2', $repository->findPriorDecision('B-7', 'd-1')['id']);
		$this->assertNull($repository->findPriorDecision('B-8', 'd-4'));
		$this->assertSame(['decisionNumber' => 'B-9', 'status' => 'determined'], $repository->findPriorDecision('B-9', 'd-x'));
		$this->assertNull($repository->findPriorDecision('B-1'));
	}//end testTheFirstDeterminedDecisionOutsideTheExcludedIdIsThePriorOne()

	/**
	 * No store, no register, no schema or a failed read answer no prior decision.
	 *
	 * @return void
	 */
	public function testNoReadableStoreAnswersNone(): void {
		$rows = [['id' => 'd-2', 'decisionNumber' => 'B-7', 'status' => 'determined']];

		$this->assertNull($this->repository(rows: null)->findPriorDecision('B-7'));
		$this->assertNull($this->repository(rows: $rows, config: ['register' => 'dossiq'])->findPriorDecision('B-7'));
		$this->assertNull($this->repository(rows: $rows, config: ['mandaterings_besluit_schema' => 'besluit'])->findPriorDecision('B-7'));
		$this->assertNull($this->repository(rows: new RuntimeException('down'))->findPriorDecision('B-7'));
	}//end testNoReadableStoreAnswersNone()
}//end class
