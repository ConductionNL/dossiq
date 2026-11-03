<?php

/**
 * A ZGW create cannot be pointed at a stored object through `_directFields`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\NotificatieService;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwDocumentService;
use OCA\Dossiq\Service\ZgwJwtValidator;
use OCA\Dossiq\Service\ZgwMappingService;
use OCA\Dossiq\Service\ZgwPaginationHelper;
use OCA\Dossiq\Service\ZgwService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

/**
 * `_directFields` reach the stored payload unmapped. A create whose direct
 * fields name `id`, `uuid` or `@self` is written without them, so it cannot
 * become an update of the object they name.
 *
 * @covers \OCA\Dossiq\Service\ZgwService
 */
class ZgwCreateDropsCallerIdentityTest extends TestCase {

	/**
	 * The payload handed to saveObject() and the uuid it was given.
	 *
	 * @return void
	 */
	public function testACreateDropsIdentityFieldsFromItsDirectFields(): void {
		$mappings = $this->createMock(ZgwMappingService::class);
		$mappings->method('getMapping')->willReturn(['sourceRegister' => 'dossiq', 'sourceSchema' => 'caseType', 'propertyMapping' => []]);

		$rules = $this->createMock(ZgwBusinessRulesService::class);
		$rules->method('validate')->willReturn(
			[
				'valid' => true,
				'enrichedBody' => [
					'omschrijving' => 'Nieuw zaaktype',
					'_directFields' => ['id' => 'victim-1', 'uuid' => 'victim-1', '@self' => ['id' => 'victim-1'], 'statusTypes' => ['st-1']],
				],
			]
		);

		$service = new ZgwService(
			$mappings,
			$this->createMock(ZgwPaginationHelper::class),
			$this->createMock(ZgwDocumentService::class),
			$this->createMock(NotificatieService::class),
			$rules,
			$this->createMock(ZgwJwtValidator::class),
			new NullLogger(),
		);

		$objects = new class () {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * Record the write, then stop the request.
			 *
			 * @param array<string, mixed> $object The payload.
			 * @param mixed                ...$rest Register, schema and uuid, by name.
			 *
			 * @return never
			 */
			public function saveObject(array $object, mixed ...$rest): never {
				$this->saved[] = ['object' => $object, 'uuid' => ($rest['uuid'] ?? null)];
				throw new RuntimeException('stop after the write');
			}
		};
		$mapper = new class () {
			/**
			 * Map the inbound body as a passThrough-false mapping does: the declared field only.
			 *
			 * @param mixed                $mapping The mapping.
			 * @param array<string, mixed> $input   The body.
			 *
			 * @return array<string, mixed>
			 */
			public function executeMapping(mixed $mapping, array $input): array {
				return ['title' => (string)($input['omschrijving'] ?? '')];
			}
		};
		(new ReflectionProperty(ZgwService::class, 'objectService'))->setValue($service, $objects);
		(new ReflectionProperty(ZgwService::class, 'mappingService'))->setValue($service, $mapper);
		(new ReflectionProperty(ZgwService::class, 'cachedRequestBody'))->setValue($service, ['omschrijving' => 'Nieuw zaaktype']);

		$service->handleCreate(request: $this->createMock(IRequest::class), zgwApi: 'catalogi', resource: 'zaaktypen');

		$this->assertCount(1, $objects->saved);
		$this->assertSame(['title' => 'Nieuw zaaktype', 'statusTypes' => ['st-1']], $objects->saved[0]['object']);
		$this->assertNull($objects->saved[0]['uuid']);
	}//end testACreateDropsIdentityFieldsFromItsDirectFields()
}//end class
