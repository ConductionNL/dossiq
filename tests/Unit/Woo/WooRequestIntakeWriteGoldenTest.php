<?php

/**
 * Golden test for the case WooRequestIntake writes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\Intake\SystemCaseStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\Dossiq\Woo\WooReceivedAnswers;
use OCA\Dossiq\Woo\WooReceivedTerm;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\Dossiq\Woo\WooRequestRefused;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every case write `start()` and `receive()` hand to the case store, and what they answer.
 *
 * @covers \OCA\Dossiq\Woo\WooRequestIntake
 * @uses \OCA\Dossiq\Woo\WooRequestForm
 * @uses \OCA\Dossiq\Woo\WooRequestRefused
 * @uses \OCA\Dossiq\Woo\WooRequesterProperties
 * @uses \OCA\Dossiq\Service\Intake\CollectionCaseLinks
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\Support\UnscopedObjectReader
 *
 * @spec openspec/specs/woo-request-intake/spec.md
 */
class WooRequestIntakeWriteGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	private const EARLIER = '4d4d4d4d-0000-4000-a000-0000000000aa';

	/**
	 * The calls the case store saw, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * Requests for `start()`, with the case type and what the store answers on the write.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>|null, 3: bool}>
	 */
	public static function starts(): array {
		$type = ['id' => 'type-1', 'initialStatus' => 'status-ontvangst'];
		$base = ['subjectRef' => 'subject-anna', 'onderwerp' => 'Parkeren', 'omschrijving' => 'Alle stukken', 'origin' => 'portal'];
		$written = ['id' => 'case-1', 'answer' => ['identifier' => 'ZAAK-1', 'deadline' => '2026-12-01']];

		return [
			'plain portal request' => [$base, $type, $written, false],
			'with requester details' => [$base + ['verzoekerNaam' => 'Anna de Vries', 'verzoekerEmail' => 'anna@example.com', 'verzoekerType' => 'burger'], $type, $written, true],
			'requester details, no definitions' => [$base + ['verzoekerNaam' => 'Anna de Vries'], $type, $written, false],
			'starts from an earlier request' => [$base + ['startFrom' => ' ' . self::EARLIER . ' '], $type, $written, false],
			'start from that is no uuid' => [$base + ['startFrom' => 'not-a-uuid'], $type, $written, false],
			'with a branch' => [$base + ['branch' => '000012345678', 'audience' => 'business'], $type, $written, false],
			'with a short branch' => [$base + ['branch' => '1234', 'audience' => 'citizen'], $type, $written, false],
			'with a branch number' => [$base + ['branch' => 123456789012], $type, $written, false],
			'type without id or status' => [$base, ['initialStatus' => ' '], $written, false],
			'pipelinq request' => [array_merge($base, ['origin' => 'pipelinq']), $type, ['id' => 'case-2', 'answer' => []], false],
			'everything at once' => [$base + ['verzoekerNaam' => ' Bob ', 'startFrom' => self::EARLIER, 'branch' => '000012345678', 'audience' => 'client'], $type, $written, true],
			'write fails' => [$base, $type, null, false],
			'write without id' => [$base, $type, ['id' => '', 'answer' => []], false],
		];
	}//end starts()

	/**
	 * What `start()` writes and answers matches the recording.
	 *
	 * @param array<string, mixed>      $request     The request.
	 * @param array<string, mixed>      $caseType    The current case type.
	 * @param array<string, mixed>|null $written     What the store answers on the write.
	 * @param bool                      $definitions Whether the case type declares the requester properties.
	 *
	 * @dataProvider starts
	 *
	 * @return void
	 */
	public function testStartWritesWhatWasRecorded(array $request, array $caseType, ?array $written, bool $definitions): void {
		$intake = $this->intake(caseType: $caseType, written: $written, definitions: $definitions);
		try {
			$answer = $intake->start(request: $request);
		} catch (WooRequestRefused $e) {
			$answer = ['refused' => $e->getReason(), 'detail' => $e->getDetail()];
		}

		$this->assertMatchesGolden(name: $this->goldenName(prefix: 'start'), value: ['calls' => $this->log, 'answer' => $answer]);
	}//end testStartWritesWhatWasRecorded()

	/**
	 * Answers for `receive()`, with the case type and what the store answers on the write.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null, 2?: array<string, string>}>
	 */
	public static function receipts(): array {
		$type = ['id' => 'type-1', 'initialStatus' => 'status-ontvangst'];

		return [
			'received form' => [$type, ['id' => 'case-9', 'answer' => []]],
			'received, write fails' => [$type, null],
			'received, type without status' => [['id' => 'type-2'], ['id' => 'case-8', 'answer' => []]],
			'received with a named initiator' => [$type, ['id' => 'case-7', 'answer' => []], ['initiatorDisplayName' => 'Dora']],
			'received with a blank initiator' => [$type, ['id' => 'case-6', 'answer' => []], ['initiatorDisplayName' => ' ']],
		];
	}//end receipts()

	/**
	 * What `receive()` writes and answers matches the recording.
	 *
	 * @param array<string, mixed>      $caseType The current case type.
	 * @param array<string, mixed>|null $written  What the store answers on the write.
	 * @param array<string, string>     $extra    More intake fields the answers reader gives.
	 *
	 * @dataProvider receipts
	 *
	 * @return void
	 */
	public function testReceiveWritesWhatWasRecorded(array $caseType, ?array $written, array $extra = []): void {
		$answers = $this->createMock(WooReceivedAnswers::class);
		$answers->method('toRequest')->willReturn(
			['onderwerp' => 'Notulen', 'omschrijving' => 'Raad 2025', 'origin' => 'portal-form', 'verzoekerNaam' => 'Cees']
		);
		$answers->method('intake')->willReturn(['intakeChannel' => 'website', 'receivedAt' => '2026-10-01T09:00:00+02:00'] + $extra);

		$term = $this->createMock(WooReceivedTerm::class);
		$term->method('isAvailable')->willReturn(true);
		$term->method('answer')->willReturnCallback(
			fn (string $caseId, array $case, string $caseUrl): array => ['outcome' => 'armed', 'caseId' => $caseId, 'case' => $case, 'caseUrl' => $caseUrl]
		);

		$intake = $this->intake(caseType: $caseType, written: $written, definitions: true, term: $term, answers: $answers);
		$answer = $intake->receive(answers: ['requestedInformation' => 'Notulen'], receivedAt: '2026-10-01T09:00:00+02:00');

		$this->assertMatchesGolden(name: $this->goldenName(prefix: 'receive'), value: ['calls' => $this->log, 'answer' => $answer]);
	}//end testReceiveWritesWhatWasRecorded()

	/**
	 * The golden file of the running case.
	 *
	 * @param string $prefix The method under test.
	 *
	 * @return string
	 */
	private function goldenName(string $prefix): string {
		return 'woo-request-intake-write/' . $prefix . '-' . trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
	}//end goldenName()

	/**
	 * The intake over a recording case store.
	 *
	 * @param array<string, mixed>      $caseType    The current case type.
	 * @param array<string, mixed>|null $written     What the store answers on the write.
	 * @param bool                      $definitions Whether the case type declares the requester properties.
	 * @param WooReceivedTerm|null      $term        The term reader for `receive()`.
	 * @param WooReceivedAnswers|null   $answers     The answers reader for `receive()`.
	 *
	 * @return WooRequestIntake
	 */
	private function intake(
		array $caseType,
		?array $written,
		bool $definitions,
		?WooReceivedTerm $term = null,
		?WooReceivedAnswers $answers = null,
	): WooRequestIntake {
		$register = new InMemoryRegister();
		if ($definitions === true) {
			foreach (['verzoekerNaam', 'verzoekerEmail', 'verzoekerType'] as $index => $name) {
				$register->seed(
					schema: 'propertyDefinition',
					uuid: 'definition-' . ($index + 1),
					row: ['caseType' => (string)($caseType['id'] ?? WooRequestIntake::CASE_TYPE_ID), 'name' => $name]
				);
			}
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'property_definition_schema' => 'propertyDefinition'][$key] ?? $default
		);
		$settings->method('getPortalName')->willReturn('Mijn Zuiddrecht');

		$cases = $this->createMock(SystemCaseStore::class);
		$cases->method('currentCaseType')->willReturnCallback(
			function (object $objectService, string $register, string $caseTypeId) use ($caseType): array {
				$this->log[] = ['currentCaseType' => ['register' => $register, 'caseTypeId' => $caseTypeId]];
				return $caseType;
			}
		);
		$cases->method('write')->willReturnCallback(
			function (object $objectService, string $register, array $case, ?array $caseType = null) use ($written): ?array {
				$this->log[] = ['write' => ['register' => $register, 'case' => $case, 'caseType' => $caseType]];
				return $written;
			}
		);
		$cases->method('readCase')->willReturnCallback(
			function (object $objectService, string $register, string $caseId): array {
				$this->log[] = ['readCase' => ['register' => $register, 'caseId' => $caseId]];
				return ['id' => $caseId, 'read' => true];
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://gemeente.test' . $path);

		return new WooRequestIntake(
			settingsService: $settings,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class),
			receivedTerm: $term,
			receivedAnswers: $answers,
			cases: $cases,
		);
	}//end intake()
}//end class
