<?php

/**
 * ZgwService maps the zaak's communicatiekanaal at its own boundary.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Asserted from the caller: the mapping class is only worth something if
 * ZgwService runs it on a zaak and leaves other resources alone.
 *
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */
class ZgwCommunicationChannelBoundaryTest extends TestCase {

	/**
	 * A ZgwService whose OpenRegister mapping returns $mapped verbatim.
	 *
	 * @param array<string, mixed> $mapped What the stored mapping produces.
	 *
	 * @return ZgwService
	 */
	private function service(array $mapped): ZgwService {
		$reflection = new ReflectionClass(ZgwService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('mappingService');
		$property->setValue(
			$service,
			new class($mapped) {
				/**
				 * @param array<string, mixed> $mapped The output.
				 */
				public function __construct(private array $mapped) {
				}

				/**
				 * @param mixed $mapping The mapping.
				 * @param array $input   The input.
				 *
				 * @return array<string, mixed>
				 */
				public function executeMapping(mixed $mapping, array $input): array {
					return $this->mapped;
				}
			}
		);

		return $service;
	}//end service()

	/**
	 * A zaak created with a channel URL is written with a slug, and the URL is kept.
	 *
	 * @return void
	 */
	public function testAZaakWithAChannelUrlIsWrittenWithASlug(): void {
		$url = 'https://referentielijsten.example/api/v1/communicatiekanalen/1';
		$mapped = $this->service(mapped: ['title' => 'Kapvergunning'])->applyInboundMapping(
			body: ['omschrijving' => 'Kapvergunning', 'communicatiekanaal' => $url],
			mapping: new \stdClass(),
			mappingConfig: ['zgwResource' => 'zaak']
		);

		$this->assertSame('zgw-api', $mapped['communicationChannel']);
		$this->assertSame($url, $mapped['communicationChannelSource']);
	}//end testAZaakWithAChannelUrlIsWrittenWithASlug()

	/**
	 * Read back, the zaak answers with the URL it was created with; another
	 * resource is not touched.
	 *
	 * @return void
	 */
	public function testAZaakAnswersWithItsUrlAndOtherResourcesAreLeftAlone(): void {
		$url = 'https://referentielijsten.example/api/v1/communicatiekanalen/1';
		$zaak = $this->service(mapped: ['url' => 'u'])->applyOutboundMapping(
			objectData: ['communicationChannel' => 'zgw-api', 'communicationChannelSource' => $url],
			mapping: new \stdClass(),
			mappingConfig: ['zgwResource' => 'zaak'],
			baseUrl: 'https://nc.example/zaken'
		);
		$this->assertSame($url, $zaak['communicatiekanaal']);

		$status = $this->service(mapped: ['url' => 'u'])->applyOutboundMapping(
			objectData: ['communicationChannel' => 'zgw-api'],
			mapping: new \stdClass(),
			mappingConfig: ['zgwResource' => 'status'],
			baseUrl: 'https://nc.example/zaken'
		);
		$this->assertArrayNotHasKey('communicatiekanaal', $status);
	}//end testAZaakAnswersWithItsUrlAndOtherResourcesAreLeftAlone()
}//end class
