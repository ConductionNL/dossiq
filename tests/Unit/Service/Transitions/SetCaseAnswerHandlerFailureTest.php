<?php

/**
 * SetCaseAnswerHandler incomplete, entity and failure paths.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\AssigneeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SetCaseAnswerHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\SetCaseAnswerHandler
 *
 * @uses \OCA\Dossiq\Service\AssigneeResolver
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class SetCaseAnswerHandlerFailureTest extends TestCase {

	/**
	 * The handler over an object service double.
	 *
	 * @param object|null     $objects The object service, or null when OpenRegister is absent.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return SetCaseAnswerHandler The handler.
	 */
	private function handler(?object $objects, LoggerInterface $logger): SetCaseAnswerHandler {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'reg', 'case_schema' => 'case'][$key] ?? '');

		return new SetCaseAnswerHandler(assignees: new AssigneeResolver(new NullLogger()), settings: $settings, logger: $logger);
	}//end handler()

	/**
	 * Without a name, or without an object service, nothing is written.
	 *
	 * @return void
	 */
	public function testAnIncompleteActionWritesNothing(): void {
		$objects = new class {
			/** @var int */
			public int $calls = 0;

			/**
			 * @return array<string, mixed>
			 */
			public function find(string $id): array {
				$this->calls++;
				return [];
			}
		};

		$noName = $this->handler($objects, new NullLogger())->handle(['name' => ' ', 'value' => 1], ['id' => 'case-1'], []);
		$noStore = $this->handler(null, new NullLogger())->handle(['name' => 'x', 'value' => 1], ['id' => 'case-1'], []);

		$this->assertFalse($noName->succeeded);
		$this->assertSame('set_case_answer_incomplete', $noName->error);
		$this->assertSame('set_case_answer_incomplete', $noStore->error);
		$this->assertSame(0, $objects->calls);
	}//end testAnIncompleteActionWritesNothing()

	/**
	 * A stored case returned as an entity is serialised before the answer is merged.
	 *
	 * @return void
	 */
	public function testAnEntityIsSerialisedBeforeTheMerge(): void {
		$objects = new class {
			/** @var array<int, array<int, mixed>> */
			public array $saved = [];

			/**
			 * @return object
			 */
			public function find(string $id): object {
				return new class($id) implements \JsonSerializable {
					public function __construct(private string $id) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['id' => $this->id, 'properties' => [['name' => 'a', 'value' => 1]]];
					}
				};
			}

			/**
			 * @param array<string, mixed> $object The case.
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->saved[] = [$object, $register, $schema];
				return $object;
			}
		};

		$result = $this->handler($objects, new NullLogger())->handle(['name' => 'b', 'value' => 'ja'], ['id' => 'case-9'], []);

		$this->assertTrue($result->succeeded);
		$this->assertSame(['name' => 'b'], $result->data);
		$this->assertSame(
			[[['id' => 'case-9', 'properties' => [['name' => 'a', 'value' => 1], ['name' => 'b', 'value' => 'ja']]], 'reg', 'case']],
			$objects->saved
		);
	}//end testAnEntityIsSerialisedBeforeTheMerge()

	/**
	 * A read or write that throws fails the step and is logged.
	 *
	 * @return void
	 */
	public function testAThrowingStoreFailsTheStep(): void {
		$objects = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function find(string $id): array {
				throw new RuntimeException('not found: ' . $id);
			}
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('SetCaseAnswerHandler: the answer was not written', ['case' => 'case-1', 'exception' => 'not found: case-1']);

		$result = $this->handler($objects, $logger)->handle(['name' => 'x', 'value' => 1], ['id' => 'case-1'], []);

		$this->assertFalse($result->succeeded);
		$this->assertSame('set_case_answer_failed', $result->error);
	}//end testAThrowingStoreFailsTheStep()
}//end class
