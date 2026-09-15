<?php

/**
 * Dossiq intake-source seeder.
 *
 * Puts the channels dossiq handles into OpenRegister's `intake-sources`
 * register as ordinary objects: mail, the portal, the API, the contact centre
 * and the DSO. A channel held as an object is a channel an administrator can
 * see, name, point at a connection and switch off. A channel held as a constant
 * in PHP is none of those things, and that is the reason this exists.
 *
 * THE REGISTER IS NOT DOSSIQ'S. OpenRegister owns `intake-sources` and its
 * `intake-source` schema and seeds both from its own repair step. This class
 * resolves the register by slug and contributes rows; where the register is
 * absent it reports that and writes nothing, so an OpenRegister that predates
 * it costs an upgrade rather than a failure.
 *
 * UPSERT BY SLUG, NEVER OVER THE SWITCH. The title and the description are
 * dossiq's words and are refreshed on every upgrade. `enabled`, `connection`,
 * `location`, `state` and `settings` belong to whoever configured the channel
 * and are never written again after the row exists. An upgrade that re-enabled
 * a source would start polling a mailbox nobody asked it to poll.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Upserts dossiq's intake channels into the OpenRegister intake-sources register.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */
class IntakeSourceSeeder {
	use SearchesObjects;

	/**
	 * The register OpenRegister holds intake sources in.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'intake-sources';

	/**
	 * The schema one intake source is an object of.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'intake-source';

	/**
	 * The catalogue of channels, relative to this file.
	 *
	 * @var string
	 */
	private const CATALOGUE_PATH = __DIR__ . '/../../Settings/intake_sources.json';

	/**
	 * The fields dossiq writes on every upgrade.
	 *
	 * Everything else on an existing row belongs to whoever configured it.
	 *
	 * @var array<int, string>
	 */
	private const REFRESHED_FIELDS = ['title', 'description', 'kind', 'targetRegister', 'targetSchema'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container, for the lazy OpenRegister lookups.
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The channels this app declares, read from the catalogue.
	 *
	 * @return array<int, array<string, mixed>> The channel definitions.
	 *
	 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
	 */
	public function catalogue(): array {
		$content = file_get_contents(self::CATALOGUE_PATH);
		if ($content === false) {
			return [];
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === false || is_array($decoded['sources'] ?? null) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$decoded['sources'],
				static fn (mixed $source): bool => is_array($source) === true
					&& is_string(($source['slug'] ?? null)) === true
					&& $source['slug'] !== ''
			)
		);
	}//end catalogue()

	/**
	 * Seed every declared channel, creating what is missing and refreshing the rest.
	 *
	 * @return array{available: bool, created: int, updated: int, refused: array<int, string>}
	 *                                                                                        What the seed did.
	 *
	 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
	 */
	public function seed(): array {
		$empty = [
			'available' => false,
			'created' => 0,
			'updated' => 0,
			'refused' => [],
		];

		$objectService = $this->resolve(service: 'OCA\OpenRegister\Service\ObjectService');
		$registerMapper = $this->resolve(service: 'OCA\OpenRegister\Db\RegisterMapper');
		if ($objectService === null || $registerMapper === null) {
			return $empty;
		}

		try {
			$register = $registerMapper->find(self::REGISTER_SLUG, false, false);
		} catch (Throwable $e) {
			$this->logger->info(
				'Dossiq: the OpenRegister intake-sources register is not present, no channel seeded',
				['exception' => $e->getMessage()]
			);
			return $empty;
		}

		// A REPAIR STEP RUNS WITH NO SESSION, SO OPENREGISTER RESOLVES THE ACTOR
		// AS 'Anonymous' AND REFUSES EVERY WRITE. The refusal is reported as a
		// warning, which does not fail an upgrade, so the install would print
		// "Update successful" over a register holding nothing. The
		// `intake-source` schema's authorization block names `admin` for create
		// and update, which Anonymous is not. Elevating here rather than in the
		// repair step keeps it beside the writes it covers.
		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->upsertAll(
				objectService: $objectService,
				register: (string)$register->getId()
			)
		);
	}//end seed()

	/**
	 * Create what is missing and refresh the rest, one channel at a time.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The intake-sources register id.
	 *
	 * @return array{available: bool, created: int, updated: int, refused: array<int, string>}
	 *                                                                                        What the seed did.
	 *
	 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
	 */
	private function upsertAll(object $objectService, string $register): array {
		$created = 0;
		$updated = 0;
		$refused = [];

		foreach ($this->catalogue() as $source) {
			$slug = (string)$source['slug'];
			try {
				$existing = $this->findBySlug(
					objectService: $objectService,
					register: $register,
					slug: $slug
				);

				if ($existing === null) {
					$objectService->saveObject(
						register: $register,
						schema: self::SCHEMA_SLUG,
						object: $this->newRow(source: $source)
					);
					$created++;
					continue;
				}

				$objectService->saveObject(
					register: $register,
					schema: self::SCHEMA_SLUG,
					object: $this->refreshedRow(existing: $this->dataOf(object: $existing), source: $source),
					uuid: $this->uuidOf(object: $existing)
				);
				$updated++;
			} catch (Throwable $e) {
				// \Throwable rather than \Exception: an OpenRegister refusal can
				// surface as a PHP Error, and one refused channel must not abort
				// the upgrade that is seeding the other four.
				$refused[] = $slug;
				$this->logger->error(
					'Dossiq: failed to seed an intake source',
					['slug' => $slug, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		return [
			'available' => true,
			'created' => $created,
			'updated' => $updated,
			'refused' => $refused,
		];
	}//end seed()

	/**
	 * A new row: the catalogue's own fields, switched off.
	 *
	 * A channel that started polling on install would poll a mailbox nobody
	 * configured, so `enabled` is false and stays false until somebody says
	 * otherwise.
	 *
	 * @param array<string, mixed> $source The catalogue definition.
	 *
	 * @return array<string, mixed> The object to write.
	 *
	 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
	 */
	public function newRow(array $source): array {
		$row = ['slug' => (string)$source['slug'], 'enabled' => false];
		foreach (self::REFRESHED_FIELDS as $field) {
			if (isset($source[$field]) === true) {
				$row[$field] = $source[$field];
			}
		}

		return $row;
	}//end newRow()

	/**
	 * An existing row with dossiq's own wording refreshed and nothing else touched.
	 *
	 * @param array<string, mixed> $existing The row as it stands.
	 * @param array<string, mixed> $source   The catalogue definition.
	 *
	 * @return array<string, mixed> The object to write back.
	 *
	 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
	 */
	public function refreshedRow(array $existing, array $source): array {
		$row = $existing;
		foreach (self::REFRESHED_FIELDS as $field) {
			if (isset($source[$field]) === true) {
				$row[$field] = $source[$field];
			}
		}

		$row['slug'] = (string)$source['slug'];

		return $row;
	}//end refreshedRow()

	/**
	 * Find one intake source by its slug.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The register id.
	 * @param string $slug          The source's slug.
	 *
	 * @return object|array|null The row, or null when there is none.
	 */
	private function findBySlug(object $objectService, string $register, string $slug): object|array|null {
		$results = $objectService->findAll(
			[
				'filters' => [
					'register' => $register,
					'schema' => self::SCHEMA_SLUG,
					'slug' => $slug,
				],
				'limit' => 1,
			]
		);

		if (is_array($results) === false) {
			return null;
		}

		// The paginated shape first: `count($results) > 0` is also true for
		// `['results' => [...]]`, so reading `$results[0]` first answers "not
		// found" for every paginated response and creates a duplicate row on
		// every upgrade.
		if (is_array(($results['results'] ?? null)) === true && count($results['results']) > 0) {
			return $results['results'][0];
		}

		if (array_key_exists(0, $results) === true) {
			return $results[0];
		}

		return null;
	}//end findBySlug()

	/**
	 * The stored data of a row, whatever shape the read came back in.
	 *
	 * @param object|array $object The row.
	 *
	 * @return array<string, mixed> Its data.
	 */
	private function dataOf(object|array $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}

			return [];
		}

		if (method_exists($object, 'jsonSerialize') === true) {
			$data = $object->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}

			return [];
		}

		return [];
	}//end dataOf()

	/**
	 * The uuid of a row, whatever shape the read came back in.
	 *
	 * @param object|array $object The row.
	 *
	 * @return string|null Its uuid.
	 */
	private function uuidOf(object|array $object): ?string {
		if (is_array($object) === true) {
			$uuid = ($object['id'] ?? ($object['uuid'] ?? null));
			if (is_string($uuid) === true) {
				return $uuid;
			}

			return null;
		}

		if (method_exists($object, 'getUuid') === true) {
			$uuid = $object->getUuid();
			if (is_string($uuid) === true) {
				return $uuid;
			}

			return null;
		}

		return null;
	}//end uuidOf()

	/**
	 * Resolve one OpenRegister service, or nothing.
	 *
	 * @param string $service The fully qualified class name.
	 *
	 * @return object|null The service, or null when OpenRegister cannot answer.
	 */
	private function resolve(string $service): ?object {
		// PSR-11 DECLARES EXACTLY THESE TWO, AND NOTHING WIDER IS CAUGHT.
		// A `\Throwable` here would answer "OpenRegister is not installed" for a
		// TypeError in the service's own constructor, and the seed would then
		// report an absent register rather than the defect. It is also the
		// swallowing-catch shape `ServiceCatchReturnsNullTest` measures, and that
		// ceiling only goes down.
		try {
			return $this->container->get($service);
		} catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
			$this->logger->debug(
				'Dossiq: could not resolve an OpenRegister service for the intake-source seed',
				['service' => $service, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end resolve()
}//end class
