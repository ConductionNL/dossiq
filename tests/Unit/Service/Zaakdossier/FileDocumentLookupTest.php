<?php

/**
 * FileDocumentLookup: the informatieobject record behind a Nextcloud file.
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
use OCA\Dossiq\Service\Zaakdossier\FileDocumentLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A file id finds its record id across the whole register, unscoped by RBAC or tenancy.
 */
#[CoversClass(FileDocumentLookup::class)]
final class FileDocumentLookupTest extends TestCase {

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

	/**
	 * A lookup configured for register 5 and schema 11 over these rows.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return FileDocumentLookup
	 */
	private function lookup(array $rows): FileDocumentLookup {
		return new FileDocumentLookup($this->settings($this->objectService($rows), ['register' => '5', 'dossier_informatieobject_schema' => '11']));
	}//end lookup()

	public function testAFileFindsItsRecordWithAnUnscopedSearch(): void {
		$this->assertSame('doc-1', $this->lookup([['id' => ' doc-1 ']])->recordIdOfFile(42));
		$this->assertSame(
			[['searchObjects', ['fileId' => 42, '_limit' => 1, '@self' => ['register' => 5, 'schema' => 11]], ['_rbac' => false, '_multitenancy' => false]]],
			$this->calls
		);
	}//end testAFileFindsItsRecordWithAnUnscopedSearch()

	public function testTheIdFallsBackToTheUuidAndThenTheSelfId(): void {
		$this->assertSame('u-1', $this->lookup([['uuid' => 'u-1']])->recordIdOfFile(42));
		$this->assertSame('s-1', $this->lookup([['@self' => ['id' => 's-1']]])->recordIdOfFile(42));
	}//end testTheIdFallsBackToTheUuidAndThenTheSelfId()

	public function testAFileWithoutARecordHasNone(): void {
		$this->assertSame('', $this->lookup([])->recordIdOfFile(42));
	}//end testAFileWithoutARecordHasNone()

	public function testNoFileIdOrNoConfigurationMeansNoSearch(): void {
		$service = $this->objectService([['id' => 'doc-1']]);

		$this->assertSame('', $this->lookup([['id' => 'doc-1']])->recordIdOfFile(0));
		$this->assertSame('', (new FileDocumentLookup($this->settings(null, ['register' => '5', 'dossier_informatieobject_schema' => '11'])))->recordIdOfFile(42));
		$this->assertSame('', (new FileDocumentLookup($this->settings($service, ['register' => '5'])))->recordIdOfFile(42));
		$this->assertSame([], $this->calls);
	}//end testNoFileIdOrNoConfigurationMeansNoSearch()
}//end class
