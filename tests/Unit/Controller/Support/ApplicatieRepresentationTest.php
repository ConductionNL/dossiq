<?php

/**
 * Applicatie Representation Test
 *
 * Pins the mapping between OpenRegister consumers and ZGW applicaties: client
 * ids, label, superuser flag and scopes, both ways.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\ApplicatieRepresentation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApplicatieRepresentation.
 */
#[CoversClass(ApplicatieRepresentation::class)]
final class ApplicatieRepresentationTest extends TestCase {
	/**
	 * A consumer serialising to the data, with the given configuration.
	 *
	 * @param array<string, mixed>      $data       The serialised consumer.
	 * @param array<string, mixed>|null $authConfig The configuration.
	 *
	 * @return object
	 */
	private function consumer(array $data, ?array $authConfig): object {
		return new class($data, $authConfig) {
			/**
			 * @param array<string, mixed>      $data       The data.
			 * @param array<string, mixed>|null $authConfig The configuration.
			 */
			public function __construct(private array $data, private ?array $authConfig) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}

			/**
			 * @return array<string, mixed>|null
			 */
			public function getAuthorizationConfiguration(): ?array {
				return $this->authConfig;
			}
		};
	}//end consumer()

	/**
	 * The name comes first, then the extras; an empty name or non-list extras are left out.
	 *
	 * @return void
	 */
	public function testClientIdsAreTheNameThenTheExtras(): void {
		$rep = new ApplicatieRepresentation();

		self::assertSame(expected: ['app', 'x', 'y'], actual: $rep->clientIdsOf(data: ['name' => 'app'], authConfig: ['clientIds' => ['x', 'y']]));
		self::assertSame(expected: ['x'], actual: $rep->clientIdsOf(data: ['name' => ''], authConfig: ['clientIds' => ['x']]));
		self::assertSame(expected: ['app'], actual: $rep->clientIdsOf(data: ['name' => 'app'], authConfig: ['clientIds' => 'x']));
		self::assertSame(expected: [], actual: $rep->clientIdsOf(data: [], authConfig: []));
	}//end testClientIdsAreTheNameThenTheExtras()

	/**
	 * A consumer without configuration still yields its name.
	 *
	 * @return void
	 */
	public function testConsumerClientIdsToleratesAMissingConfiguration(): void {
		$rep = new ApplicatieRepresentation();

		self::assertSame(expected: ['app'], actual: $rep->consumerClientIds(consumer: $this->consumer(data: ['name' => 'app'], authConfig: null)));
		self::assertSame(
			expected: ['app', 'extra'],
			actual: $rep->consumerClientIds(consumer: $this->consumer(data: ['name' => 'app'], authConfig: ['clientIds' => ['extra']]))
		);
	}//end testConsumerClientIdsToleratesAMissingConfiguration()

	/**
	 * A consumer becomes an applicatie with its url, client ids, label, flag and scopes.
	 *
	 * @return void
	 */
	public function testToApplicatieMapsEveryField(): void {
		$consumer = $this->consumer(
			data: ['uuid' => 'u-1', 'name' => 'app', 'description' => 'My app'],
			authConfig: ['clientIds' => ['two'], 'superuser' => true, 'scopes' => [['component' => 'zrc']]]
		);

		self::assertSame(
			expected: [
				'url' => 'https://h/ac/applicaties/u-1',
				'uuid' => 'u-1',
				'clientIds' => ['app', 'two'],
				'label' => 'My app',
				'heeftAlleAutorisaties' => true,
				'autorisaties' => [['component' => 'zrc']],
			],
			actual: (new ApplicatieRepresentation())->toApplicatie(consumer: $consumer, baseUrl: 'https://h/ac/applicaties')
		);
	}//end testToApplicatieMapsEveryField()

	/**
	 * Without a name or configuration the applicatie still carries one (empty) client id and defaults.
	 *
	 * @return void
	 */
	public function testToApplicatieDefaults(): void {
		$withoutConfig = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'u-2'];
			}
		};

		self::assertSame(
			expected: ['url' => 'b/u-2', 'uuid' => 'u-2', 'clientIds' => [''], 'label' => '', 'heeftAlleAutorisaties' => false, 'autorisaties' => []],
			actual: (new ApplicatieRepresentation())->toApplicatie(consumer: $withoutConfig, baseUrl: 'b')
		);
		self::assertSame(
			expected: [''],
			actual: (new ApplicatieRepresentation())->toApplicatie(consumer: $this->consumer(data: ['uuid' => 'u-3', 'name' => ''], authConfig: null), baseUrl: 'b')['clientIds']
		);
	}//end testToApplicatieDefaults()

	/**
	 * The first client id becomes the name, the rest and the secret go into the configuration.
	 *
	 * @return void
	 */
	public function testToConsumerSplitsTheClientIds(): void {
		self::assertSame(
			expected: [
				'name' => 'one',
				'description' => 'Label',
				'authorizationType' => 'jwt-zgw',
				'authorizationConfiguration' => [
					'superuser' => true,
					'scopes' => [['component' => 'drc']],
					'algorithm' => 'HS256',
					'clientIds' => ['two', 'three'],
					'publicKey' => 's3cret',
				],
			],
			actual: (new ApplicatieRepresentation())->toConsumer(
				body: ['clientIds' => ['one', 'two', 'three'], 'label' => 'Label', 'heeftAlleAutorisaties' => true, 'autorisaties' => [['component' => 'drc']], 'secret' => 's3cret']
			)
		);
	}//end testToConsumerSplitsTheClientIds()

	/**
	 * Without client ids the label names the consumer; without a label it is 'unknown'.
	 *
	 * @return void
	 */
	public function testToConsumerFallsBackToTheLabel(): void {
		$rep = new ApplicatieRepresentation();

		$single = $rep->toConsumer(body: ['clientIds' => ['only'], 'label' => 'L']);
		self::assertSame(expected: 'only', actual: $single['name']);
		self::assertSame(expected: ['superuser' => false, 'scopes' => [], 'algorithm' => 'HS256'], actual: $single['authorizationConfiguration']);

		self::assertSame(expected: 'L', actual: $rep->toConsumer(body: ['clientIds' => [], 'label' => 'L'])['name']);
		$bare = $rep->toConsumer(body: ['clientIds' => 'nope']);
		self::assertSame(expected: 'unknown', actual: $bare['name']);
		self::assertSame(expected: '', actual: $bare['description']);
	}//end testToConsumerFallsBackToTheLabel()
}//end class
