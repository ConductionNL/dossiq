<?php

/**
 * ZgwService inbound and outbound mapping golden tests.
 *
 * Pins what each mapping hands the mapping engine (the marker fields it adds,
 * in order, and the route fields it drops), what it does to the engine's
 * answer (empty nullable fields), and that a zaak goes through the
 * communicatiekanaal translation both ways.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CommunicationChannel;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for ZgwService::applyOutboundMapping() and applyInboundMapping().
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 */
class ZgwMappingStepsGoldenTest extends TestCase {

	/**
	 * What the doubles saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The service over an engine answering $answer and a channel translation.
	 *
	 * @param array<string, mixed> $answer What the mapping engine answers.
	 *
	 * @return ZgwService
	 */
	private function service(array $answer): ZgwService {
		$engine = new class ($this->calls, $answer) {
			public function __construct(private array &$calls, private array $answer) {
			}

			public function executeMapping(object $mapping, array $input): array {
				$this->calls[] = ['executeMapping', $mapping->name, $input];
				return $this->answer;
			}
		};
		$channels = $this->createMock(CommunicationChannel::class);
		$channels->method('outbound')->willReturnCallback(
			function (array $case, array $mapped): array {
				$this->calls[] = ['outbound channel', $case, $mapped];
				return $mapped + ['communicatiekanaal' => 'url'];
			}
		);
		$channels->method('inbound')->willReturnCallback(
			function (array $body, array $mapped): array {
				$this->calls[] = ['inbound channel', $body, $mapped];
				return $mapped + ['communicationChannel' => 'slug'];
			}
		);

		$service = (new \ReflectionClass(ZgwService::class))->newInstanceWithoutConstructor();
		\Closure::bind(
			static function () use ($service, $engine, $channels): void {
				$service->mappingService = $engine;
				$service->channels = $channels;
			},
			null,
			ZgwService::class
		)();

		return $service;
	}//end service()

	/**
	 * An informatieobject gets its markers and download URL, and empty nullable fields become null.
	 *
	 * @return void
	 */
	public function testAnOutboundInformatieobject(): void {
		$mapped = $this->service(['titel' => '', 'auteur' => 'Ann', 'link' => ''])->applyOutboundMapping(
			['id' => 'd-1', 'title' => 'Nota', '@self' => ['created' => 'c', 'updated' => 'u']],
			(object)['name' => 'out'],
			['zgwResource' => 'enkelvoudiginformatieobject', 'valueMapping' => ['a' => 'b'], 'nullableFields' => ['titel', 'missing']],
			'https://zgw.test/eio'
		);

		$this->assertSame(['titel' => null, 'auteur' => 'Ann', 'link' => ''], $mapped);
		$this->assertSame(
			[[
				'executeMapping',
				'out',
				[
					'id' => 'd-1',
					'title' => 'Nota',
					'@self' => ['created' => 'c', 'updated' => 'u'],
					'_baseUrl' => 'https://zgw.test/eio',
					'_valueMappings' => ['a' => 'b'],
					'_uuid' => 'd-1',
					'_created' => 'c',
					'_updated' => 'u',
					'_downloadUrl' => 'https://zgw.test/eio/d-1/download',
				],
			]],
			$this->calls
		);
	}//end testAnOutboundInformatieobject()

	/**
	 * A zaak takes its uuid from @self, gets no download URL, and goes through the channel translation.
	 *
	 * @return void
	 */
	public function testAnOutboundZaak(): void {
		$mapped = $this->service(['identificatie' => 'Z-1'])->applyOutboundMapping(
			['@self' => ['id' => 's-1']],
			(object)['name' => 'out'],
			['zgwResource' => 'zaak'],
			'https://zgw.test/zaken'
		);

		$this->assertSame(['identificatie' => 'Z-1', 'communicatiekanaal' => 'url'], $mapped);
		$input = ['@self' => ['id' => 's-1'], '_baseUrl' => 'https://zgw.test/zaken', '_valueMappings' => [], '_uuid' => 's-1', '_created' => '', '_updated' => ''];
		$this->assertSame([['executeMapping', 'out', $input], ['outbound channel', $input, ['identificatie' => 'Z-1']]], $this->calls);
	}//end testAnOutboundZaak()

	/**
	 * An informatieobject without any id gets no download URL.
	 *
	 * @return void
	 */
	public function testAnOutboundObjectWithoutAnId(): void {
		$this->service([])->applyOutboundMapping([], (object)['name' => 'out'], ['zgwResource' => 'enkelvoudiginformatieobject'], 'b');

		$this->assertArrayNotHasKey('_downloadUrl', $this->calls[0][2]);
		$this->assertSame('', $this->calls[0][2]['_uuid']);
	}//end testAnOutboundObjectWithoutAnId()

	/**
	 * Inbound drops the route fields, adds the value mappings, and unsets the default nullables that came back empty.
	 *
	 * @return void
	 */
	public function testAnInboundBodyWithTheDefaultNullables(): void {
		$mapped = $this->service(['title' => 'Nota', 'endDate' => '', 'deadline' => null, 'archiveStatus' => '', 'other' => ''])->applyInboundMapping(
			['titel' => 'Nota', '_route' => 'r', 'zgwApi' => 'zaken', 'resource' => 'zaken', 'uuid' => 'u-1'],
			(object)['name' => 'in'],
			['valueMapping' => ['x' => 'y']]
		);

		$this->assertSame(['title' => 'Nota', 'deadline' => null, 'other' => ''], $mapped);
		$this->assertSame([['executeMapping', 'in', ['titel' => 'Nota', '_valueMappings' => ['x' => 'y']]]], $this->calls);
	}//end testAnInboundBodyWithTheDefaultNullables()

	/**
	 * A zaak's own nullables replace the defaults, and the zaak goes through the channel translation.
	 *
	 * @return void
	 */
	public function testAnInboundZaakWithItsOwnNullables(): void {
		$mapped = $this->service(['endDate' => '', 'note' => ''])->applyInboundMapping(
			['omschrijving' => 'x'],
			(object)['name' => 'in'],
			['zgwResource' => 'zaak', 'inboundNullable' => ['note']]
		);

		$this->assertSame(['endDate' => '', 'communicationChannel' => 'slug'], $mapped);
		$this->assertSame(['inbound channel', ['omschrijving' => 'x', '_valueMappings' => []], ['endDate' => '']], $this->calls[1]);
	}//end testAnInboundZaakWithItsOwnNullables()
}//end class
