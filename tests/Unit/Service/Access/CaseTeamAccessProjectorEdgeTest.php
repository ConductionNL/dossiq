<?php

/**
 * The early returns and the failure path of CaseTeamAccessProjector::reapply.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\Access\CaseTeamAccessRules;
use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * No mapper object or no case schema answers false quietly; a write that
 * throws is logged as an error and answers false; the absent app is info.
 *
 * @covers \OCA\Dossiq\Service\Access\CaseTeamAccessProjector
 * @uses   \OCA\Dossiq\Service\Access\CaseTeamAccessRules
 * @uses   \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class CaseTeamAccessProjectorEdgeTest extends TestCase {

	/**
	 * Every logger call: level, message, context.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * A projector over a container holding $mapper and a resolver finding $schema.
	 *
	 * @param mixed           $mapper    What the container holds under the SchemaMapper id; false = nothing.
	 * @param object|null     $schema    What the slug resolver finds.
	 * @param \Throwable|null $readFails What reading the case types throws.
	 * @param bool            $resolves  Whether the resolver may be asked at all.
	 *
	 * @return CaseTeamAccessProjector The projector.
	 */
	private function projector(mixed $mapper, ?object $schema = null, ?\Throwable $readFails = null, bool $resolves = true): CaseTeamAccessProjector {
		$store = $this->createMock(CaseTypeStore::class);
		if ($readFails !== null) {
			$store->method('everyCaseType')->willThrowException($readFails);
		} else {
			$store->expects($this->never())->method('everyCaseType');
		}

		$slugs = $this->createMock(SchemaSlugResolver::class);
		if ($resolves === true) {
			$slugs->expects($this->once())->method('resolve')->with($mapper, 'case')->willReturn($schema);
		} else {
			$slugs->expects($this->never())->method('resolve');
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper !== false);
		$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, (string)$message, $context];
				}
			);
		}

		return new CaseTeamAccessProjector(
			store: $store,
			handling: new CaseTypeHandling(),
			rules: new CaseTeamAccessRules(),
			slugs: $slugs,
			fragments: new RegisterFragmentMerger(),
			container: $container,
			logger: $logger,
		);

	}//end projector()

	/**
	 * A container entry that is not an object answers false without a lookup.
	 *
	 * @return void
	 */
	public function testAMapperThatIsNotAnObjectWritesNothing(): void {
		$this->assertFalse($this->projector(mapper: 'not a mapper', resolves: false)->reapply());
		$this->assertSame([], $this->logged);
	}//end testAMapperThatIsNotAnObjectWritesNothing()

	/**
	 * No case schema answers false before the case types are read.
	 *
	 * @return void
	 */
	public function testNoCaseSchemaWritesNothing(): void {
		$this->assertFalse($this->projector(mapper: new \stdClass())->reapply());
		$this->assertSame([], $this->logged);
	}//end testNoCaseSchemaWritesNothing()

	/**
	 * A write that throws is logged as an error with its cause and answers false.
	 *
	 * @return void
	 */
	public function testAThrowingWriteIsLoggedAndFalse(): void {
		$schema = new class {

			/**
			 * The live authorization.
			 *
			 * @return array<string, mixed>
			 */
			public function getAuthorization(): array {
				return [];
			}//end getAuthorization()
		};

		$this->assertFalse($this->projector(mapper: new \stdClass(), schema: $schema, readFails: new RuntimeException('register offline'))->reapply());
		$this->assertSame(
			[['error', 'Dossiq: could not write the handling team rules onto the case schema', ['exception' => 'register offline']]],
			$this->logged
		);
	}//end testAThrowingWriteIsLoggedAndFalse()

	/**
	 * Without OpenRegister that is logged as information, never as an error.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItIsInformation(): void {
		$this->assertFalse($this->projector(mapper: false, resolves: false)->reapply());
		$this->assertSame(
			[['info', 'Dossiq: no OpenRegister SchemaMapper, so the handling team rules were not written', []]],
			$this->logged
		);
	}//end testWithoutOpenRegisterItIsInformation()
}//end class
