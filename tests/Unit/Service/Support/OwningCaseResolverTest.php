<?php

/**
 * OwningCaseResolver: the case a child object belongs to, directly or through an intermediate object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Any lookup that cannot be completed resolves to no case, so the access check that asks denies.
 */
#[CoversClass(OwningCaseResolver::class)]
final class OwningCaseResolverTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	/**
	 * The finds the object service double received: [id, register, schema].
	 *
	 * @var array<int, array{0: string, 1: int, 2: int}>
	 */
	private array $finds = [];

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A resolver over an object service holding $objects, configured as given.
	 *
	 * @param array<string, mixed>  $objects Object id to stored value (a Throwable is thrown).
	 * @param array<string, string> $config  Config key to value.
	 * @param bool                  $openRegister Whether an object service exists.
	 *
	 * @return OwningCaseResolver
	 */
	private function resolver(array $objects, array $config = ['register' => '5', 'task_schema' => '20', 'step_schema' => '21'], bool $openRegister = true): OwningCaseResolver {
		$finds = &$this->finds;
		$service = new class($objects, $finds) {
			public function __construct(private array $objects, private array &$finds) {
			}

			public function find(string $id, int $register = 0, int $schema = 0): mixed {
				$this->finds[] = [$id, $register, $schema];
				$value = ($this->objects[$id] ?? null);
				if ($value instanceof \Throwable) {
					throw $value;
				}

				return $value;
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($openRegister === true ? $service : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		return new OwningCaseResolver($settings, $this->logger);
	}//end resolver()

	/**
	 * An entity-like value that serialises to $data.
	 *
	 * @param mixed $data The serialised value.
	 *
	 * @return object
	 */
	private static function entity(mixed $data): object {
		return new class($data) implements \JsonSerializable {
			public function __construct(private mixed $data) {
			}

			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end entity()

	public function testAChildNamesItsCaseInTheConfiguredRegisterAndSchema(): void {
		$resolver = $this->resolver(['t-1' => ['caseId' => 'case-1']]);

		$this->assertSame('case-1', $resolver->resolve('t-1', 'task_schema'));
		$this->assertSame([['t-1', 5, 20]], $this->finds);
	}//end testAChildNamesItsCaseInTheConfiguredRegisterAndSchema()

	public function testTheCaseFieldCanBeNamedAndEntitiesAreSerialised(): void {
		$resolver = $this->resolver(['t-1' => self::entity(['case' => 'case-2'])]);

		$this->assertSame('case-2', $resolver->resolve('t-1', 'task_schema', 'case'));
	}//end testTheCaseFieldCanBeNamedAndEntitiesAreSerialised()

	public function testAChildWithoutACaseOrThatCannotBeReadHasNone(): void {
		$resolver = $this->resolver([
			'empty' => ['caseId' => ''],
			'scalar' => 'not an object',
			'odd' => self::entity('not an array'),
			'plain' => new \stdClass(),
		]);

		$this->assertNull($resolver->resolve('empty', 'task_schema'));
		$this->assertNull($resolver->resolve('scalar', 'task_schema'));
		$this->assertNull($resolver->resolve('odd', 'task_schema'));
		$this->assertNull($resolver->resolve('plain', 'task_schema'));
		$this->assertNull($resolver->resolve('missing', 'task_schema'));
	}//end testAChildWithoutACaseOrThatCannotBeReadHasNone()

	public function testAFailedLookupIsLoggedAndDenied(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with('Dossiq: owning-case lookup failed — denying: gone');

		$this->assertNull($this->resolver(['t-1' => new RuntimeException('gone')])->resolve('t-1', 'task_schema'));
	}//end testAFailedLookupIsLoggedAndDenied()

	public function testNothingIsLookedUpWithoutAnIdOpenRegisterOrConfiguration(): void {
		$objects = ['t-1' => ['caseId' => 'case-1']];

		$this->assertNull($this->resolver($objects)->resolve('', 'task_schema'));
		$this->assertNull($this->resolver($objects, openRegister: false)->resolve('t-1', 'task_schema'));
		$this->assertNull($this->resolver($objects, ['task_schema' => '20'])->resolve('t-1', 'task_schema'));
		$this->assertNull($this->resolver($objects)->resolve('t-1', 'unconfigured_schema'));
		$this->assertSame([], $this->finds);
	}//end testNothingIsLookedUpWithoutAnIdOpenRegisterOrConfiguration()

	public function testAChildReachesItsCaseThroughTheLinkedObject(): void {
		$resolver = $this->resolver([
			'step-1' => ['task' => 't-1'],
			't-1' => ['case' => 'case-3'],
		]);

		$this->assertSame('case-3', $resolver->resolveVia('step-1', 'step_schema', 'task', 'task_schema'));
		$this->assertSame([['step-1', 5, 21], ['t-1', 5, 20]], $this->finds);
	}//end testAChildReachesItsCaseThroughTheLinkedObject()

	public function testABrokenLinkResolvesToNoCase(): void {
		$resolver = $this->resolver(['step-1' => ['task' => ''], 'step-2' => ['task' => 'missing']]);

		$this->assertNull($resolver->resolveVia('step-1', 'step_schema', 'task', 'task_schema'));
		$this->assertNull($resolver->resolveVia('step-2', 'step_schema', 'task', 'task_schema'));
		$this->assertNull($resolver->resolveVia('unknown', 'step_schema', 'task', 'task_schema'));
	}//end testABrokenLinkResolvesToNoCase()
}//end class
