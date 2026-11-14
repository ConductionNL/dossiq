<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for ConnectedSystemDocumentFiler.
 *
 * Pins when a document from a connected system is refused, the metadata the
 * upload path receives (defaults, trimming, dropped empties), which case id
 * is used, the file name reduced to its base name, the temporary file being
 * removed, and the answer for a failed upload.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\ConnectedSystemDocumentFiler;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Refusals, metadata and upload handling of the document filer.
 *
 * @covers \OCA\Dossiq\Service\Cases\ConnectedSystemDocumentFiler
 */
class ConnectedSystemDocumentFilerTest extends TestCase {

	/**
	 * The document type uuid the name maps to.
	 */
	private const TYPE = 'type-uuid-1';

	/**
	 * Uploads the handler received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $uploads = [];

	/**
	 * What the handler answers.
	 *
	 * @var array<string, mixed>
	 */
	private array $answer = ['success' => true, 'informatieobject' => ['id' => 'doc-1']];

	/**
	 * Without content or a file name the document is refused before any upload.
	 *
	 * @return void
	 */
	public function testMissingContentOrFileNameIsRefused(): void {
		$filer = $this->filer();
		$cases = [
			'no content' => ['content' => null],
			'empty content' => ['content' => ''],
			'non-string content' => ['content' => ['bytes']],
			'empty name' => ['fileName' => '  '],
			'array name' => ['fileName' => ['a.pdf']],
		];

		foreach ($cases as $label => $override) {
			$result = $filer->file(operation: $override + self::operation(), documentTypes: self::types(), case: self::kase(), system: 'sys');
			self::assertSame(['refusal' => 'Een document heeft een bestandsnaam en een inhoud nodig.', 'kind' => 'refused'], $result, $label);
		}

		self::assertSame([], $this->uploads);
	}//end testMissingContentOrFileNameIsRefused()

	/**
	 * A missing or unmapped document type is refused before any upload.
	 *
	 * @return void
	 */
	public function testMissingOrUnmappedTypeIsRefused(): void {
		$filer = $this->filer();
		$expected = ['refusal' => 'Het documenttype is niet gekoppeld aan een documenttype in dossiq.', 'kind' => 'refused'];

		self::assertSame($expected, $filer->file(operation: ['documentTypeName' => ''] + self::operation(), documentTypes: self::types(), case: self::kase(), system: 'sys'));
		self::assertSame($expected, $filer->file(operation: ['documentTypeName' => 'Aanvraag'] + self::operation(), documentTypes: self::types(), case: self::kase(), system: 'sys'));
		self::assertSame($expected, $filer->file(operation: self::operation(), documentTypes: ['Besluit' => ''], case: self::kase(), system: 'sys'));
		self::assertSame([], $this->uploads);
	}//end testMissingOrUnmappedTypeIsRefused()

	/**
	 * The metadata defaults the title to the file name, trims values and drops empty ones.
	 *
	 * @return void
	 */
	public function testMetadataDefaultsTrimsAndDropsEmpties(): void {
		$operation = [
			'fileName' => 'besluit.pdf',
			'content' => 'bytes',
			'documentTypeName' => ' Besluit ',
			'confidentiality' => '  openbaar ',
			'author' => '',
			'description' => ['not', 'text'],
			'language' => 'nld',
			'creationDate' => 20261012,
		];

		$result = $this->filer()->file(operation: $operation, documentTypes: self::types(), case: self::kase(), system: 'sys');

		self::assertSame(['identifier' => 'doc-1'], $result);
		self::assertSame(
			[
				'title' => 'besluit.pdf',
				'informatieobjecttype' => self::TYPE,
				'vertrouwelijkheidaanduiding' => 'openbaar',
				'creatiedatum' => '20261012',
				'taal' => 'nld',
				'direction' => 'incoming',
			],
			$this->uploads[0]['metadata']
		);
	}//end testMetadataDefaultsTrimsAndDropsEmpties()

	/**
	 * The file name is reduced to its base name and the format is passed as the type.
	 *
	 * @return void
	 */
	public function testFileNameIsReducedToItsBaseName(): void {
		$this->filer()->file(operation: ['fileName' => '../../etc/brief.txt', 'format' => 'text/plain'] + self::operation(), documentTypes: self::types(), case: self::kase(), system: 'sys');

		self::assertSame('brief.txt', $this->uploads[0]['file']['name']);
		self::assertSame('text/plain', $this->uploads[0]['file']['type']);
		self::assertSame('bytes', $this->uploads[0]['bytes']);
		self::assertFileDoesNotExist($this->uploads[0]['file']['tmp_name']);
	}//end testFileNameIsReducedToItsBaseName()

	/**
	 * The case id comes from `@self.id`, else from `id`, else is empty.
	 *
	 * @return void
	 */
	public function testCaseIdSource(): void {
		$filer = $this->filer();
		$filer->file(operation: self::operation(), documentTypes: self::types(), case: ['@self' => ['id' => 'self-id'], 'id' => 'plain-id'], system: 'sys');
		$filer->file(operation: self::operation(), documentTypes: self::types(), case: ['id' => 'plain-id'], system: 'sys');
		$filer->file(operation: self::operation(), documentTypes: self::types(), case: [], system: 'sys');

		self::assertSame(['self-id', 'plain-id', ''], array_column($this->uploads, 'caseId'));
	}//end testCaseIdSource()

	/**
	 * A failed upload is logged with the system and error, and refused without the internal error.
	 *
	 * @return void
	 */
	public function testFailedUploadIsLoggedAndRefused(): void {
		$this->answer = ['success' => false, 'error' => 'disk full'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('ConnectedSystemCaseWriter: a document from a connected system was not filed', ['system' => 'src-7', 'error' => 'disk full']);

		$result = $this->filer(logger: $logger)->file(operation: self::operation(), documentTypes: self::types(), case: self::kase(), system: 'src-7');

		self::assertSame(['refusal' => 'Het document is niet opgenomen in het dossier.', 'kind' => 'refused'], $result);
		self::assertFileDoesNotExist($this->uploads[0]['file']['tmp_name']);
	}//end testFailedUploadIsLoggedAndRefused()

	/**
	 * A successful upload without an informatieobject id answers an empty identifier.
	 *
	 * @return void
	 */
	public function testSuccessWithoutIdAnswersEmptyIdentifier(): void {
		$this->answer = ['success' => true];

		self::assertSame(['identifier' => ''], $this->filer()->file(operation: self::operation(), documentTypes: self::types(), case: self::kase(), system: 'sys'));
	}//end testSuccessWithoutIdAnswersEmptyIdentifier()

	/**
	 * A complete document operation.
	 *
	 * @return array<string, mixed>
	 */
	private static function operation(): array {
		return ['fileName' => 'besluit.pdf', 'content' => 'bytes', 'documentTypeName' => 'Besluit', 'title' => 'Besluit', 'format' => 'application/pdf'];
	}//end operation()

	/**
	 * The document type map.
	 *
	 * @return array<string, string>
	 */
	private static function types(): array {
		return ['Besluit' => self::TYPE];
	}//end types()

	/**
	 * A case row.
	 *
	 * @return array<string, mixed>
	 */
	private static function kase(): array {
		return ['@self' => ['id' => 'case-1']];
	}//end kase()

	/**
	 * The filer over a recording upload handler.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ConnectedSystemDocumentFiler
	 */
	private function filer(?LoggerInterface $logger = null): ConnectedSystemDocumentFiler {
		$uploads = $this->createMock(DossierUploadHandler::class);
		$uploads->method('uploadOne')->willReturnCallback(
			function (string $caseId, array $file, array $metadata): array {
				$this->uploads[] = ['caseId' => $caseId, 'file' => $file, 'metadata' => $metadata, 'bytes' => (string)file_get_contents($file['tmp_name'])];

				return $this->answer;
			}
		);

		return new ConnectedSystemDocumentFiler($uploads, $logger ?? $this->createMock(LoggerInterface::class));
	}//end filer()
}//end class
