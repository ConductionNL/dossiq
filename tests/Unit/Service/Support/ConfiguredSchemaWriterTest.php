<?php

/**
 * Saving into a configured schema, or handing the object back untouched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ConfiguredSchemaWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\Dossiq\Service\Support\ConfiguredSchemaWriter
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class ConfiguredSchemaWriterTest extends TestCase {

	/**
	 * Failures handed to the writer's callback.
	 *
	 * @var array<int, Throwable>
	 */
	private array $failures = [];

	/**
	 * The writer over these config values and object service.
	 *
	 * @param array<string, string> $config        Config values.
	 * @param object|null           $objectService The object service.
	 *
	 * @return ConfiguredSchemaWriter The writer.
	 */
	private function writer(array $config, ?object $objectService): ConfiguredSchemaWriter {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($config[$key] ?? '')
		);

		return new ConfiguredSchemaWriter(
			settingsService: $settings,
			onFailure: function (Throwable $e): void {
				$this->failures[] = $e;
			}
		);
	}//end writer()

	/**
	 * An object service that answers saveObject() with `$answer`, or throws it.
	 *
	 * @param mixed $answer The saved result, or a Throwable to throw.
	 *
	 * @return object The fake.
	 */
	private function objects(mixed $answer): object {
		return new class ($answer) {
			/**
			 * Every save as [object, register, schema, uuid].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $saves = [];

			/**
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param int|string           $register Register.
			 * @param int|string           $schema   Schema.
			 * @param string|null          $uuid     Uuid.
			 *
			 * @return mixed The answer.
			 */
			public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): mixed {
				$this->saves[] = [$object, $register, $schema, $uuid];
				if ($this->answer instanceof Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end objects()

	/**
	 * A configured schema gets the object; the saved row comes back.
	 *
	 * @return void
	 */
	public function testSavesIntoTheConfiguredSchema(): void {
		$objects = $this->objects(answer: ['id' => 'n1', 'title' => 'Note']);
		$writer = $this->writer(config: ['register' => 'dossiq', 'note_schema' => 'notes'], objectService: $objects);

		$this->assertSame(['id' => 'n1', 'title' => 'Note'], $writer->save(schemaConfigKey: 'note_schema', object: ['title' => 'Note']));
		$this->assertSame([[['title' => 'Note'], 'dossiq', 'notes', null]], $objects->saves);
		$this->assertSame([], $this->failures);
	}//end testSavesIntoTheConfiguredSchema()

	/**
	 * A serialisable entity is returned as its array; an unreadable answer returns the input.
	 *
	 * @return void
	 */
	public function testTheSavedEntityIsReturnedAsAnArray(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'n2'];
			}
		};
		$config = ['register' => 'dossiq', 'note_schema' => 'notes'];

		$this->assertSame(['id' => 'n2'], $this->writer(config: $config, objectService: $this->objects(answer: $entity))->save(schemaConfigKey: 'note_schema', object: ['a' => 1]));
		$this->assertSame(['a' => 1], $this->writer(config: $config, objectService: $this->objects(answer: null))->save(schemaConfigKey: 'note_schema', object: ['a' => 1]));
	}//end testTheSavedEntityIsReturnedAsAnArray()

	/**
	 * Without OpenRegister, a register or the schema, nothing is saved.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTargetSavesNothing(): void {
		$objects = $this->objects(answer: ['id' => 'x']);

		$this->assertSame(['a' => 1], $this->writer(config: ['register' => 'dossiq', 'note_schema' => 'notes'], objectService: null)->save(schemaConfigKey: 'note_schema', object: ['a' => 1]));
		$this->assertSame(['a' => 1], $this->writer(config: ['note_schema' => 'notes'], objectService: $objects)->save(schemaConfigKey: 'note_schema', object: ['a' => 1]));
		$this->assertSame(['a' => 1], $this->writer(config: ['register' => 'dossiq'], objectService: $objects)->save(schemaConfigKey: 'note_schema', object: ['a' => 1]));
		$this->assertSame([], $objects->saves);
		$this->assertSame([], $this->failures);
	}//end testAnUnconfiguredTargetSavesNothing()

	/**
	 * A failing save goes to the callback, and the input comes back.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsHandedToTheCallback(): void {
		$error = new RuntimeException('validation failed');
		$writer = $this->writer(config: ['register' => 'dossiq', 'note_schema' => 'notes'], objectService: $this->objects(answer: $error));

		$this->assertSame(['a' => 1], $writer->save(schemaConfigKey: 'note_schema', object: ['a' => 1]));
		$this->assertSame([$error], $this->failures);
	}//end testAFailingSaveIsHandedToTheCallback()
}//end class
