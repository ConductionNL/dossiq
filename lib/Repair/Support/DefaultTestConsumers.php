<?php

/**
 * The two ZGW test consumers a development install is seeded with.
 *
 * A superuser consumer and a read-only one with limited scopes, written
 * through OpenRegister's ConsumerMapper: created when absent, their
 * authorization configuration brought up to date when present.
 * {@see \OCA\Dossiq\Repair\LoadDefaultZgwMappings} looks the mapper up and
 * reports the counts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use DateTime;

/**
 * Creates or updates the default ZGW test consumers.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class DefaultTestConsumers {

	/**
	 * Create each default consumer, or update the one that already exists by name.
	 *
	 * @param object $consumerMapper OpenRegister's ConsumerMapper.
	 *
	 * @return array{0: int, 1: int} The number created and the number updated.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function upsert(object $consumerMapper): array {
		$created = 0;

		$updated = 0;

		foreach ($this->defaults() as $applicatie) {
			$existing = $consumerMapper->findAll(filters: ['name' => $applicatie['name']]);
			if (count(value: $existing) > 0) {
				$this->update(consumerMapper: $consumerMapper, consumer: $existing[0], applicatie: $applicatie);
				$updated++;
				continue;
			}

			$this->create(consumerMapper: $consumerMapper, applicatie: $applicatie);
			$created++;
		}

		return [$created, $updated];
	}//end upsert()

	/**
	 * The default test consumer configurations.
	 *
	 * @return array<int, array<string, mixed>> The default consumer data
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function defaults(): array {
		return [
			$this->adminApplicatie(),
			$this->limitedApplicatie(),
		];
	}//end defaults()

	/**
	 * Update an existing consumer's authorization configuration so new scopes are applied.
	 *
	 * @param object               $consumerMapper OpenRegister's ConsumerMapper.
	 * @param object               $consumer       The existing consumer.
	 * @param array<string, mixed> $applicatie     The default consumer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function update(object $consumerMapper, object $consumer, array $applicatie): void {
		$newConfig = $applicatie['authorizationConfiguration'] ?? [];
		$consumer->setAuthorizationConfiguration($newConfig);
		$consumer->setUpdated(new DateTime());
		$consumerMapper->update($consumer);
	}//end update()

	/**
	 * Create a default consumer with fresh timestamps.
	 *
	 * @param object               $consumerMapper OpenRegister's ConsumerMapper.
	 * @param array<string, mixed> $applicatie     The default consumer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function create(object $consumerMapper, array $applicatie): void {
		$applicatie['created'] = new DateTime();
		$applicatie['updated'] = new DateTime();
		$consumerMapper->createFromArray(object: $applicatie);
	}//end create()

	/**
	 * The superuser test consumer.
	 *
	 * @return array<string, mixed> The consumer data
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function adminApplicatie(): array {
		return [
			// FROZEN client ids + secrets below. These are ZGW consumer
			// credentials registered in the ZGW consumer table and referenced
			// by name from tests/zgw/seed-consumers.sh and the Postman
			// environments. A renamed id here makes the seed script look up a
			// consumer that does not exist — a KeyError that aborts the whole
			// e2e job before a single test runs. `procest-admin` is also a
			// Nextcloud GROUP id (see TransitionAuthorizer::ADMIN_GROUP_ID).
			'name' => 'procest-admin',
			'description' => 'Dossiq Admin (development)',
			'authorizationType' => 'jwt-zgw',
			'userId' => 'admin',
			'authorizationConfiguration' => [
				'publicKey' => 'procest-admin-secret-key-for-testing',
				'algorithm' => 'HS256',
				'superuser' => true,
				'scopes' => [],
			],
		];
	}//end adminApplicatie()

	/**
	 * The read-only test consumer with limited scopes.
	 *
	 * @return array<string, mixed> The consumer data
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function limitedApplicatie(): array {
		return [
			'name' => 'procest-limited',
			'description' => 'Dossiq Limited (testing)',
			'authorizationType' => 'jwt-zgw',
			'userId' => 'admin',
			'authorizationConfiguration' => [
				'publicKey' => 'procest-limited-secret-key-for-test',
				'algorithm' => 'HS256',
				'superuser' => false,
				'scopes' => $this->limitedScopes(),
			],
		];
	}//end limitedApplicatie()

	/**
	 * The scopes the limited test consumer holds.
	 *
	 * @return array<int, array<string, mixed>> The scopes
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function limitedScopes(): array {
		return [
			[
				'component' => 'ztc',
				'scopes' => ['zaaktypen.lezen'],
			],
			[
				'component' => 'zrc',
				'scopes' => ['zaken.lezen'],
				'maxVertrouwelijkheidaanduiding' => 'openbaar',
			],
		];
	}//end limitedScopes()
}//end class
