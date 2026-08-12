<?php

/**
 * AiService::detectDeterministicPiiSpans() Unit Tests.
 *
 * Pure-logic tests for the deterministic regex "rules floor"
 * (woo-llm-anonymisation) — no I/O, no container/appConfig interaction
 * beyond trivial construction.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Ai\AiAuditLog;
use OCA\Procest\Service\Ai\AiEndpointGuard;
use OCA\Procest\Service\Ai\AiModelIdentity;
use OCA\Procest\Service\Ai\AiPiiRedactor;
use OCA\Procest\Service\Ai\AiPromptFactory;
use OCA\Procest\Service\AiService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\AiService::detectDeterministicPiiSpans
 *
 * @uses \OCA\Procest\Service\Ai\AiAuditLog
 * @uses \OCA\Procest\Service\Ai\AiEndpointGuard
 * @uses \OCA\Procest\Service\Ai\AiModelIdentity
 * @uses \OCA\Procest\Service\Ai\AiPiiRedactor
 * @uses \OCA\Procest\Service\AiService
 */
class AiServicePiiDetectionTest extends TestCase {
	/**
	 * Build a bare AiService (no outbound calls needed for this method).
	 *
	 * @return AiService
	 */
	private function service(): AiService {
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		// Real AiPiiRedactor, not a mock: this suite asserts the actual pattern
		// set and offsets, so a stubbed redactor would assert nothing.
		return new AiService(
			appConfig: $appConfig,
			prompts: new AiPromptFactory(),
			pii: new AiPiiRedactor(),
			endpointGuard: new AiEndpointGuard($logger),
			audit: new AiAuditLog($appConfig, $this->createMock(ContainerInterface::class), $logger),
			modelIdentity: new AiModelIdentity($appConfig),
			logger: $logger,
		);
	}//end service()

	/**
	 * A BSN-shaped 9-digit number is detected with the correct offsets.
	 *
	 * @return void
	 */
	public function testDetectsBsn(): void {
		$text = 'Klant BSN: 123456782 heeft een aanvraag ingediend.';
		$spans = $this->service()->detectDeterministicPiiSpans($text);

		$this->assertCount(1, $spans);
		$this->assertSame('bsn', $spans[0]['category']);
		$this->assertSame('123456782', $spans[0]['text']);
		$this->assertSame(
			'123456782',
			substr($text, $spans[0]['start'], ($spans[0]['end'] - $spans[0]['start']))
		);
	}//end testDetectsBsn()

	/**
	 * A Dutch mobile phone number is detected.
	 *
	 * @return void
	 */
	public function testDetectsPhoneNumber(): void {
		$spans = $this->service()->detectDeterministicPiiSpans('Bel mij op 0612345678 voor meer info.');

		$this->assertCount(1, $spans);
		$this->assertSame('phone', $spans[0]['category']);
	}//end testDetectsPhoneNumber()

	/**
	 * A Dutch postcode is detected.
	 *
	 * @return void
	 */
	public function testDetectsPostcode(): void {
		$spans = $this->service()->detectDeterministicPiiSpans('Adres: Kerkstraat 1, 1234 AB Amsterdam.');

		$this->assertCount(1, $spans);
		$this->assertSame('postcode', $spans[0]['category']);
		$this->assertSame('1234 AB', $spans[0]['text']);
	}//end testDetectsPostcode()

	/**
	 * An IBAN is detected.
	 *
	 * @return void
	 */
	public function testDetectsIban(): void {
		$spans = $this->service()->detectDeterministicPiiSpans('Rekeningnummer: NL91ABNA0417164300.');

		$this->assertCount(1, $spans);
		$this->assertSame('iban', $spans[0]['category']);
	}//end testDetectsIban()

	/**
	 * Multiple matches across categories are all returned, sorted by start offset.
	 *
	 * @return void
	 */
	public function testMultipleSpansAreSortedByStart(): void {
		$text = 'BSN 123456782, bel 0612345678, postcode 1234 AB.';
		$spans = $this->service()->detectDeterministicPiiSpans($text);

		$this->assertGreaterThanOrEqual(3, count($spans));
		for ($i = 1; $i < count($spans); $i++) {
			$this->assertLessThanOrEqual($spans[$i]['start'], $spans[$i - 1]['start']);
		}
	}//end testMultipleSpansAreSortedByStart()

	/**
	 * Text with no PII returns an empty array.
	 *
	 * @return void
	 */
	public function testCleanTextReturnsNoSpans(): void {
		$spans = $this->service()->detectDeterministicPiiSpans('Dit is een algemene tekst zonder persoonsgegevens.');

		$this->assertSame([], $spans);
	}//end testCleanTextReturnsNoSpans()

	/**
	 * Empty text returns an empty array without error.
	 *
	 * @return void
	 */
	public function testEmptyTextReturnsNoSpans(): void {
		$this->assertSame([], $this->service()->detectDeterministicPiiSpans(''));
	}//end testEmptyTextReturnsNoSpans()
}//end class
