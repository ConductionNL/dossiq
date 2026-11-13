<?php

/**
 * DocumentTypeResolver: a document type name becomes its catalogue id, or stays the name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DocumentTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A name resolves to the id of the first informatieobjecttype with that description.
 */
#[CoversClass(DocumentTypeResolver::class)]
final class DocumentTypeResolverTest extends TestCase {

	/**
	 * The searches the object service double received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * An object service double that records each search and answers with $rows.
	 *
	 * @param array<int, mixed> $rows The rows every search answers.
	 *
	 * @return object
	 */
	private function objectService(array $rows): object {
		$calls = &$this->calls;

		return new class($rows, $calls) {
			public function __construct(private array $rows, private array &$calls) {
			}

			public function searchObjects(array $query, ...$scope): array {
				$this->calls[] = ["searchObjects", $query, $scope];
				return $this->rows;
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters, ...$scope): array {
				$this->calls[] = ["searchObjectsBySlug", [$register, $schema, $filters], $scope];
				return $this->rows;
			}
		};
	}//end objectService()

	/**
	 * Settings that hand out $objectService and these config values.
	 *
	 * @param object|null           $objectService The object service, or null when OpenRegister is absent.
	 * @param array<string, string> $config        Config key to value.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, array $config): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method("getObjectService")->willReturn($objectService);
		$settings->method("getConfigValue")->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? "")
		);
		return $settings;
	}//end settings()

	public function testAnEmptyNameResolvesToNothingWithoutASearch(): void {
		$service = $this->objectService([['id' => 'x']]);

		$resolver = new DocumentTypeResolver($this->settings($service, ['register' => '5', 'dossier_informatieobjecttype_schema' => '9']));

		$this->assertSame('', $resolver->resolve('  '));
		$this->assertSame([], $this->calls);
	}//end testAnEmptyNameResolvesToNothingWithoutASearch()

	public function testAMatchingTypeResolvesToItsIdThroughARegisterScopedSearch(): void {
		$resolver = new DocumentTypeResolver(
			$this->settings($this->objectService([['id' => 'type-7', 'uuid' => 'u-7']]), ['register' => '5', 'dossier_informatieobjecttype_schema' => '9'])
		);

		$this->assertSame('type-7', $resolver->resolve('Besluit'));
		$this->assertSame(
			[['searchObjects', ['description' => 'Besluit', '_limit' => 1, '@self' => ['register' => 5, 'schema' => 9]], []]],
			$this->calls
		);
	}//end testAMatchingTypeResolvesToItsIdThroughARegisterScopedSearch()

	public function testARowWithoutAnIdFallsBackToItsUuid(): void {
		$resolver = new DocumentTypeResolver(
			$this->settings($this->objectService([['uuid' => 'u-7']]), ['register' => 'dossiq', 'dossier_informatieobjecttype_schema' => 'informatieobjecttype'])
		);

		$this->assertSame('u-7', $resolver->resolve('Besluit'));
		$this->assertSame('searchObjectsBySlug', $this->calls[0][0]);
		$this->assertSame(['dossiq', 'informatieobjecttype', ['description' => 'Besluit', '_limit' => 1]], $this->calls[0][1]);
	}//end testARowWithoutAnIdFallsBackToItsUuid()

	public function testNoMatchKeepsTheName(): void {
		$resolver = new DocumentTypeResolver(
			$this->settings($this->objectService([]), ['register' => '5', 'dossier_informatieobjecttype_schema' => '9'])
		);

		$this->assertSame('Onbekend type', $resolver->resolve('Onbekend type'));
	}//end testNoMatchKeepsTheName()

	public function testWithoutOpenRegisterOrConfigurationTheNameIsKept(): void {
		$service = $this->objectService([['id' => 'x']]);

		$this->assertSame('Besluit', (new DocumentTypeResolver($this->settings(null, ['register' => '5', 'dossier_informatieobjecttype_schema' => '9'])))->resolve('Besluit'));
		$this->assertSame('Besluit', (new DocumentTypeResolver($this->settings($service, ['dossier_informatieobjecttype_schema' => '9'])))->resolve('Besluit'));
		$this->assertSame('Besluit', (new DocumentTypeResolver($this->settings($service, ['register' => '5'])))->resolve('Besluit'));
		$this->assertSame([], $this->calls);
	}//end testWithoutOpenRegisterOrConfigurationTheNameIsKept()
}//end class
