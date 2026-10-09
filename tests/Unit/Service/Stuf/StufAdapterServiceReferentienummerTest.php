<?php

/**
 * creeerZaak() reports the referentienummer of the envelope it sent.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-orchestration
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\CircuitBreakerService;
use OCA\Dossiq\Service\Stuf\NeedsInputDispatcher;
use OCA\Dossiq\Service\Stuf\StufAdapterService;
use OCA\Dossiq\Service\Stuf\StufCaseMappingStore;
use OCA\Dossiq\Service\Stuf\StufMessageHandler;
use OCA\Dossiq\Service\Stuf\StufMessageParser;
use OCA\Dossiq\Service\Stuf\StufOutboundTransport;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Dossiq\Service\StufMessageBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The referentienummer is read from the envelope, logged with the outbound
 * message, and handed back to the caller, so a reply can be matched to it.
 */
class StufAdapterServiceReferentienummerTest extends TestCase {
	/**
	 * Run creeerZaak() on an envelope and return what it reports.
	 *
	 * @param string $envelope The envelope the builder produces.
	 * @param string $expectedLogged The referentienummer the audit log must receive.
	 *
	 * @return array The creeerZaak() result.
	 */
	private function createWith(string $envelope, string $expectedLogged): array {
		$builder = $this->createMock(StufMessageBuilder::class);
		$builder->method('buildLk01CreeerZaak')->willReturn($envelope);

		$messageHandler = $this->createMock(StufMessageHandler::class);
		$messageHandler->expects($this->once())
			->method('logOutbound')
			->with($this->anything(), $envelope, $expectedLogged)
			->willReturn(['id' => 'stuf-msg-1']);

		$transport = $this->createMock(StufOutboundTransport::class);
		$transport->method('dispatch')->willReturn(
			[
				'success' => true,
				'messageId' => 'stuf-msg-1',
				'caseIdentification' => null,
				'fout' => null,
			]
		);

		$circuitBreaker = $this->createMock(CircuitBreakerService::class);
		$circuitBreaker->method('checkEndpoint')->willReturn(true);

		$service = new StufAdapterService(
			builder: $builder,
			transport: $transport,
			messageHandler: $messageHandler,
			parser: $this->createMock(StufMessageParser::class),
			circuitBreaker: $circuitBreaker,
			register: $this->createMock(StufRegisterAccess::class),
			mappings: $this->createMock(StufCaseMappingStore::class),
			needsInput: $this->createMock(NeedsInputDispatcher::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		return $service->creeerZaak(case: ['id' => 'case-1'], endpoint: ['id' => 'ep-1']);
	}//end createWith()

	/**
	 * The number in the stuurgegevens is the one reported and logged.
	 *
	 * @return void
	 */
	public function testTheEnvelopesReferentienummerIsReportedAndLogged(): void {
		$envelope = '<ZAK:zakLk01><ZAK:stuurgegevens>'
			. '<stuf:referentienummer>DQ-2026-0042</stuf:referentienummer>'
			. '</ZAK:stuurgegevens></ZAK:zakLk01>';

		$result = $this->createWith(envelope: $envelope, expectedLogged: 'DQ-2026-0042');

		$this->assertSame('DQ-2026-0042', $result['referentienummer']);
		$this->assertTrue($result['success']);
	}//end testTheEnvelopesReferentienummerIsReportedAndLogged()

	/**
	 * An envelope without one reports an empty number rather than failing.
	 *
	 * @return void
	 */
	public function testAnEnvelopeWithoutAReferentienummerReportsAnEmptyOne(): void {
		$result = $this->createWith(envelope: '<ZAK:zakLk01/>', expectedLogged: '');

		$this->assertSame('', $result['referentienummer']);
	}//end testAnEnvelopeWithoutAReferentienummerReportsAnEmptyOne()
}//end class
