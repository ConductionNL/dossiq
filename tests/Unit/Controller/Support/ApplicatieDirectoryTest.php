<?php

/**
 * The applicatie directory lists consumers by clientId and reads a token's client_id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\ApplicatieDirectory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\ApplicatieDirectory
 */
class ApplicatieDirectoryTest extends TestCase {

	/**
	 * A consumer with a name and extra clientIds.
	 *
	 * @param string             $name   The primary clientId.
	 * @param array<int, string> $extras The extra clientIds, or null for no configuration.
	 *
	 * @return object
	 */
	private static function consumer(string $name, ?array $extras = null): object {
		return new class($name, $extras) {
			/**
			 * Constructor.
			 *
			 * @param string                  $name   The name.
			 * @param array<int, string>|null $extras The extras.
			 */
			public function __construct(public string $name, private ?array $extras) {
			}//end __construct()

			/**
			 * The authorization configuration.
			 *
			 * @return array<string, mixed>|null
			 */
			public function getAuthorizationConfiguration(): ?array {
				if ($this->extras === null) {
					return null;
				}

				return ['clientIds' => $this->extras];
			}//end getAuthorizationConfiguration()
		};
	}//end consumer()

	/**
	 * A mapper over the given consumers that records every findAll filter.
	 *
	 * @param array<int, object> $consumers The stored consumers.
	 *
	 * @return object
	 */
	private static function mapper(array $consumers): object {
		return new class($consumers) {
			/**
			 * Every findAll call's filters.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, object> $consumers The consumers.
			 */
			public function __construct(private array $consumers) {
			}//end __construct()

			/**
			 * Find consumers whose name matches the name filter.
			 *
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, object>
			 */
			public function findAll(array $filters = []): array {
				$this->calls[] = $filters;
				if (isset($filters['name']) === false) {
					return $this->consumers;
				}

				return array_values(array_filter($this->consumers, fn (object $c): bool => $c->name === $filters['name']));
			}//end findAll()
		};
	}//end mapper()

	/**
	 * A token with the given payload.
	 *
	 * @param array<string, mixed> $payload The claims.
	 *
	 * @return string
	 */
	private static function token(array $payload): string {
		return 'eyJhbGciOiJIUzI1NiJ9.' . base64_encode((string)json_encode($payload)) . '.sig';
	}//end token()

	/**
	 * 'clientId' wins over 'clientIds'; empty and absent values count as no filter.
	 *
	 * @return void
	 */
	public function testRequestedClientIdPrefersClientIdAndIgnoresEmpty(): void {
		$directory = new ApplicatieDirectory(consumerMapper: null);

		$this->assertSame('a', $directory->requestedClientId(clientId: 'a', clientIds: 'b'));
		$this->assertSame('b', $directory->requestedClientId(clientId: '', clientIds: 'b'));
		$this->assertSame('b', $directory->requestedClientId(clientId: null, clientIds: 'b'));
		$this->assertNull($directory->requestedClientId(clientId: '', clientIds: ''));
		$this->assertNull($directory->requestedClientId(clientId: null, clientIds: null));
	}//end testRequestedClientIdPrefersClientIdAndIgnoresEmpty()

	/**
	 * The client_id claim is read, then iss; anything unreadable is "onbekend".
	 *
	 * @return void
	 */
	public function testClientIdOfReadsTheTokenOrSaysUnknown(): void {
		$directory = new ApplicatieDirectory(consumerMapper: null);

		$this->assertSame('app-one', $directory->clientIdOf(presentedToken: self::token(['client_id' => 'app-one', 'iss' => 'other'])));
		$this->assertSame('issuer', $directory->clientIdOf(presentedToken: self::token(['iss' => 'issuer'])));
		$this->assertSame('onbekend', $directory->clientIdOf(presentedToken: self::token(['client_id' => ''])));
		$this->assertSame('onbekend', $directory->clientIdOf(presentedToken: self::token(['client_id' => 42])));
		$this->assertSame('onbekend', $directory->clientIdOf(presentedToken: 'only.two'));
		$this->assertSame('onbekend', $directory->clientIdOf(presentedToken: 'a.!!!.c'));
		$this->assertSame('onbekend', $directory->clientIdOf(presentedToken: ''));
	}//end testClientIdOfReadsTheTokenOrSaysUnknown()

	/**
	 * Without a filter every consumer is listed after one unfiltered read.
	 *
	 * @return void
	 */
	public function testNoFilterListsEveryConsumer(): void {
		$one = self::consumer('app-one');
		$two = self::consumer('app-two');
		$mapper = self::mapper([$one, $two]);

		$this->assertSame([$one, $two], (new ApplicatieDirectory(consumerMapper: $mapper))->listConsumers(filterClientId: null));
		$this->assertSame([[]], $mapper->calls);
	}//end testNoFilterListsEveryConsumer()

	/**
	 * A filter matching a consumer's name answers that consumer without searching the extras.
	 *
	 * @return void
	 */
	public function testAPrimaryClientIdMatchStopsTheSearch(): void {
		$one = self::consumer('app-one', ['shared']);
		$two = self::consumer('app-two');
		$mapper = self::mapper([$one, $two]);

		$this->assertSame([$two], (new ApplicatieDirectory(consumerMapper: $mapper))->listConsumers(filterClientId: 'app-two'));
		$this->assertSame([[], ['name' => 'app-two']], $mapper->calls);
	}//end testAPrimaryClientIdMatchStopsTheSearch()

	/**
	 * With no name match, consumers carrying the clientId among their extras are listed.
	 *
	 * @return void
	 */
	public function testExtraClientIdsAreSearchedWhenNoNameMatches(): void {
		$one = self::consumer('app-one', ['extra-two']);
		$two = self::consumer('app-two', ['other']);
		$three = self::consumer('app-three');
		$four = self::consumer('app-four', ['x', 'extra-two']);
		$mapper = self::mapper([$one, $two, $three, $four]);

		$this->assertSame([$one, $four], (new ApplicatieDirectory(consumerMapper: $mapper))->listConsumers(filterClientId: 'extra-two'));
		$this->assertSame([[], ['name' => 'extra-two'], []], $mapper->calls);
	}//end testExtraClientIdsAreSearchedWhenNoNameMatches()

	/**
	 * The extras are compared strictly, and an unknown clientId lists nothing.
	 *
	 * @return void
	 */
	public function testUnknownOrLooselyEqualClientIdListsNothing(): void {
		$mapper = self::mapper([self::consumer('app-one', ['1'])]);
		$directory = new ApplicatieDirectory(consumerMapper: $mapper);

		$this->assertSame([], $directory->listConsumers(filterClientId: 1));
		$this->assertSame([], $directory->listConsumers(filterClientId: 'nobody'));
	}//end testUnknownOrLooselyEqualClientIdListsNothing()
}//end class
