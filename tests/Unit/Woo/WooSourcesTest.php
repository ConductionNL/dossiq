<?php

/**
 * Woo Sources Test
 *
 * The sources a handler can search from a Woo case, and integriq's search and
 * fetch, dispatched as integriq's real typed commands (the contract stubs in
 * tests/Stubs/Integriq stand in when integriq is absent) and answered by a
 * listener the way integriq answers.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryEventDispatcher;
use OCA\Dossiq\Woo\WooSources;
use OCA\Integriq\Event\DocumentFetchRequestedEvent;
use OCA\Integriq\Event\DocumentSearchRequestedEvent;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Woo\WooSources
 */
class WooSourcesTest extends TestCase {

	private InMemoryEventDispatcher $dispatcher;

	private bool $integriqInstalled = true;

	protected function setUp(): void {
		$this->dispatcher = new InMemoryEventDispatcher();
		$this->integriqInstalled = true;
	}//end setUp()

	/**
	 * The service over the given integriq presence.
	 *
	 * @return WooSources The service.
	 */
	private function sources(): WooSources {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $app): bool => ($app === 'integriq' && $this->integriqInstalled));
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => ($app === 'integriq' && $this->integriqInstalled));

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === WooSources::CONNECTION_KEY) ? 'm365-zuiddrecht' : $default
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ['register' => 'dossiq', 'dossier_informatieobject_schema' => 'informatieobject'][$key] ?? $default
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new WooSources(
			appManager: $apps,
			dispatcher: $this->dispatcher,
			appConfig: $config,
			settingsService: $settings,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end sources()

	/**
	 * The source list as `id => source`.
	 *
	 * @return array<string, array<string, mixed>> The sources.
	 */
	private function byId(): array {
		return array_column($this->sources()->list(), null, 'id');
	}//end byId()

	public function testTheStubsAreTheShapeWooSourcesCalls(): void {
		self::assertTrue(class_exists(DocumentSearchRequestedEvent::class));
		self::assertTrue(class_exists(DocumentFetchRequestedEvent::class));
	}//end testTheStubsAreTheShapeWooSourcesCalls()

	/**
	 * REQ-WOO-012 "A source that is not connected": listed with the reason, the others still searchable.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqItsSourceIsListedAsNotConnectedWithTheReason(): void {
		$this->integriqInstalled = false;
		$sources = $this->byId();

		self::assertSame(['files', 'cases', 'microsoft365'], array_keys($sources));
		self::assertFalse($sources['microsoft365']['available']);
		self::assertSame('integriq-not-installed', $sources['microsoft365']['reason']);
		self::assertTrue($sources['files']['available']);
		self::assertTrue($sources['cases']['available']);
	}//end testWithoutIntegriqItsSourceIsListedAsNotConnectedWithTheReason()

	public function testWithIntegriqItsSourceIsOffered(): void {
		$sources = $this->byId();

		self::assertTrue($sources['microsoft365']['available']);
		self::assertSame('', $sources['microsoft365']['reason']);
		self::assertSame('integriq', $sources['microsoft365']['kind']);
	}//end testWithIntegriqItsSourceIsOffered()

	/**
	 * The platform sources tell the dialog which unified search provider and filters to call.
	 *
	 * @return void
	 */
	public function testThePlatformSourcesNameTheirUnifiedSearchProvider(): void {
		$sources = $this->byId();

		self::assertSame(['provider' => 'files', 'filters' => []], $sources['files']['search']);
		self::assertSame(
			['provider' => 'openregister_objects', 'filters' => ['register' => 'dossiq', 'schema' => 'informatieobject']],
			$sources['cases']['search']
		);
	}//end testThePlatformSourcesNameTheirUnifiedSearchProvider()

	/**
	 * Integriq's hits become rows, at most 50, with the rest counted.
	 *
	 * @return void
	 */
	public function testASearchMapsIntegriqHitsToRowsAndCountsTheRest(): void {
		$asked = null;
		$this->dispatcher->addListener(
			DocumentSearchRequestedEvent::class,
			static function (DocumentSearchRequestedEvent $event) use (&$asked): void {
				$asked = $event;
				$hits = [];
				for ($i = 1; $i <= 52; $i++) {
					$hits[] = ['remoteId' => 'driveItem:d:' . $i, 'title' => 'Stuk ' . $i, 'path' => 'Ruimte / Documenten', 'modifiedAt' => '2025-03-01T10:00:00Z', 'snippet' => 'over de Stationsweg', 'entityType' => 'driveItem'];
				}

				$event->setResult(['hits' => $hits, 'moreCount' => 7, 'notices' => ['delegated-grant-missing']]);
			}
		);

		$answer = $this->sources()->searchMicrosoft365(terms: 'Stationsweg', from: '2025-01-01', to: '2025-12-31', userId: 'pjansen');

		self::assertSame('', $answer['refusal']);
		self::assertCount(50, $answer['rows']);
		self::assertSame(9, $answer['remaining']);
		self::assertSame(['delegated-grant-missing'], $answer['notices']);
		self::assertSame(
			['key' => 'driveItem:d:1', 'name' => 'Stuk 1', 'location' => 'Ruimte / Documenten', 'date' => '2025-03-01T10:00:00Z', 'snippet' => 'over de Stationsweg', 'entityType' => 'driveItem'],
			$answer['rows'][0]
		);

		self::assertInstanceOf(DocumentSearchRequestedEvent::class, $asked);
		self::assertSame('dossiq', $asked->getSourceApp());
		self::assertSame('m365-zuiddrecht', $asked->getConnectionKey());
		self::assertSame('pjansen', $asked->getUserId());
		self::assertSame('Stationsweg', $asked->getTerms());
		self::assertSame('2025-01-01', $asked->getFrom());
		self::assertSame('2025-12-31', $asked->getTo());
		self::assertSame(50, $asked->getLimit());
	}//end testASearchMapsIntegriqHitsToRowsAndCountsTheRest()

	public function testASearchIntegriqDoesNotAnswerIsARefusal(): void {
		$answer = $this->sources()->searchMicrosoft365(terms: 'x', from: null, to: null, userId: 'pjansen');

		self::assertSame('integriq-did-not-answer', $answer['refusal']);
		self::assertSame([], $answer['rows']);
	}//end testASearchIntegriqDoesNotAnswerIsARefusal()

	public function testASearchWithoutIntegriqIsRefusedBeforeAnythingIsDispatched(): void {
		$this->integriqInstalled = false;
		$answer = $this->sources()->searchMicrosoft365(terms: 'x', from: null, to: null, userId: 'pjansen');

		self::assertSame('integriq-not-installed', $answer['refusal']);
		self::assertSame([], $this->dispatcher->dispatched);
	}//end testASearchWithoutIntegriqIsRefusedBeforeAnythingIsDispatched()

	public function testAFetchAnswersTheFileOrNull(): void {
		$this->dispatcher->addListener(
			DocumentFetchRequestedEvent::class,
			static function (DocumentFetchRequestedEvent $event): void {
				if ($event->getHandle() === 'driveItem:d:1' && $event->getUserId() === 'pjansen') {
					$event->setResult(['fileName' => '../raadsvoorstel.docx', 'mimeType' => 'application/msword', 'content' => 'voorstel']);
				}
			}
		);
		$sources = $this->sources();

		self::assertSame(
			['fileName' => 'raadsvoorstel.docx', 'mimeType' => 'application/msword', 'content' => 'voorstel'],
			$sources->fetchMicrosoft365(handle: 'driveItem:d:1', userId: 'pjansen')
		);
		self::assertNull($sources->fetchMicrosoft365(handle: 'driveItem:d:2', userId: 'pjansen'));
		self::assertNull($sources->fetchMicrosoft365(handle: '', userId: 'pjansen'));

		$this->integriqInstalled = false;
		self::assertNull($this->sources()->fetchMicrosoft365(handle: 'driveItem:d:1', userId: 'pjansen'));
	}//end testAFetchAnswersTheFileOrNull()

	public function testOnlyTheThreeSourcesAreKnown(): void {
		self::assertTrue(WooSources::isKnown('files'));
		self::assertTrue(WooSources::isKnown('microsoft365'));
		self::assertFalse(WooSources::isKnown('dropbox'));
	}//end testOnlyTheThreeSourcesAreKnown()
}//end class
