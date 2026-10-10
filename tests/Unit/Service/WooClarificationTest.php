<?php

/**
 * A clarification asks one question on a Woo case, and closes once answered.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-a-clarification-asks-one-question-in-plain-words-req-wds-004
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\AanvullingsverzoekResolutionService;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\InformationRequestService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCA\Dossiq\Woo\WooRequestIntake;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-WDS-004 through AanvullingsverzoekService::ask() and recordAnswer(), over a real store.
 *
 * @covers \OCA\Dossiq\Service\AanvullingsverzoekService
 *
 * @uses \OCA\Dossiq\Service\AanvullingsverzoekResolutionService
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class WooClarificationTest extends TestCase {

	/**
	 * The question of the scenario.
	 */
	private const QUESTION = 'Over welke speeltuinen en welke jaren gaat uw verzoek?';

	/**
	 * Cases and requests.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * The act that sends the letter and suspends the term.
	 *
	 * @var InformationRequestService&MockObject
	 */
	private InformationRequestService&MockObject $act;

	/**
	 * The service under test.
	 *
	 * @var AanvullingsverzoekService
	 */
	private AanvullingsverzoekService $requests;

	/**
	 * Seed a Woo case and a permit case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: 'woo-1', row: ['caseType' => WooRequestIntake::CASE_TYPE_ID, 'portalSubject' => 'subject-ref-anna']);
		$this->store->seed(schema: 'case', uuid: 'omv-1', row: ['caseType' => 'omgevingsvergunning-type']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? $default)
		);

		$this->act = $this->createMock(InformationRequestService::class);
		$this->requests = new AanvullingsverzoekService(act: $this->act, settingsService: $settings, logger: new NullLogger());
	}//end setUp()

	/**
	 * Ask a clarification on one case.
	 *
	 * @param string             $caseId The case.
	 * @param array<int, string> $items  Documents asked for alongside, which a clarification refuses.
	 *
	 * @return array<string, mixed> The written request.
	 */
	private function askClarification(string $caseId, array $items = []): array {
		return $this->requests->ask(
			caseId: $caseId,
			items: $items,
			recipient: 'anna@example.org',
			durationDays: 14,
			userId: 'behandelaar',
			kind: AanvullingsverzoekService::KIND_CLARIFICATION,
			question: self::QUESTION,
		);
	}//end askClarification()

	/**
	 * The request is open with the question and no items; the letter asks the question; the term stands still.
	 *
	 * @return void
	 */
	public function testAClarificationHasNoItems(): void {
		$this->act->expects($this->once())->method('ask')
			->with('woo-1', [self::QUESTION], 'anna@example.org', 14, '', '')
			->willReturn(['sent' => true, 'instance' => ['id' => 'term-1', 'pauseDeadline' => '2026-10-20']]);

		$request = $this->askClarification(caseId: 'woo-1');

		$this->assertSame('verduidelijking', $request['kind']);
		$this->assertSame(self::QUESTION, $request['summary']);
		$this->assertSame([], $request['missingItems']);
		$this->assertSame('open', $request['state']);
		$this->assertTrue($this->store->row(schema: 'case', uuid: 'woo-1')['waitingOnApplicant']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'aanvullingsverzoek', payload: $request, creating: true));
	}//end testAClarificationHasNoItems()

	/**
	 * Once answered, the handler closes it with nothing received, and the term resumes.
	 *
	 * @return void
	 */
	public function testItClosesOnceAnswered(): void {
		$this->act->method('ask')->willReturn(['sent' => true, 'instance' => ['id' => 'term-1', 'pauseDeadline' => '2026-10-20']]);
		$this->act->expects($this->once())->method('receive');
		$this->askClarification(caseId: 'woo-1');

		$closed = (new AanvullingsverzoekResolutionService(requests: $this->requests, act: $this->act, logger: new NullLogger()))
			->recordAnswer(caseId: 'woo-1', received: [], complete: true, userId: 'behandelaar');

		$this->assertSame('answered', $closed['state']);
	}//end testItClosesOnceAnswered()

	/**
	 * Another case type refuses the kind, and nothing is sent or stored.
	 *
	 * @return void
	 */
	public function testOnlyAWooCaseTakesAClarification(): void {
		$this->act->expects($this->never())->method('ask');

		try {
			$this->askClarification(caseId: 'omv-1');
			$this->fail('a clarification on a permit case must be refused');
		} catch (RefusedException $e) {
			$this->assertSame('aanvullingsverzoek_clarification_woo_only', $e->getMessage());
		}

		$this->assertSame([], $this->store->all(schema: 'aanvullingsverzoek'));
	}//end testOnlyAWooCaseTakesAClarification()

	/**
	 * A clarification that also lists documents is refused before any letter.
	 *
	 * @return void
	 */
	public function testAClarificationListsNoDocuments(): void {
		$this->act->expects($this->never())->method('ask');
		$this->expectException(RefusedException::class);

		$this->askClarification(caseId: 'woo-1', items: ['Bankafschrift']);
	}//end testAClarificationListsNoDocuments()
}//end class
