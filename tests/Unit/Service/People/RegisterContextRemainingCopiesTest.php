<?php

/**
 * The register and the object service, as the role vocabulary and the document case-ids reader read them.
 *
 * Pins what each of these services answers and refuses before the reading is
 * shared with Custody\DossierRegister: the object service and register pair,
 * the schema a key names, and the exception sentence for each missing piece.
 * The readings are private, so they are reached by reflection.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\People
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\People;

use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use stdClass;

/**
 * Every one of these services reads the register and its schemas the same way.
 *
 * @covers \OCA\Dossiq\Service\People\CaseRoleVocabulary
 * @covers \OCA\Dossiq\Service\Zaakdossier\DocumentCaseIds
 * @uses \OCA\Dossiq\Service\Custody\DossierRegister
 *
 * @spec openspec/specs/case-management/spec.md
 */
class RegisterContextRemainingCopiesTest extends TestCase {
	/**
	 * Each service with the name of its private register reading.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function services(): array {
		return [
			'role vocabulary' => [CaseRoleVocabulary::class, 'requireRegister'],
			'document case ids' => [DocumentCaseIds::class, 'requireRegister'],
		];
	}//end services()

	/**
	 * The service whose schema() reads and refuses the shared way.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function schemaServices(): array {
		return ['role vocabulary' => [CaseRoleVocabulary::class]];
	}//end schemaServices()

	/**
	 * The object service and the configured register come back as a pair.
	 *
	 * @param class-string $class   The service.
	 * @param string       $context Its register reading.
	 *
	 * @dataProvider services
	 *
	 * @return void
	 */
	public function testContextIsTheObjectServiceAndTheRegister(string $class, string $context): void {
		$service = new stdClass();
		$subject = $this->build(class: $class, settings: $this->settings(service: $service, config: ['register' => 'dossiq']));

		self::assertSame(expected: [$service, 'dossiq'], actual: $this->call(subject: $subject, method: $context));
	}//end testContextIsTheObjectServiceAndTheRegister()

	/**
	 * Without OpenRegister the context is refused, before the register is read.
	 *
	 * @param class-string $class   The service.
	 * @param string       $context Its register reading.
	 *
	 * @dataProvider services
	 *
	 * @return void
	 */
	public function testContextRefusesWithoutOpenRegister(string $class, string $context): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->expects(self::never())->method('getConfigValue');
		$subject = $this->build(class: $class, settings: $settings);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'OpenRegister is not available');
		$this->call(subject: $subject, method: $context);
	}//end testContextRefusesWithoutOpenRegister()

	/**
	 * Without a configured register the context is refused.
	 *
	 * @param class-string $class   The service.
	 * @param string       $context Its register reading.
	 *
	 * @dataProvider services
	 *
	 * @return void
	 */
	public function testContextRefusesWithoutARegister(string $class, string $context): void {
		$subject = $this->build(class: $class, settings: $this->settings(service: new stdClass(), config: []));

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Dossier register not configured');
		$this->call(subject: $subject, method: $context);
	}//end testContextRefusesWithoutARegister()

	/**
	 * A configured schema is the value its key holds.
	 *
	 * @param class-string $class The service.
	 *
	 * @dataProvider schemaServices
	 *
	 * @return void
	 */
	public function testSchemaIsTheConfiguredValue(string $class): void {
		$subject = $this->build(class: $class, settings: $this->settings(service: null, config: ['role_schema' => 'role']));

		self::assertSame(expected: 'role', actual: $this->schema(subject: $subject, key: 'role_schema'));
	}//end testSchemaIsTheConfiguredValue()

	/**
	 * An unset schema is refused with the key in the sentence.
	 *
	 * @param class-string $class The service.
	 *
	 * @dataProvider schemaServices
	 *
	 * @return void
	 */
	public function testSchemaRefusesAnUnsetKey(string $class): void {
		$subject = $this->build(class: $class, settings: $this->settings(service: null, config: []));

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Dossiq schema role_schema not configured');
		$this->schema(subject: $subject, key: 'role_schema');
	}//end testSchemaRefusesAnUnsetKey()

	/**
	 * Settings answering the given object service and configuration.
	 *
	 * @param object|null           $service The object service, or null.
	 * @param array<string, string> $config  The configuration values.
	 *
	 * @return SettingsService The settings double.
	 */
	private function settings(?object $service, array $config): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($service);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return $settings;
	}//end settings()

	/**
	 * The service over the given settings, every other dependency a double or its default.
	 *
	 * @param class-string    $class    The service.
	 * @param SettingsService $settings The settings.
	 *
	 * @return object The service.
	 */
	private function build(string $class, SettingsService $settings): object {
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			if ($parameter->isDefaultValueAvailable() === true) {
				break;
			}

			$type = $parameter->getType();
			self::assertInstanceOf(expected: ReflectionNamedType::class, actual: $type);
			$arguments[] = $this->argument(type: $type->getName(), settings: $settings);
		}

		return new $class(...$arguments);
	}//end build()

	/**
	 * The settings for a SettingsService parameter, a double for any other.
	 *
	 * @param class-string    $type     The parameter's class.
	 * @param SettingsService $settings The settings.
	 *
	 * @return object The argument.
	 */
	private function argument(string $type, SettingsService $settings): object {
		if ($type === SettingsService::class) {
			return $settings;
		}

		return $this->createMock(originalClassName: $type);
	}//end argument()

	/**
	 * Call a private method of the service that takes no argument.
	 *
	 * @param object $subject The service.
	 * @param string $method  The method.
	 *
	 * @return mixed What the method returned.
	 */
	private function call(object $subject, string $method): mixed {
		return (new ReflectionMethod($subject, $method))->invoke($subject);
	}//end call()

	/**
	 * Call the service's private schema().
	 *
	 * @param object $subject The service.
	 * @param string $key     The schema key.
	 *
	 * @return mixed What schema() returned.
	 */
	private function schema(object $subject, string $key): mixed {
		return (new ReflectionMethod($subject, 'schema'))->invoke($subject, $key);
	}//end schema()
}//end class
