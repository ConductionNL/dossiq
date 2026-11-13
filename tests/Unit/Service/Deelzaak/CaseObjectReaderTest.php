<?php

/**
 * Tests for the deelzaak case object reader.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Deelzaak
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Deelzaak;

use JsonSerializable;
use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The slice of OpenRegister's ObjectService the reader calls, for mocking.
 */
class CaseObjectReaderObjectService {

	/**
	 * Find one object.
	 *
	 * @param string     $id       The id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 *
	 * @return mixed
	 */
	public function find(string $id, int|string $register = '', int|string $schema = ''): mixed {
		return null;
	}//end find()
}//end class

/**
 * Cases and case types are read from the configured schemas, normalised to arrays.
 *
 * @covers \OCA\Dossiq\Service\Deelzaak\CaseObjectReader
 * @uses   \OCA\Dossiq\Service\Cases\CaseMergeStore
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */
class CaseObjectReaderTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var CaseObjectReaderObjectService|MockObject
	 */
	private CaseObjectReaderObjectService $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The configuration the reader reads.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(CaseObjectReaderObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The reader under test.
	 *
	 * @param bool $withService Whether OpenRegister is available.
	 *
	 * @return CaseObjectReader
	 */
	private function reader(bool $withService = true): CaseObjectReader {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(($withService === true ? $this->objects : null));
		$settings->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => ($this->config[$key] ?? '')
		);

		return new CaseObjectReader(settingsService: $settings, logger: $this->logger);
	}//end reader()

	/**
	 * A case is found on the case schema and answered as an array.
	 *
	 * @return void
	 */
	public function testFetchCaseByIdReadsTheCaseSchema(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with('case-1', 'dossiq', 'case')
			->willReturn(['id' => 'case-1', 'title' => 'Bouw']);

		$this->assertSame(['id' => 'case-1', 'title' => 'Bouw'], $this->reader()->fetchCaseById(caseUuid: 'case-1'));
	}//end testFetchCaseByIdReadsTheCaseSchema()

	/**
	 * An entity answer is serialised.
	 *
	 * @return void
	 */
	public function testFetchCaseByIdSerialisesAnEntity(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The entity as an array.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'case-1'];
			}
		};
		$this->objects->method('find')->willReturn($entity);

		$this->assertSame(['id' => 'case-1'], $this->reader()->fetchCaseById(caseUuid: 'case-1'));
	}//end testFetchCaseByIdSerialisesAnEntity()

	/**
	 * Nothing found, or an answer that is not an object or array, reads as null.
	 *
	 * @return void
	 */
	public function testFetchCaseByIdAnswersNullForNoUsableObject(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(null, 'case-1', new \stdClass());

		$reader = $this->reader();
		$this->assertNull($reader->fetchCaseById(caseUuid: 'case-1'));
		$this->assertNull($reader->fetchCaseById(caseUuid: 'case-1'));
		$this->assertNull($reader->fetchCaseById(caseUuid: 'case-1'));
	}//end testFetchCaseByIdAnswersNullForNoUsableObject()

	/**
	 * An empty id, no OpenRegister or no case schema reads as null without a lookup.
	 *
	 * @return void
	 */
	public function testFetchCaseByIdWithoutAnIdOrConfigurationAnswersNull(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertNull($this->reader()->fetchCaseById(caseUuid: ''));
		$this->assertNull($this->reader(withService: false)->fetchCaseById(caseUuid: 'case-1'));

		$this->config['case_schema'] = '';
		$this->assertNull($this->reader()->fetchCaseById(caseUuid: 'case-1'));
	}//end testFetchCaseByIdWithoutAnIdOrConfigurationAnswersNull()

	/**
	 * A failed lookup reads as null and is logged at debug level with the id.
	 *
	 * @return void
	 */
	public function testFetchCaseByIdLogsAFailedLookup(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())
			->method('debug')
			->with('Case lookup failed', ['uuid' => 'case-1', 'error' => 'gone']);

		$this->assertNull($this->reader()->fetchCaseById(caseUuid: 'case-1'));
	}//end testFetchCaseByIdLogsAFailedLookup()

	/**
	 * A case type is found on the case type schema.
	 *
	 * @return void
	 */
	public function testLoadCaseTypeReadsTheCaseTypeSchema(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with('omgevingsvergunning', 'dossiq', 'caseType')
			->willReturn(['id' => 'type-1']);

		$this->assertSame(['id' => 'type-1'], $this->reader()->loadCaseType(caseTypeId: 'omgevingsvergunning'));
	}//end testLoadCaseTypeReadsTheCaseTypeSchema()

	/**
	 * A case type without OpenRegister, schema or a successful lookup reads as null.
	 *
	 * @return void
	 */
	public function testLoadCaseTypeAnswersNullWhenUnreadable(): void {
		$this->assertNull($this->reader(withService: false)->loadCaseType(caseTypeId: 'type-1'));

		$this->objects->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->never())->method('debug');
		$this->assertNull($this->reader()->loadCaseType(caseTypeId: 'type-1'));

		$this->config['case_type_schema'] = '';
		$this->assertNull($this->reader()->loadCaseType(caseTypeId: 'type-1'));
	}//end testLoadCaseTypeAnswersNullWhenUnreadable()

	/**
	 * The parent reference is read from a scalar or a hydrated object.
	 *
	 * @return void
	 */
	public function testExtractParentReferenceReadsEveryShape(): void {
		$reader = $this->reader();

		$this->assertSame('p-1', $reader->extractParentReference(case: ['parentCase' => 'p-1']));
		$this->assertSame('p-2', $reader->extractParentReference(case: ['parentCase' => ['id' => 'p-2', 'uuid' => 'u']]));
		$this->assertSame('p-3', $reader->extractParentReference(case: ['parentCase' => ['uuid' => 'p-3']]));
		$this->assertSame('', $reader->extractParentReference(case: ['parentCase' => ['id' => 7]]));
		$this->assertSame('', $reader->extractParentReference(case: ['parentCase' => []]));
		$this->assertSame('', $reader->extractParentReference(case: ['parentCase' => 12]));
		$this->assertSame('', $reader->extractParentReference(case: []));
	}//end testExtractParentReferenceReadsEveryShape()

}//end class
