<?php

/**
 * Giving every case that already exists its first holding.
 *
 * The property worth protecting is the DATE. A backfill that stamps the holding
 * with the moment the upgrade ran produces a chain that looks complete and
 * says the wrong thing: every case in the instance would read as having changed
 * hands on the night of the release. So the holding is dated from the case's
 * own start, and the test that matters is the one comparing the two.
 *
 * MUTATION-CHECKED 2026-09-18: making `startOf()` return an empty string (which
 * makes the chain fall back to now) reddens testTheHoldingIsDatedFromTheCase on
 * the `from` assertion. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Migration;

use OCA\Dossiq\Repair\BackfillCaseCustody;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;

/**
 * A store that can list every case, as the repair step walks them.
 */
final class CcbCaseStore extends InMemoryRegister {

	/**
	 * Every case row, as `findAll()` hands them back.
	 *
	 * @param array<string, mixed> $config The filters, which this store reads only for the schema.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(array $config): array {
		$schema = (string)($config['filters']['schema'] ?? '');

		return $this->all(schema: $schema);
	}//end findAll()

	/**
	 * Run a closure, as OpenRegister's system-context helper does.
	 *
	 * @param callable $operation The closure.
	 *
	 * @return void
	 */
	public function runAsSystem(callable $operation): void {
		$operation();
	}//end runAsSystem()
}//end class

/**
 * The first holding of every existing case.
 *
 * @covers \OCA\Dossiq\Repair\BackfillCaseCustody
 * @uses \OCA\Dossiq\Service\Custody\CaseCustodyChain
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class CaseCustodyBackfillTest extends TestCase {
	use MakesCaseDateNormaliser;


	/**
	 * The store the step reads and writes.
	 *
	 * @var CcbCaseStore
	 */
	private CcbCaseStore $store;

	/**
	 * Two cases with no chain at all.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new CcbCaseStore();
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: [
				'title' => 'Dakkapel Prinsengracht 12',
				'assignedGroup' => 'vergunningen',
				'assignee' => 'jan',
				'startDate' => '2026-03-03T09:00:00+01:00',
			],
		);
		$this->store->seed(
			schema: 'case',
			uuid: 'case-2',
			row: [
				'title' => 'Sloopmelding Nieuwe Gracht 4',
				'assignedGroup' => 'toezicht',
				'assignee' => '',
				'startDate' => '2025-11-20T08:00:00+01:00',
			],
		);
	}//end setUp()

	/**
	 * Every case ends up with exactly one open holding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testEveryCaseGetsOneOpenHolding(): void {
		$this->step()->run($this->createMock(originalClassName: IOutput::class));

		$chain = $this->chain();
		foreach (['case-1', 'case-2'] as $caseId) {
			$holdings = $chain->holdings(caseId: $caseId);
			self::assertCount(1, $holdings, 'The chain starts with one holding, not none and not two.');
			self::assertTrue($holdings[0]['open'], 'And that holding is the one running now.');
		}

		self::assertSame('vergunningen', $chain->openHoldingFor(caseId: 'case-1')['organisationUnit']);
		self::assertSame('jan', $chain->openHoldingFor(caseId: 'case-1')['handler']);
		self::assertSame('toezicht', $chain->openHoldingFor(caseId: 'case-2')['organisationUnit']);
	}//end testEveryCaseGetsOneOpenHolding()

	/**
	 * The holding is dated from the case, not from the upgrade.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testTheHoldingIsDatedFromTheCase(): void {
		$this->step()->run($this->createMock(originalClassName: IOutput::class));

		self::assertSame(
			'2026-03-03T09:00:00+01:00',
			$this->chain()->openHoldingFor(caseId: 'case-1')['from'],
			'A holding stamped with the moment the upgrade ran says every case changed hands that night.',
		);
		self::assertSame(
			'2025-11-20T08:00:00+01:00',
			$this->chain()->openHoldingFor(caseId: 'case-2')['from'],
		);
	}//end testTheHoldingIsDatedFromTheCase()

	/**
	 * The backfill says out loud that it was a backfill.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testABackfilledHoldingSaysSo(): void {
		$this->step()->run($this->createMock(originalClassName: IOutput::class));

		self::assertSame(
			BackfillCaseCustody::REASON,
			$this->chain()->openHoldingFor(caseId: 'case-1')['reason'],
			'A holding with no reason cannot be told from a move somebody forgot to explain.',
		);
	}//end testABackfilledHoldingSaysSo()

	/**
	 * Running it twice does not double the chain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testRunningItTwiceChangesNothing(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$this->step()->run($output);
		$this->step()->run($output);

		self::assertCount(1, $this->chain()->holdings(caseId: 'case-1'));
		self::assertCount(1, $this->chain()->holdings(caseId: 'case-2'));
	}//end testRunningItTwiceChangesNothing()

	/**
	 * A case that already moved keeps the chain it has.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function testACaseThatAlreadyHasAChainIsLeftAlone(): void {
		$chain = $this->chain();
		$chain->begin(
			caseId: 'case-1',
			organisationUnit: 'vergunningen',
			handler: 'jan',
			from: '2026-03-03T09:00:00+01:00',
			reason: 'Registered',
			movedBy: 'jan',
		);
		$chain->move(
			caseId: 'case-1',
			organisationUnit: 'toezicht',
			handler: 'sofie',
			reason: 'Handhaving',
			movedBy: 'jan',
			at: '2026-04-12T14:30:00+02:00',
		);

		$this->step()->run($this->createMock(originalClassName: IOutput::class));

		self::assertCount(2, $chain->holdings(caseId: 'case-1'), 'The backfill adds nothing to a chain that exists.');
		self::assertSame('toezicht', $chain->openHoldingFor(caseId: 'case-1')['organisationUnit']);
	}//end testACaseThatAlreadyHasAChainIsLeftAlone()

	/**
	 * The repair step under test.
	 *
	 * @return BackfillCaseCustody The step.
	 */
	private function step(): BackfillCaseCustody {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_custody_schema' => 'caseCustody',
				];

				return ($map[$key] ?? $default);
			}
		);

		return new BackfillCaseCustody(
			settingsService: $this->settings(),
			custody: $this->chain(),
			appConfig: $appConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end step()

	/**
	 * The chain the step writes into.
	 *
	 * @return CaseCustodyChain The chain.
	 */
	private function chain(): CaseCustodyChain {
		return new CaseCustodyChain(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			dates: $this->caseDates(),
		);
	}//end chain()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_custody_schema' => 'caseCustody',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
