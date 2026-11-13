<?php

/**
 * Unit tests for CaseTypeReferenceResolver: which case type a reference names.
 *
 * 🔴 WHAT THESE GUARD IS A CASE FILED ON A TYPE NOBODY NAMED. A uuid reads one
 * row; anything else is a catalogue identifier, and a new case goes to the
 * published, unsuperseded version of that chain. A search that ignores an
 * unknown filter answers every case type, so the identifier is checked again
 * on each row: the first row of an unfiltered answer is not the one asked for.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/vth-dso-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseTypeReferenceResolver.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver
 *
 * @uses \OCA\Dossiq\Service\CaseTypeStore
 * @uses \OCA\Dossiq\Service\Support\SerialisedResult
 */
class CaseTypeReferenceResolverTest extends TestCase {

	private const UUID_V1 = '0a1b2c3d-0000-4000-8000-000000000001';

	private const UUID_V2 = '0a1b2c3d-0000-4000-8000-000000000002';

	/**
	 * The case type rows, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every call the object service received, as [method, argument].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $calls = [];

	/**
	 * A chain of three versions of CT-1000, and a stranger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			self::UUID_V1 => ['id' => self::UUID_V1, 'identifier' => 'CT-1000', 'version' => '1', 'isDraft' => false, 'supersededBy' => self::UUID_V2],
			self::UUID_V2 => ['id' => self::UUID_V2, 'identifier' => 'CT-1000', 'version' => '2', 'isDraft' => false],
			'ct-3' => ['id' => 'ct-3', 'identifier' => 'CT-1000', 'version' => '3', 'isDraft' => true],
			'other' => ['id' => 'other', 'identifier' => 'CT-2000', 'version' => '9', 'isDraft' => false],
		];
		$this->calls = [];
	}//end setUp()

	/**
	 * The resolver over an in-memory object service.
	 *
	 * @param bool $filtering Whether the search honours the identifier filter.
	 *
	 * @return CaseTypeReferenceResolver The resolver.
	 */
	private function resolver(bool $filtering=true): CaseTypeReferenceResolver {
		$objectService = new class($this->rows, $this->calls, $filtering) {
			/**
			 * @param array<string, array<string, mixed>>    $rows      The rows.
			 * @param array<int, array{0: string, 1: mixed}> $calls     The recorded calls.
			 * @param bool                                   $filtering Whether the identifier filter applies.
			 */
			public function __construct(
				private array $rows,
				public array &$calls,
				private bool $filtering,
			) {
			}//end __construct()

			/**
			 * Read one row.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, $register=null, $schema=null): ?array {
				$this->calls[] = ['find', $id];
				return ($this->rows[$id] ?? null);
			}//end find()

			/**
			 * Search on identifier, or ignore the filter.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				$this->calls[] = ['searchObjects', $query];
				if ($this->filtering === false) {
					return array_values($this->rows);
				}

				return array_values(
					array_filter($this->rows, static fn (array $row): bool => $row['identifier'] === $query['identifier'])
				);
			}//end searchObjects()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'caseType',
				default => $default,
			}
		);

		return new CaseTypeReferenceResolver(store: new CaseTypeStore($settings));
	}//end resolver()

	/**
	 * A blank reference names nothing and asks the store nothing.
	 *
	 * @return void
	 */
	public function testABlankReferenceResolvesToNothing(): void {
		self::assertSame([], $this->resolver()->resolve(reference: "  \t "));
		self::assertSame([], $this->calls);
	}//end testABlankReferenceResolvesToNothing()

	/**
	 * A uuid is read directly, lower-cased, even when it is superseded.
	 *
	 * A caller holding a uuid named a version, not a chain, so the resolver
	 * does not swap it for the current one.
	 *
	 * @return void
	 */
	public function testAUuidIsReadDirectly(): void {
		$row = $this->resolver()->resolve(reference: strtoupper(self::UUID_V1));

		self::assertSame(self::UUID_V1, $row['id']);
		self::assertSame([['find', self::UUID_V1]], $this->calls);
	}//end testAUuidIsReadDirectly()

	/**
	 * A ZGW zaaktype URL is read by the uuid it ends in.
	 *
	 * @return void
	 */
	public function testAZaaktypeUrlIsReadByItsUuid(): void {
		$row = $this->resolver()->resolve(reference: 'https://example.org/catalogi/api/v1/zaaktypen/' . self::UUID_V2);

		self::assertSame(self::UUID_V2, $row['id']);
		self::assertSame('find', $this->calls[0][0]);
	}//end testAZaaktypeUrlIsReadByItsUuid()

	/**
	 * A bare uuid nobody holds is empty, and is never retried as an identifier.
	 *
	 * @return void
	 */
	public function testAnUnknownBareUuidResolvesToNothing(): void {
		self::assertSame([], $this->resolver()->resolve(reference: '0a1b2c3d-0000-4000-8000-00000000ffff'));
		self::assertSame([['find', '0a1b2c3d-0000-4000-8000-00000000ffff']], $this->calls);
	}//end testAnUnknownBareUuidResolvesToNothing()

	/**
	 * An identifier answers the published version nothing has superseded.
	 *
	 * Version 1 is superseded and version 3 is a draft, so a new case goes on
	 * version 2 even though both others are in the same chain.
	 *
	 * @return void
	 */
	public function testAnIdentifierAnswersTheCurrentPublishedVersion(): void {
		$row = $this->resolver()->resolve(reference: ' CT-1000 ');

		self::assertSame(self::UUID_V2, $row['id']);
		self::assertSame('searchObjects', $this->calls[0][0]);
		self::assertSame('CT-1000', $this->calls[0][1]['identifier']);
	}//end testAnIdentifierAnswersTheCurrentPublishedVersion()

	/**
	 * A search that ignores the filter does not hand back a stranger.
	 *
	 * @return void
	 */
	public function testAnUnfilteredSearchIsCheckedAgainOnTheRow(): void {
		self::assertSame(self::UUID_V2, $this->resolver(filtering: false)->resolve(reference: 'CT-1000')['id']);
		self::assertSame('other', $this->resolver(filtering: false)->resolve(reference: 'CT-2000')['id']);
	}//end testAnUnfilteredSearchIsCheckedAgainOnTheRow()

	/**
	 * A chain with only drafts and superseded versions resolves to nothing.
	 *
	 * @return void
	 */
	public function testAChainWithNoCurrentVersionResolvesToNothing(): void {
		unset($this->rows[self::UUID_V2]);

		self::assertSame([], $this->resolver()->resolve(reference: 'CT-1000'));
	}//end testAChainWithNoCurrentVersionResolvesToNothing()

	/**
	 * The id and the opening status read off the row through the store.
	 *
	 * @return void
	 */
	public function testIdAndInitialStatusOfARow(): void {
		$resolver = $this->resolver();

		self::assertSame('ct-9', $resolver->idOf(caseType: ['id' => 'ct-9']));
		self::assertSame('st-1', $resolver->initialStatusOf(caseType: ['initialStatus' => 'st-1']));
		self::assertSame('', $resolver->initialStatusOf(caseType: []));
	}//end testIdAndInitialStatusOfARow()
}//end class
