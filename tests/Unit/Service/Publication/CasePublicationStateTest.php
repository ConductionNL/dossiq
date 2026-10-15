<?php

/**
 * Unit tests for what publishing reads and writes on the case itself.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Publication
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\WithheldListRecorder;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseLedger;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pins the ledger's answers: which decision a case publishes, and the state it writes back.
 *
 * @covers \OCA\Dossiq\Woo\WooCaseLedger
 *
 * @uses \OCA\Dossiq\Service\Publication\PublicationResultLink
 * @uses \OCA\Dossiq\Service\Publication\WithheldEntries
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class CasePublicationStateTest extends TestCase {

	/**
	 * The cases and decisions.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Seed an empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * Settings over the store, or over no object service at all.
	 *
	 * @param bool $withStore Whether OpenRegister is there.
	 *
	 * @return SettingsService
	 */
	private function settings(bool $withStore = true): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withStore === true ? $this->store : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case', 'decision_schema' => 'decision'][$key] ?? $default)
		);

		return $settings;
	}//end settings()

	/**
	 * One decision on the case that carries a summary is the case's decision.
	 *
	 * @return void
	 */
	public function testTheOneDecisionWithASummaryIsFound(): void {
		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['id' => 'd-1', 'case' => 'case-1', 'wooSummary' => ['total' => 3]]);
		$this->store->seed(schema: 'decision', uuid: 'd-2', row: ['id' => 'd-2', 'case' => 'case-1']);
		$this->store->seed(schema: 'decision', uuid: 'd-3', row: ['id' => 'd-3', 'case' => 'case-2', 'wooSummary' => ['total' => 1]]);

		$ledger = new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger());

		$this->assertSame(['decisionId' => 'd-1', 'refusal' => []], $ledger->resolveWooDecision(caseId: 'case-1'));
	}//end testTheOneDecisionWithASummaryIsFound()

	/**
	 * No such decision, or several, is refused with its reason.
	 *
	 * @return void
	 */
	public function testNoneOrSeveralDecisionsAreRefused(): void {
		$ledger = new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger());
		$this->assertSame(
			['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'no_woo_decision']],
			$ledger->resolveWooDecision(caseId: 'case-1')
		);

		$this->store->seed(schema: 'decision', uuid: 'd-1', row: ['id' => 'd-1', 'case' => 'case-1', 'wooSummary' => ['total' => 3]]);
		$this->store->seed(schema: 'decision', uuid: 'd-2', row: ['uuid' => 'd-2', 'case' => 'case-1', 'wooSummary' => ['total' => 2]]);
		$this->assertSame(
			['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'several_woo_decisions', 'decisionIds' => ['d-1', 'd-2']]],
			$ledger->resolveWooDecision(caseId: 'case-1')
		);
	}//end testNoneOrSeveralDecisionsAreRefused()

	/**
	 * Without OpenRegister there is no decision to find.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNoDecisionIsFound(): void {
		$ledger = new WooCaseLedger(settingsService: $this->settings(withStore: false), logger: new NullLogger());

		$this->assertSame('no_woo_decision', $ledger->resolveWooDecision(caseId: 'case-1')['refusal']['reason']);
	}//end testWithoutOpenRegisterNoDecisionIsFound()

	/**
	 * The state write carries the result link, and leaves other case fields alone.
	 *
	 * @return void
	 */
	public function testTheStateWriteCarriesTheResultLink(): void {
		$this->store->seed(schema: 'case', uuid: 'case-1', row: ['identifier' => '2026-0001', 'title' => 'Verzoek']);
		$ledger = new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger());

		$ledger->writeCaseState(caseId: 'case-1', changes: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => 'https://example.org/p/1']);

		$case = $this->store->row(schema: 'case', uuid: 'case-1');
		$this->assertSame('Verzoek', $case['title']);
		$this->assertSame('published', $case['wooPublicationStatus']);
		$this->assertSame('https://example.org/p/1', $case['resultLink']['url']);
	}//end testTheStateWriteCarriesTheResultLink()

	/**
	 * No write without OpenRegister or without a case id.
	 *
	 * @return void
	 */
	public function testNoWriteWithoutStoreOrCase(): void {
		(new WooCaseLedger(settingsService: $this->settings(withStore: false), logger: new NullLogger()))
			->writeCaseState(caseId: 'case-1', changes: ['wooPublicationStatus' => 'ready']);
		(new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger()))
			->writeCaseState(caseId: '', changes: ['wooPublicationStatus' => 'ready']);

		$this->assertSame([], $this->store->all(schema: 'case'));
	}//end testNoWriteWithoutStoreOrCase()

	/**
	 * A failed state write is logged as a warning, never thrown.
	 *
	 * @return void
	 */
	public function testAFailedStateWriteIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new WooCaseLedger(settingsService: $this->settings(), logger: $logger))
			->writeCaseState(caseId: 'case-gone', changes: ['wooPublicationStatus' => 'ready']);
	}//end testAFailedStateWriteIsLogged()

	/**
	 * A path stays a path without a URL generator, and becomes absolute with one.
	 *
	 * @return void
	 */
	public function testAbsoluteLinks(): void {
		$this->assertSame('/p/1', (new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger()))->absolute(path: '/p/1'));

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://zuiddrecht.nl' . $path);
		$ledger = new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger(), urlGenerator: $urls);

		$this->assertSame('https://zuiddrecht.nl/p/1', $ledger->absolute(path: '/p/1'));
	}//end testAbsoluteLinks()

	/**
	 * Without a set writer a delivery is the send alone, with no set id.
	 *
	 * @return void
	 */
	public function testDeliverWithoutAWriterIsTheSendAlone(): void {
		$ledger = new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger());

		$this->assertSame(
			['publicationId' => 'pub-1', 'setId' => ''],
			$ledger->deliver(caseId: 'case-1', decisionId: 'd-1', items: [], send: static fn (): string => 'pub-1')
		);
		$this->assertSame('woo_delivered_set_not_written', WooCaseLedger::SET_NOT_WRITTEN);
	}//end testDeliverWithoutAWriterIsTheSendAlone()

	/**
	 * Without a recorder nothing is recorded, and the reason says the receiving side is missing.
	 *
	 * @return void
	 */
	public function testWithoutARecorderNothingIsRecorded(): void {
		$ledger = new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger());

		$this->assertSame(
			['record' => ['status' => 'not-recorded', 'reason' => WithheldListRecorder::REASON_TOO_OLD], 'note' => ''],
			$ledger->recordWithheld(publicationId: 'pub-1', assessments: [['documentRef' => 'doc-1', 'classification' => 'niet_openbaar']])
		);
	}//end testWithoutARecorderNothingIsRecorded()

	/**
	 * The withheld list is built from the Woo classification and its grounds field.
	 *
	 * @return void
	 */
	public function testTheWithheldListReadsTheWooClassification(): void {
		$recorder = $this->createMock(WithheldListRecorder::class);
		$recorder->expects($this->once())->method('record')
			->with(
				$this->identicalTo('pub-1'),
				$this->identicalTo([['position' => 1, 'grounds' => ['5.1.2e']]])
			)
			->willReturn(['status' => 'recorded']);
		$recorder->method('describe')->willReturn('1 document niet openbaar gemaakt.');

		$ledger = new WooCaseLedger(settingsService: $this->settings(), logger: new NullLogger(), withheldRecorder: $recorder);
		$answer = $ledger->recordWithheld(publicationId: 'pub-1', assessments: [
			['documentRef' => 'doc-1', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2e']],
			['documentRef' => 'doc-2', 'classification' => 'openbaar'],
		]);

		$this->assertSame(['record' => ['status' => 'recorded'], 'note' => '1 document niet openbaar gemaakt.'], $answer);
	}//end testTheWithheldListReadsTheWooClassification()

	/**
	 * Without a set writer a withdraw stamps nothing and throws nothing.
	 *
	 * @return void
	 */
	public function testMarkWithdrawnWithoutAWriterIsQuiet(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		(new WooCaseLedger(settingsService: $this->settings(), logger: $logger))->markWithdrawn(caseId: 'case-1', publicationId: 'pub-1');
	}//end testMarkWithdrawnWithoutAWriterIsQuiet()
}//end class
