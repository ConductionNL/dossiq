<?php

/**
 * TermijnDefinitieController: listing, calendar facts and saving term definitions.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeZone;
use OCA\Dossiq\Controller\TermijnDefinitieController;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\ConfiguredRegistryServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermEndRollInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Definitions live in the configured schema, request bookkeeping is never saved,
 * a refused save is 422 and an unexpected failure 500 and logged.
 *
 * @covers \OCA\Dossiq\Controller\TermijnDefinitieController
 */
class TermijnDefinitieControllerTest extends TestCase {

	/**
	 * The registry.
	 *
	 * @var ConfiguredRegistryServiceInterface&MockObject
	 */
	private $registry;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Fresh registry and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registry = $this->createMock(ConfiguredRegistryServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller with the given request parameters, zone and roll answer.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $zone   The case time zone.
	 * @param bool                 $roll   Whether the calendar answers.
	 *
	 * @return TermijnDefinitieController
	 */
	private function controller(array $params=[], string $zone='Europe/Amsterdam', bool $roll=true): TermijnDefinitieController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$dates = $this->createMock(CaseDateNormaliserInterface::class);
		$dates->method('timeZone')->willReturn(new DateTimeZone($zone));
		$ends = $this->createMock(TermEndRollInterface::class);
		$ends->method('calendarAnswers')->willReturn($roll);

		return new TermijnDefinitieController('dossiq', $request, $this->registry, $this->logger, $dates, $ends);
	}//end controller()

	/**
	 * Both list paths read the term definition schema.
	 *
	 * @return void
	 */
	public function testTheListReadsTheDefinitionSchema(): void {
		$this->registry->expects($this->exactly(2))->method('list')
			->with('termijn_definitie_schema')->willReturn([['id' => 't-1']]);
		$controller = $this->controller();

		foreach ([$controller->index(), $controller->indexLegacyPath()] as $response) {
			$this->assertSame(Http::STATUS_OK, $response->getStatus());
			$this->assertSame([['id' => 't-1']], $response->getData());
		}
	}//end testTheListReadsTheDefinitionSchema()

	/**
	 * The calendar reports the zone name and whether ends can roll past closed days.
	 *
	 * @return void
	 */
	public function testTheCalendarReportsZoneAndRoll(): void {
		$this->assertSame(
			['timezone' => 'Europe/Amsterdam', 'rollAvailable' => true],
			$this->controller()->calendar()->getData()
		);
		$this->assertSame(
			['timezone' => 'UTC', 'rollAvailable' => false],
			$this->controller(zone: 'UTC', roll: false)->calendarLegacyPath()->getData()
		);
	}//end testTheCalendarReportsZoneAndRoll()

	/**
	 * Create saves without an id and answers 201; update saves under the id and answers 200.
	 *
	 * @return void
	 */
	public function testSavingDropsBookkeeping(): void {
		$params = ['name' => 'Bezwaar', 'days' => 42, '_route' => 'r', 'id' => 'x', 'uuid' => 'y', '@self' => []];
		$calls = [];
		$this->registry->method('save')->willReturnCallback(
			static function (string $key, array $data, ?string $id) use (&$calls): array {
				$calls[] = [$key, $data, $id];
				return ['id' => ($id ?? 'new')] + $data;
			}
		);
		$controller = $this->controller(params: $params);

		$created = $controller->create();
		$this->assertSame(Http::STATUS_CREATED, $created->getStatus());
		$this->assertSame(['id' => 'new', 'name' => 'Bezwaar', 'days' => 42], $created->getData());
		$this->assertSame(Http::STATUS_CREATED, $controller->createLegacyPath()->getStatus());

		$updated = $controller->update(id: 't-1');
		$this->assertSame(Http::STATUS_OK, $updated->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->updateLegacyPath(id: 't-1')->getStatus());

		$body = ['name' => 'Bezwaar', 'days' => 42];
		$this->assertSame(
			[
				['termijn_definitie_schema', $body, null],
				['termijn_definitie_schema', $body, null],
				['termijn_definitie_schema', $body, 't-1'],
				['termijn_definitie_schema', $body, 't-1'],
			],
			$calls
		);
	}//end testSavingDropsBookkeeping()

	/**
	 * A refused save is 422 with its message and nothing is logged.
	 *
	 * @return void
	 */
	public function testARefusedSaveIs422(): void {
		$this->registry->method('save')->willThrowException(new RuntimeException('Dagen moet positief zijn'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(params: ['days' => -1])->create();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Dagen moet positief zijn'], $response->getData());
	}//end testARefusedSaveIs422()

	/**
	 * Any other failure is 500 and logged for the app.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIs500AndLogged(): void {
		$this->registry->method('save')->willThrowException(new \LogicException('schema niet ingesteld'));
		$this->logger->expects($this->once())->method('error')
			->with('Failed to save TermijnDefinitie: schema niet ingesteld', ['app' => 'dossiq']);

		$response = $this->controller()->update(id: 't-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Failed to save term definition: schema niet ingesteld'], $response->getData());
	}//end testAnUnexpectedFailureIs500AndLogged()
}//end class
