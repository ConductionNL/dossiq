<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\StufRegisterAccessInterface;
use OCA\Dossiq\Listener\StufProviderQueryListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Integriq\Event\StufCaseQueryEvent;
use OCA\Integriq\Event\StufPersonQueryEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The StUF provider answers from what OpenRegister actually returns, leaves a
 * question unanswered when the case store cannot be read, and never maps a
 * zaaktype it cannot place.
 *
 * @covers \OCA\Dossiq\Listener\StufProviderQueryListener
 */
class StufProviderQueryListenerEdgeTest extends TestCase {

	/**
	 * @var mixed What the case store's findAll answers, or a Throwable it throws.
	 */
	private mixed $found = [];

	/**
	 * @var array<string, string> The settings.
	 */
	private array $config = ['register' => 'dossiq', 'case_schema' => 'case'];

	/**
	 * @var array<string, string> The endpoint's zaaktype mappings.
	 */
	private array $mappings = ['B1026' => 'Kapvergunning'];

	/**
	 * @var list<string> The warnings logged.
	 */
	private array $warnings = [];

	/**
	 * @return void
	 */
	public function testSerialisableRowsUnderResultsAreAnsweredWithStufDates(): void {
		$row = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['identifier' => '2026-0042', 'title' => 'Kap eik', 'startDate' => '2026-03-01T09:00:00+01:00', 'endDate' => 'not a date', 'caseType' => 'ct-unmapped'];
			}
		};
		$this->found = ['results' => [$row, 'junk']];
		$event = $this->caseQuery();

		$this->listener(caseTypeIdentifier: '')->handle($event);

		$this->assertSame(['cases' => [['zaakidentificatie' => '2026-0042', 'omschrijving' => 'Kap eik', 'startdatum' => '20260301']]], $event->getAnswer());
	}//end testSerialisableRowsUnderResultsAreAnsweredWithStufDates()

	/**
	 * @return void
	 */
	public function testWithoutMappingsOrACaseTypeNoZaaktypeIsSent(): void {
		$this->found = [['identifier' => '2026-0042', 'caseType' => 'B1026'], ['identifier' => '2026-0043']];
		$this->mappings = [];
		$event = $this->caseQuery();

		$this->listener(caseTypeIdentifier: 'B1026')->handle($event);

		$this->assertSame(['cases' => [['zaakidentificatie' => '2026-0042'], ['zaakidentificatie' => '2026-0043']]], $event->getAnswer());
	}//end testWithoutMappingsOrACaseTypeNoZaaktypeIsSent()

	/**
	 * @return void
	 */
	public function testAnUnreadableResultLeavesTheQuestionUnanswered(): void {
		foreach (['not an array', ['results' => 'not a list']] as $found) {
			$this->found = $found;
			$event = $this->caseQuery();

			$this->listener()->handle($event);

			$this->assertFalse($event->isAnswered());
		}
	}//end testAnUnreadableResultLeavesTheQuestionUnanswered()

	/**
	 * @return void
	 */
	public function testAnUnconfiguredCaseRegisterLeavesAPersonQueryUnanswered(): void {
		$this->config = ['register' => 'dossiq'];
		$event = new StufPersonQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-2', bsn: '999993653');

		$this->listener()->handle($event);

		$this->assertFalse($event->isAnswered());
		$this->assertSame(['StUF provider query: the case register is not configured'], $this->warnings);
	}//end testAnUnconfiguredCaseRegisterLeavesAPersonQueryUnanswered()

	/**
	 * @return void
	 */
	public function testABrokenCaseStoreLeavesAPersonQueryUnanswered(): void {
		$this->found = new RuntimeException('database down');
		$event = new StufPersonQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-2', bsn: '999993653');

		$this->listener()->handle($event);

		$this->assertFalse($event->isAnswered());
		$this->assertSame(['StUF provider query: the case lookup failed: {error}'], $this->warnings);
	}//end testABrokenCaseStoreLeavesAPersonQueryUnanswered()

	/**
	 * @return void
	 */
	public function testAQueryWithoutASourceAndAnotherEventAreLeftAlone(): void {
		$event = new StufCaseQueryEvent(sourceId: '', referentienummer: 'REQ-1', criteria: ['identificatie' => '2026-0042']);
		$listener = $this->listener();

		$listener->handle($event);
		$listener->handle(new Event());

		$this->assertFalse($event->isAnswered());
	}//end testAQueryWithoutASourceAndAnotherEventAreLeftAlone()

	/**
	 * @return StufCaseQueryEvent A zakLv01 for 2026-0042 from dossiq's source.
	 */
	private function caseQuery(): StufCaseQueryEvent {
		return new StufCaseQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-1', criteria: ['identificatie' => '2026-0042']);
	}//end caseQuery()

	/**
	 * @param string $caseTypeIdentifier The catalogue identifier every case type reads as.
	 *
	 * @return StufProviderQueryListener The listener over the configured doubles.
	 */
	private function listener(string $caseTypeIdentifier = 'B1026'): StufProviderQueryListener {
		$register = $this->createMock(StufRegisterAccessInterface::class);
		$register->method('findOne')->willReturnCallback(
			fn (string $schema, array $filters): ?array => ($filters === ['integriqSource' => 'src-9'] ? ['id' => 'ep-1', 'zaaktypeMappings' => $this->mappings] : null)
		);

		$objects = new class($this) {
			public function __construct(private readonly StufProviderQueryListenerEdgeTest $test) {
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
				return $this->test->found();
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));

		$caseTypes = $this->createMock(CaseObjectReaderInterface::class);
		$caseTypes->method('loadCaseType')->willReturn(['identifier' => $caseTypeIdentifier]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new StufProviderQueryListener(register: $register, settings: $settings, caseTypes: $caseTypes, logger: $logger);
	}//end listener()

	/**
	 * What the case store answers; throws the configured failure.
	 *
	 * @return mixed
	 */
	public function found(): mixed {
		if ($this->found instanceof \Throwable) {
			throw $this->found;
		}

		return $this->found;
	}//end found()
}//end class
