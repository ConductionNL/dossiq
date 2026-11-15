<?php

/**
 * A Woo request from the portal takes the answers a resident gives
 *
 * Drives the portal route itself (PortalWooRequestController) with the real
 * WooRequestIntake behind it, the way portaliq's forward reaches it. A day
 * written day first is kept as `jjjj-mm-dd`, the requester's Dutch word is
 * kept as its value, and a refusal names its field in Dutch, worded from the
 * shipped catalogue.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-request-intake/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Controller\PortalWooRequestController;
use OCA\Dossiq\Portal\PortalAssertionVerifier;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Tests\Unit\Portal\PortalAssertionVerifierTest;
use OCA\Dossiq\Woo\WooRequestIntake;
use OCA\Dossiq\Service\Intake\WrittenCase;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The portal route on a store, as a resident fills in the form.
 *
 * @spec openspec/specs/woo-request-intake/spec.md
 */
class PortalWooRequestDutchAnswersTest extends TestCase {

	/**
	 * The store the intake writes the case into.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * Seed the Woo case type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'caseType',
			uuid: WooRequestIntake::CASE_TYPE_ID,
			row: ['title' => 'Woo-verzoek', 'identifier' => 'woo-verzoek', 'initialStatus' => 'status-ontvangst']
		);
	}//end setUp()

	/**
	 * The route with the real intake behind it.
	 *
	 * @param array<string, mixed> $body     What portaliq forwards.
	 * @param string               $language The forwarded Accept-Language.
	 *
	 * @return PortalWooRequestController
	 */
	private function route(array $body, string $language = ''): PortalWooRequestController {
		$assertion = PortalAssertionVerifierTest::mint();
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => [PortalAssertionVerifier::HEADER => $assertion, 'Accept-Language' => $language][$name] ?? ''
		);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($body[$key] ?? $default));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'property_definition_schema' => 'propertyDefinition',
			][$key] ?? $default
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://gemeente.test' . $path);

		return new PortalWooRequestController(
			appName: 'dossiq',
			request: $request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: 'a-dedicated-portaliq-secret-0123'),
			intake: new WooRequestIntake(settingsService: $settings, urlGenerator: $urls, logger: $this->createMock(LoggerInterface::class)),
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
			l10n: $this->catalogue(),
		);
	}//end route()

	/**
	 * A translator factory that reads the shipped catalogues, so a sentence
	 * with no Dutch entry stays English here as it would on an instance.
	 *
	 * @return IFactory
	 */
	private function catalogue(): IFactory {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, ?string $language = null): IL10N {
				$file = __DIR__ . '/../../../l10n/' . ($language ?? 'en') . '.json';
				$entries = (json_decode((string)file_get_contents($file), true)['translations'] ?? []);
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(static fn (string $text): string => (string)($entries[$text] ?? $text));
				return $l10n;
			}
		);

		return $factory;
	}//end catalogue()

	/**
	 * The one case the store holds.
	 *
	 * @return array<string, mixed>
	 */
	private function writtenCase(): array {
		$cases = array_values($this->store->rows['case'] ?? []);
		self::assertCount(1, $cases, 'one case was opened');
		return $cases[0];
	}//end writtenCase()

	/**
	 * A day written day first, as a resident writes it, opens the case and
	 * is kept as the date the schema declares.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-reads-the-answers-a-resident-gives-req-wdi-001
	 */
	public function testADayWrittenDayFirstIsKeptAsADate(): void {
		$response = $this->route(
			['onderwerp' => 'Verlichting fietspad', 'periodeVan' => '01-01-2026', 'periodeTot' => '13/10/2026']
		)->start();

		self::assertSame(201, $response->getStatus(), json_encode($response->getData()));
		$wooRequest = $this->writtenCase()['wooRequest'];
		self::assertSame('2026-01-01', $wooRequest['periodeVan']);
		self::assertSame('2026-10-13', $wooRequest['periodeTot']);
	}//end testADayWrittenDayFirstIsKeptAsADate()

	/**
	 * The word a resident reads for the requester kind is kept as its value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-woo-request-reads-the-answers-a-resident-gives-req-wdi-001
	 */
	public function testTheRequestersDutchWordIsKeptAsItsValue(): void {
		$response = $this->route(['onderwerp' => 'Verlichting fietspad', 'verzoekerType' => 'Inwoner'])->start();

		self::assertSame(201, $response->getStatus(), json_encode($response->getData()));
		self::assertSame('burger', $this->writtenCase()['wooRequest']['verzoekerType']);
	}//end testTheRequestersDutchWordIsKeptAsItsValue()

	/**
	 * A day that does not exist is refused on its field, in Dutch, and
	 * nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-refused-woo-request-names-the-field-in-the-residents-language-req-wdi-002
	 */
	public function testAnImpossibleDayIsRefusedOnItsFieldInDutch(): void {
		$response = $this->route(['onderwerp' => 'Verlichting fietspad', 'periodeVan' => '31-02-2026'])->start();

		self::assertSame(400, $response->getStatus());
		$data = $response->getData();
		self::assertSame('invalid', $data['error']);
		self::assertSame(
			['periodeVan' => 'Schrijf de datum als dag-maand-jaar, bijvoorbeeld 1-3-2026.'],
			$data['errors']
		);
		self::assertArrayNotHasKey('case', $this->store->rows, 'nothing was written');
	}//end testAnImpossibleDayIsRefusedOnItsFieldInDutch()

	/**
	 * Every refusal of the form is about a field and reads in Dutch.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-refused-woo-request-names-the-field-in-the-residents-language-req-wdi-002
	 */
	public function testEveryRefusalNamesItsFieldInDutch(): void {
		$cases = [
			'onderwerp' => ['onderwerp' => ''],
			'periodeTot' => ['onderwerp' => 'x', 'periodeVan' => '2026-10-13', 'periodeTot' => '01-01-2026'],
			'verzoekerType' => ['onderwerp' => 'x', 'verzoekerType' => 'student'],
			'verzoekerEmail' => ['onderwerp' => 'x', 'verzoekerEmail' => 'geen adres'],
			'documentSoorten' => ['onderwerp' => 'x', 'documentSoorten' => ['notulen']],
		];
		$english = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/en.json'), true)['translations'];
		foreach ($cases as $field => $body) {
			$data = $this->route($body)->start()->getData();

			self::assertSame([$field], array_keys($data['errors'] ?? []), $field . ': the refusal names its field');
			$sentence = $data['errors'][$field];
			self::assertArrayNotHasKey($sentence, $english, $field . ': "' . $sentence . '" is the Dutch sentence, not the English key');
			self::assertSame($sentence, $data['message']);
		}
	}//end testEveryRefusalNamesItsFieldInDutch()

	/**
	 * A forward that asks for English gets the English sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-a-refused-woo-request-names-the-field-in-the-residents-language-req-wdi-002
	 */
	public function testAnEnglishReaderGetsTheEnglishSentence(): void {
		$data = $this->route(['onderwerp' => ''], 'en-GB,en;q=0.8')->start()->getData();

		self::assertSame(['onderwerp' => 'Say what the request is about.'], $data['errors']);
	}//end testAnEnglishReaderGetsTheEnglishSentence()

	/**
	 * The confirmation's dates are days a resident reads, beside the stored
	 * values, which stay.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-confirmation-names-its-dates-as-days-req-wdi-003
	 */
	public function testTheConfirmationNamesItsDatesAsDays(): void {
		$answer = (new WrittenCase())->read(
			saved: [
				'id' => 'case-1',
				'identifier' => '2026-0101',
				'termStartsAt' => '2026-10-14T12:34:29+00:00',
				'deadline' => '2026-11-11',
			]
		)['answer'];

		self::assertSame('14 oktober 2026', $answer['termStartsDay']);
		self::assertSame('11 november 2026', $answer['deadlineDay']);
		self::assertSame('2026-10-14T12:34:29+00:00', $answer['termStartsAt'], 'the stored value stays');
		self::assertSame('2026-11-11', $answer['deadline'], 'the stored value stays');
		self::assertArrayNotHasKey('receivedDay', $answer, 'no day without a value');
	}//end testTheConfirmationNamesItsDatesAsDays()
}//end class
