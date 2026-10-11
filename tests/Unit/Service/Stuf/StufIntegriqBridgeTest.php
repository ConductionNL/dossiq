<?php

/**
 * Unit tests for StufIntegriqBridge.
 *
 * Integriq's events are the verbatim stubs in tests/Stubs/Integriq; the
 * dispatcher is a double that plays integriq's listener.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
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

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\StufException;
use OCA\Dossiq\Service\Stuf\StufIntegriqBridge;
use OCA\Integriq\Event\StufZdsCaseRequestedEvent;
use OCA\Integriq\Event\StufZdsConfirmationReceivedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Stuf\StufIntegriqBridge
 */
class StufIntegriqBridgeTest extends TestCase {

	/**
	 * Dispatched events.
	 *
	 * @var object[]
	 */
	private array $events = [];

	/**
	 * A vrij bericht goes to integriq as a vrijBericht request on the endpoint's integriq source.
	 *
	 * @return void
	 */
	public function testAVrijBerichtIsAskedOfIntegriq(): void {
		$result = ['success' => true, 'retrying' => false, 'referentienummer' => 'R1', 'messageId' => 'm1', 'caseIdentification' => null, 'fout' => null];
		$bridge = $this->bridge(
			static function (object $event) use ($result): void {
				if ($event instanceof StufZdsCaseRequestedEvent) {
					$event->setResult(result: $result);
				}
			}
		);

		$answer = $bridge->vrijBericht(endpoint: ['id' => 'ep-1', 'integriqSource' => 'src-9'], name: 'meldBesluit', payload: ['besluit' => 'B-1']);

		$this->assertSame($result, $answer);
		$event = $this->events[0];
		$this->assertInstanceOf(StufZdsCaseRequestedEvent::class, $event);
		$this->assertSame('dossiq', $event->getSourceApp());
		$this->assertSame('src-9', $event->getSourceId());
		$this->assertSame('vrijBericht', $event->getOperation());
		$this->assertSame(['name' => 'meldBesluit', 'payload' => ['besluit' => 'B-1']], $event->getArguments());
		$this->assertStringStartsWith('ep-1:', $event->getCorrelationId());
	}//end testAVrijBerichtIsAskedOfIntegriq()

	/**
	 * Integriq's refusal is a refusal here, with its reason.
	 *
	 * @return void
	 */
	public function testIntegriqsRefusalIsRaised(): void {
		$bridge = $this->bridge(
			static function (object $event): void {
				$event->refuse(reason: 'vrijBericht "x" is not registered');
			}
		);

		$this->expectException(StufException::class);
		$this->expectExceptionMessage('not registered');
		$bridge->vrijBericht(endpoint: ['id' => 'ep-1', 'integriqSource' => 'src-9'], name: 'x', payload: []);
	}//end testIntegriqsRefusalIsRaised()

	/**
	 * An endpoint without an integriq source sends nothing.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutIntegriqSourceSendsNothing(): void {
		$bridge = $this->bridge(static fn (): null => null);

		try {
			$bridge->vrijBericht(endpoint: ['id' => 'ep-1'], name: 'x', payload: []);
			$this->fail('An endpoint without integriqSource must be refused');
		} catch (StufException $exception) {
			$this->assertSame(400, $exception->getCode());
		}

		$this->assertSame([], $this->events);
	}//end testAnEndpointWithoutIntegriqSourceSendsNothing()

	/**
	 * Nobody answering (integriq disabled) is a 503 refusal, never a silent success.
	 *
	 * @return void
	 */
	public function testNobodyAnsweringIsUnavailable(): void {
		$bridge = $this->bridge(static fn (): null => null);

		try {
			$bridge->vrijBericht(endpoint: ['id' => 'ep-1', 'integriqSource' => 'src-9'], name: 'x', payload: []);
			$this->fail('An unanswered request must be refused');
		} catch (StufException $exception) {
			$this->assertSame(503, $exception->getCode());
		}
	}//end testNobodyAnsweringIsUnavailable()

	/**
	 * A confirmation is forwarded unchanged and integriq's answer comes back.
	 *
	 * @return void
	 */
	public function testAConfirmationIsForwardedAndAnswered(): void {
		$bridge = $this->bridge(
			static function (object $event): void {
				if ($event instanceof StufZdsConfirmationReceivedEvent) {
					$event->answer(statusCode: 200, body: '<Bv03/>');
				}
			}
		);

		$answer = $bridge->forwardConfirmation(rawXml: '<Bv01/>');

		$this->assertSame(['statusCode' => 200, 'body' => '<Bv03/>'], $answer);
		$this->assertSame('<Bv01/>', $this->events[0]->getRawXml());
		$this->assertSame('dossiq', $this->events[0]->getSourceApp());
	}//end testAConfirmationIsForwardedAndAnswered()

	/**
	 * An unanswered confirmation reads as null, so the caller can tell the sender to retry.
	 *
	 * @return void
	 */
	public function testAnUnansweredConfirmationIsNull(): void {
		$this->assertNull($this->bridge(static fn (): null => null)->forwardConfirmation(rawXml: '<Bv01/>'));
	}//end testAnUnansweredConfirmationIsNull()

	/**
	 * Build the bridge over a dispatcher that plays integriq.
	 *
	 * @param callable $integriq Handles each dispatched event.
	 *
	 * @return StufIntegriqBridge The bridge.
	 */
	private function bridge(callable $integriq): StufIntegriqBridge {
		$this->events = [];
		$dispatcher   = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($integriq): void {
				$this->events[] = $event;
				$integriq($event);
			}
		);

		return new StufIntegriqBridge($dispatcher, $this->createMock(LoggerInterface::class));
	}//end bridge()
}//end class
