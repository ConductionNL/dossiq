<?php

/**
 * The old StUF provider URLs forward to integriq for one release (decision 199).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\StufController;
use OCA\Dossiq\Service\IntegrationStatusService;
use OCA\Dossiq\Service\Stuf\StufIntegriqBridge;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\StufController
 */
class StufControllerProviderForwardTest extends TestCase {

	/**
	 * What the bridge was handed: [rawXml, sourceId, service] per call.
	 *
	 * @var array<int, array{0:string,1:string,2:string}>
	 */
	private array $forwarded = [];

	/**
	 * All four URLs forward the raw envelope with the endpoint's integriq source and the right service.
	 *
	 * The service is asserted per URL: the wrong one would still forward, still
	 * answer, and send case traffic to the person route.
	 *
	 * @return void
	 */
	public function testEachUrlForwardsWithItsServiceAndTheEndpointsSource(): void {
		$controller = $this->controller(answer: ['statusCode' => 200, 'body' => '<zakLa01/>']);

		$response = $controller->cases();
		$controller->casesLegacyPath();
		$controller->persons();
		$controller->personsLegacyPath();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('<zakLa01/>', $response->render());
		$this->assertSame(['cases', 'cases', 'persons', 'persons'], array_column($this->forwarded, 2));
		$this->assertSame(['src-9'], array_values(array_unique(array_column($this->forwarded, 1))));
		$this->assertSame(self::envelope(application: 'Key2Zaken'), $this->forwarded[0][0], 'The envelope is forwarded unchanged');
	}//end testEachUrlForwardsWithItsServiceAndTheEndpointsSource()

	/**
	 * A sender no endpoint names is forwarded without a source, so integriq refuses it.
	 *
	 * @return void
	 */
	public function testAnUnknownSenderIsForwardedWithoutASource(): void {
		$controller = $this->controller(answer: ['statusCode' => 401, 'body' => '<Fo02/>'], application: 'Stranger');

		$response = $controller->cases();

		$this->assertSame('', $this->forwarded[0][1]);
		$this->assertSame(401, $response->getStatus());
		$this->assertSame('<Fo02/>', $response->render());
	}//end testAnUnknownSenderIsForwardedWithoutASource()

	/**
	 * Integriq's fault statuses pass through; an unknown one reads as a bad gateway.
	 *
	 * @return void
	 */
	public function testIntegriqsStatusIsPassedOn(): void {
		foreach ([400 => 400, 413 => 413, 500 => 500, 302 => 502] as $given => $expected) {
			$response = $this->controller(answer: ['statusCode' => $given, 'body' => '<Fo02/>'])->persons();
			$this->assertSame($expected, $response->getStatus());
		}
	}//end testIntegriqsStatusIsPassedOn()

	/**
	 * Integriq not answering is a 503, never an empty StUF answer.
	 *
	 * @return void
	 */
	public function testIntegriqAwayIsA503(): void {
		$response = $this->controller(answer: null)->cases();

		$this->assertSame(503, $response->getStatus());
		$this->assertStringNotContainsString('La01', $response->render());
	}//end testIntegriqAwayIsA503()

	/**
	 * Build the controller over a bridge double and one configured endpoint.
	 *
	 * @param array{statusCode:int,body:string}|null $answer      Integriq's answer, null for none.
	 * @param string                                 $application The envelope's zender applicatie.
	 *
	 * @return StufController The controller, reading a fixed body.
	 */
	private function controller(?array $answer, string $application='Key2Zaken'): StufController {
		$this->forwarded = [];
		$bridge = $this->createMock(StufIntegriqBridge::class);
		$bridge->method('forwardProviderRequest')->willReturnCallback(
			function (string $rawXml, string $sourceId, string $service) use ($answer): ?array {
				$this->forwarded[] = [$rawXml, $sourceId, $service];
				return $answer;
			}
		);

		$register = $this->createMock(StufRegisterAccess::class);
		$register->method('findOne')->willReturnCallback(
			static function (string $schema, array $filters): ?array {
				if ($schema === StufRegisterAccess::SCHEMA_ENDPOINT && $filters === ['recipientApplication' => 'Key2Zaken']) {
					return ['id' => 'ep-1', 'recipientApplication' => 'Key2Zaken', 'integriqSource' => 'src-9'];
				}

				return null;
			}
		);

		$body = self::envelope(application: $application);
		return new class('dossiq', $this->createMock(IRequest::class), $register, $bridge, $this->createMock(IL10N::class), $this->createMock(LoggerInterface::class), $this->createMock(IntegrationStatusService::class), $body) extends StufController {
			/**
			 * Constructor.
			 *
			 * @param string                   $appName  The app.
			 * @param IRequest                 $request  The request.
			 * @param StufRegisterAccess       $register The register.
			 * @param StufIntegriqBridge       $bridge   The bridge.
			 * @param IL10N                    $l10n     L10n.
			 * @param LoggerInterface          $logger   Logger.
			 * @param IntegrationStatusService $status   Status.
			 * @param string                   $body     The fixed request body.
			 */
			public function __construct(string $appName, IRequest $request, StufRegisterAccess $register, StufIntegriqBridge $bridge, IL10N $l10n, LoggerInterface $logger, IntegrationStatusService $status, private readonly string $body) {
				parent::__construct($appName, $request, $register, $bridge, $l10n, $logger, $status);
			}

			/**
			 * Serve a fixed body instead of php://input.
			 *
			 * @return string
			 */
			protected function readRawBody(): string {
				return $this->body;
			}
		};
	}//end controller()

	/**
	 * A zakLv01 envelope from one sending application, with the prefixes a real sender uses.
	 *
	 * @param string $application The zender applicatie.
	 *
	 * @return string The envelope.
	 */
	private static function envelope(string $application): string {
		return '<soapenv:Envelope><soapenv:Body><ZKN:zakLv01><ZKN:stuurgegevens><StUF:zender><StUF:organisatie>0363</StUF:organisatie>'
			. '<StUF:applicatie>' . $application . '</StUF:applicatie></StUF:zender>'
			. '<StUF:ontvanger><StUF:applicatie>Dossiq</StUF:applicatie></StUF:ontvanger></ZKN:stuurgegevens></ZKN:zakLv01></soapenv:Body></soapenv:Envelope>';
	}//end envelope()
}//end class
