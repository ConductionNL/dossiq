<?php

/**
 * Unit tests for WooCaseLedger: it finds the one Woo decision of a case,
 * mirrors the publication state onto the case with the requester's link, and
 * degrades when its optional collaborators are not wired.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\Publication\WithheldListRecorder;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooCaseLedger;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Woo\WooCaseLedger
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Woo\WooResultLink
 */
class WooCaseLedgerTest extends TestCase {

	private SettingsService&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'decision_schema' => 'decision', 'case_schema' => 'case'][$key] ?? ''
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An object service answering the given decisions and stored case, recording calls.
	 *
	 * @param list<mixed>               $decisions The decision rows.
	 * @param array<string, mixed>|null $case      The stored case, or null to fail the write.
	 */
	private function objectService(array $decisions = [], ?array $case = []): object {
		return new class($decisions, $case) {
			/** @var list<array<int|string, mixed>> */
			public array $calls = [];

			public function __construct(private array $decisions, private ?array $case) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = ['search', $register, $schema, $filters];
				return $this->decisions;
			}

			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				if ($this->case === null) {
					throw new RuntimeException('locked');
				}

				$this->calls[] = ['patch', $objectId, $data, $register, $schema];
				return $data;
			}
		};
	}//end objectService()

	private function ledger(?object $objectService, ?IURLGenerator $urls = null, ?WooDeliveredSetWriter $sets = null): WooCaseLedger {
		$this->settings->method('getObjectService')->willReturn($objectService);
		return new WooCaseLedger($this->settings, $this->logger, $urls, $sets);
	}//end ledger()

	public function testTheOneWooDecisionOfTheCaseIsFound(): void {
		$service = $this->objectService(
			[
				['@self' => ['id' => 'dec-1'], 'case' => 'case-1', 'wooSummary' => 'Deels openbaar'],
				['id' => 'dec-2', 'case' => 'case-1', 'wooSummary' => ''],
				['id' => 'dec-3', 'case' => 'case-9', 'wooSummary' => 'Openbaar'],
				['uuid' => 'dec-1', 'case' => 'case-1', 'wooSummary' => 'again'],
			]
		);

		$this->assertSame(['decisionId' => 'dec-1', 'refusal' => []], $this->ledger($service)->resolveWooDecision('case-1'));
		$this->assertSame([['search', 'dossiq', 'decision', ['case' => 'case-1', '_limit' => 50]]], $service->calls);
	}//end testTheOneWooDecisionOfTheCaseIsFound()

	public function testNoWooDecisionIsARefusal(): void {
		$expected = ['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'no_woo_decision']];

		$this->assertSame($expected, $this->ledger($this->objectService([['id' => 'dec-2', 'case' => 'case-1']]))->resolveWooDecision('case-1'));
	}//end testNoWooDecisionIsARefusal()

	public function testWithoutAnObjectServiceThereIsNoWooDecision(): void {
		$this->assertSame(
			['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'no_woo_decision']],
			$this->ledger(null)->resolveWooDecision('case-1')
		);
	}//end testWithoutAnObjectServiceThereIsNoWooDecision()

	public function testSeveralWooDecisionsAreARefusalNamingThem(): void {
		$service = $this->objectService(
			[
				['id' => 'dec-1', 'case' => 'case-1', 'wooSummary' => 'a'],
				['id' => 'dec-2', 'case' => 'case-1', 'wooSummary' => 'b'],
			]
		);

		$this->assertSame(
			['decisionId' => '', 'refusal' => ['available' => false, 'reason' => 'several_woo_decisions', 'decisionIds' => ['dec-1', 'dec-2']]],
			$this->ledger($service)->resolveWooDecision('case-1')
		);
	}//end testSeveralWooDecisionsAreARefusalNamingThem()

	public function testTheCaseStateIsPatchedWithTheRequestersLink(): void {
		$service = $this->objectService();

		$this->ledger($service)->writeCaseState('case-1', ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => 'https://open.example/1']);

		$this->assertSame(
			[
				[
					'patch',
					'case-1',
					[
						'wooPublicationStatus' => 'published',
						'wooPublicationUrl' => 'https://open.example/1',
						'resultLink' => ['label' => 'Bekijk wat openbaar is gemaakt', 'url' => 'https://open.example/1'],
					],
					'dossiq',
					'case',
				],
			],
			$service->calls
		);
	}//end testTheCaseStateIsPatchedWithTheRequestersLink()

	public function testAFailingCaseWriteIsLoggedNotThrown(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'WooPublicationService: the case could not show its publication state',
			['app' => 'dossiq', 'caseId' => 'case-1', 'error' => 'locked']
		);

		$this->ledger($this->objectService([], null))->writeCaseState('case-1', ['wooPublicationStatus' => 'withdrawn']);
	}//end testAFailingCaseWriteIsLoggedNotThrown()

	public function testNoCaseOrNoObjectServiceWritesNothing(): void {
		$service = $this->objectService();
		$this->logger->expects($this->never())->method('warning');

		$this->ledger($service)->writeCaseState('', ['wooPublicationStatus' => 'published']);
		$this->assertSame([], $service->calls);

		(new WooCaseLedger($this->createMock(SettingsService::class), $this->logger))->writeCaseState('case-1', ['x' => 1]);
	}//end testNoCaseOrNoObjectServiceWritesNothing()

	public function testWithoutAUrlGeneratorThePathStaysRelative(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('getAbsoluteURL')->with('/apps/dossiq/woo/1')->willReturn('https://nc.example/apps/dossiq/woo/1');

		$this->assertSame('/apps/dossiq/woo/1', $this->ledger(null)->absolute('/apps/dossiq/woo/1'));
		$this->assertSame('https://nc.example/apps/dossiq/woo/1', $this->ledger(null, $urls)->absolute('/apps/dossiq/woo/1'));
	}//end testWithoutAUrlGeneratorThePathStaysRelative()

	public function testWithoutADeliveredSetWriterTheSendAloneAnswers(): void {
		$sent = 0;
		$send = static function () use (&$sent): int {
			$sent++;
			return 77;
		};

		$this->assertSame(['publicationId' => '77', 'setId' => ''], $this->ledger(null)->deliver('case-1', 'dec-1', [['id' => 'f-1']], $send));
		$this->assertSame(1, $sent);
	}//end testWithoutADeliveredSetWriterTheSendAloneAnswers()

	public function testWithADeliveredSetWriterDeliveryAndWithdrawalGoThroughIt(): void {
		$sets = $this->createMock(WooDeliveredSetWriter::class);
		$send = static fn (): string => 'pub-1';
		$sets->expects($this->once())->method('deliver')
			->with('case-1', 'dec-1', [['id' => 'f-1']], $send)
			->willReturn(['publicationId' => 'pub-1', 'setId' => 'set-1']);
		$sets->expects($this->once())->method('markWithdrawn')->with('case-1', 'pub-1')
			->willThrowException(new RuntimeException('set gone'));
		$this->logger->expects($this->once())->method('error')->with(
			'WooPublicationService: the delivered set could not record the withdraw',
			['app' => 'dossiq', 'publicationId' => 'pub-1', 'error' => 'set gone']
		);
		$ledger = $this->ledger(null, null, $sets);

		$this->assertSame(['publicationId' => 'pub-1', 'setId' => 'set-1'], $ledger->deliver('case-1', 'dec-1', [['id' => 'f-1']], $send));
		$ledger->markWithdrawn('case-1', 'pub-1');
	}//end testWithADeliveredSetWriterDeliveryAndWithdrawalGoThroughIt()

	public function testWithoutAWithheldRecorderNothingIsRecorded(): void {
		$this->assertSame(
			['record' => ['status' => 'not-recorded', 'reason' => WithheldListRecorder::REASON_TOO_OLD], 'note' => ''],
			$this->ledger(null)->recordWithheld('pub-1', [['id' => 'f-1', 'openbaarheid' => 'niet_openbaar']])
		);
	}//end testWithoutAWithheldRecorderNothingIsRecorded()
}//end class
