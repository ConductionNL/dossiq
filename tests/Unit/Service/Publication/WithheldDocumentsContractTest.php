<?php

/**
 * Contract test between dossiq's withheld list and opencatalogi's record door.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-published-woo-decision-records-its-withheld-documents-in-opencatalogi-req-wrw-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\WithheldEntries;
use OCA\Dossiq\Service\Publication\WithheldListRecorder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The call dossiq makes matches the door opencatalogi opens.
 *
 * The literals below are copied from opencatalogi
 * lib/Service/Woo/WithheldDocuments.php on development @9063e7ea6. Its own side of
 * this contract is WithheldDocumentsRecordTest::testTheContractKeysMatchBothSides.
 */
class WithheldDocumentsContractTest extends TestCase {

	use EnglishL10n;

	/**
	 * WithheldDocuments.php:122 `public function record(string $publicationId, array $entries, string $source): array`.
	 */
	private const METHOD = 'record';

	/**
	 * WithheldDocuments.php:122, the three parameters in order with their types.
	 */
	private const PARAMETERS = [['publicationId', 'string'], ['entries', 'array'], ['source', 'string']];

	/**
	 * WithheldDocuments.php:66 `ENTRY_KEYS`.
	 */
	private const ENTRY_KEYS = ['position', 'grounds'];

	/**
	 * WithheldDocuments.php:80 `ANSWER_KEYS`.
	 */
	private const ANSWER_KEYS = ['recorded', 'refused'];

	/**
	 * WithheldDocuments.php:87 `REFUSED_KEYS`.
	 */
	private const REFUSED_KEYS = ['position', 'code', 'reason'];

	/**
	 * The method dossiq calls has opencatalogi's name, parameters and types.
	 *
	 * Built on the real class when it is autoloadable, on the copied
	 * signature (the fake mirrors it) otherwise.
	 *
	 * @return void
	 */
	public function testTheCallMatchesOpencatalogisSignature(): void {
		$class = FakeWithheldDocuments::class;
		if (class_exists(WithheldListRecorder::RECORD_CLASS) === true) {
			$class = WithheldListRecorder::RECORD_CLASS;
			self::assertSame(self::ENTRY_KEYS, constant($class . '::ENTRY_KEYS'));
			self::assertSame(self::ANSWER_KEYS, constant($class . '::ANSWER_KEYS'));
			self::assertSame(self::REFUSED_KEYS, constant($class . '::REFUSED_KEYS'));
		}

		$method = new ReflectionMethod($class, self::METHOD);
		$parameters = array_map(
			static fn (\ReflectionParameter $p): array => [$p->getName(), (string)$p->getType()],
			$method->getParameters()
		);

		self::assertSame(self::PARAMETERS, $parameters);
		self::assertSame('array', (string)$method->getReturnType());
		self::assertSame('OCA\OpenCatalogi\Service\Woo\WithheldDocuments', WithheldListRecorder::RECORD_CLASS);
	}//end testTheCallMatchesOpencatalogisSignature()

	/**
	 * Every entry dossiq builds has exactly opencatalogi's entry keys.
	 *
	 * @return void
	 */
	public function testTheEntriesCarryExactlyTheEntryKeys(): void {
		$entries = (new WithheldEntries())->fromAssessments(
			assessments: [['documentRef' => 'a', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2.e'], 'title' => 'x']],
			withheldValue: 'niet_openbaar',
			groundsField: 'weigeringsgronden',
		);

		self::assertSame(self::ENTRY_KEYS, array_keys($entries[0]));
	}//end testTheEntriesCarryExactlyTheEntryKeys()

	/**
	 * The recorder reads opencatalogi's answer keys and refused keys.
	 *
	 * @return void
	 */
	public function testTheAnswerIsReadByOpencatalogisKeys(): void {
		$door = new FakeWithheldDocuments();
		$door->answer = array_combine(self::ANSWER_KEYS, [2, [array_combine(self::REFUSED_KEYS, [7, '9.9', 'unknown-ground'])]]);
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($door);
		$recorder = new WithheldListRecorder(
			container: $container,
			logger: $this->createMock(\Psr\Log\LoggerInterface::class),
			l10n: $this->english(),
			recordClass: FakeWithheldDocuments::class,
		);

		$answer = $recorder->record('pub', []);

		self::assertSame(2, $answer['recorded']);
		self::assertSame([['position' => 7, 'code' => '9.9', 'reason' => 'unknown-ground']], $answer['refused']);
	}//end testTheAnswerIsReadByOpencatalogisKeys()
}//end class
