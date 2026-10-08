<?php

/**
 * Unit tests for GeneratedDocumentFiler.
 *
 * The filer turns the file Filinq stored into an informatieobject on the case.
 * What it hands the dossier is the contract: the file's own name and bytes,
 * the type and confidentiality the metadata carries, the direction, the
 * author, and the addressees the case names when the metadata says `case`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\CorrespondentWriter;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler
 */
class GeneratedDocumentFilerTest extends TestCase {

	/**
	 * What the dossier was asked to upload.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $uploaded = null;

	/**
	 * The filer over a dossier that records, a root folder holding one file and a signed-in user.
	 *
	 * @param array<int, mixed> $nodes What the root folder answers for the file id.
	 *
	 * @return GeneratedDocumentFiler The filer.
	 */
	private function filer(array $nodes): GeneratedDocumentFiler {
		$dossier = $this->createMock(ZaakdossierService::class);
		$dossier->method('uploadDocument')->willReturnCallback(
			function (string $caseId, string $fileName, string $content, array $metadata): array {
				$this->uploaded = [$caseId, $fileName, $content, $metadata];

				return ['id' => 'inf-1'];
			}
		);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->with(41)->willReturn($nodes);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Jan Behandelaar');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$writer = $this->createMock(CorrespondentWriter::class);
		$writer->method('addressedParties')->willReturn(['party-aanvrager']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($writer);

		return new GeneratedDocumentFiler($dossier, $root, $session, $container);
	}//end filer()

	/**
	 * The stored file.
	 *
	 * @return File The file.
	 */
	private function file(): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Besluit.pdf');
		$file->method('getContent')->willReturn('%PDF-1.7');

		return $file;
	}//end file()

	/**
	 * The file becomes an informatieobject with the metadata's type and the case's addressee.
	 *
	 * @return void
	 */
	public function testTheFileIsFiledWithWhatTheMetadataSays(): void {
		$created = $this->filer([$this->file()])->file(
			caseId: 'case-1',
			fileId: 41,
			title: 'Besluit',
			mime: 'application/pdf',
			metadata: ['informatieobjecttype' => 'type-1', 'addressees' => 'case', 'classification' => 'vertrouwelijk']
		);

		self::assertSame(['id' => 'inf-1'], $created);
		self::assertSame(
			[
				'case-1',
				'Besluit.pdf',
				'%PDF-1.7',
				[
					'title' => 'Besluit',
					'informatieobjecttype' => 'type-1',
					'direction' => 'outgoing',
					'auteur' => 'Jan Behandelaar',
					'format' => 'application/pdf',
					'recipients' => ['party-aanvrager'],
					'vertrouwelijkheidaanduiding' => 'vertrouwelijk',
				],
			],
			$this->uploaded
		);
	}//end testTheFileIsFiledWithWhatTheMetadataSays()

	/**
	 * Without a document type nothing is uploaded.
	 *
	 * @return void
	 */
	public function testWithoutATypeNothingIsFiled(): void {
		$filer = $this->filer([$this->file()]);
		self::assertFalse($filer->wantsFiling(metadata: ['direction' => 'outgoing']));

		$this->expectException(RuntimeException::class);
		try {
			$filer->file(caseId: 'case-1', fileId: 41, title: 'B', mime: 'application/pdf', metadata: []);
		} finally {
			self::assertNull($this->uploaded);
		}
	}//end testWithoutATypeNothingIsFiled()

	/**
	 * A file that cannot be found is a failure, not an empty document.
	 *
	 * @return void
	 */
	public function testAMissingFileIsAFailure(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('file 41');

		$this->filer([])->file(caseId: 'case-1', fileId: 41, title: 'B', mime: 'application/pdf', metadata: ['informatieobjecttype' => 't']);
	}//end testAMissingFileIsAFailure()
}//end class
