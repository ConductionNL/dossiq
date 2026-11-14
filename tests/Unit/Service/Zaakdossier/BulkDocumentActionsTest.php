<?php

/**
 * BulkDocumentActions: bulk reads and metadata updates, one document at a time.
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

use OCA\Dossiq\Service\Zaakdossier\BulkDocumentActions;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each document is checked for clearance on its own, and one failure never stops the others.
 */
#[CoversClass(BulkDocumentActions::class)]
final class BulkDocumentActionsTest extends TestCase {

	private InformatieobjectReader&MockObject $reader;
	private ZaakdossierService&MockObject $files;
	private IUser $user;

	protected function setUp(): void {
		$this->reader = $this->createMock(InformatieobjectReader::class);
		$this->files  = $this->createMock(ZaakdossierService::class);
		$this->user   = $this->createMock(IUser::class);
		$this->reader->method('guardReadable')->willReturnCallback(
			fn (IUser $user, string $infoObjectId): ?JSONResponse => ($infoObjectId === 'secret' ? new JSONResponse([], 403) : null)
		);
	}//end setUp()

	public function testAllReadableOnlyWhenEveryDocumentIs(): void {
		$bulk = new BulkDocumentActions($this->reader, $this->files);

		$this->assertTrue($bulk->allReadable($this->user, ['a', 'b']));
		$this->assertTrue($bulk->allReadable($this->user, []));
		$this->assertFalse($bulk->allReadable($this->user, ['a', 'secret', 'b']));
	}//end testAllReadableOnlyWhenEveryDocumentIs()

	public function testUpdateAllReportsEachDocumentAndSkipsTheUncleared(): void {
		$updated = [];
		$this->files->method('updateMetadata')->willReturnCallback(
			static function (string $infoObjectId, array $metadata) use (&$updated): array {
				if ($infoObjectId === 'broken') {
					throw new RuntimeException('record locked');
				}

				$updated[] = [$infoObjectId, $metadata];
				return [];
			}
		);

		$results = (new BulkDocumentActions($this->reader, $this->files))
			->updateAll($this->user, ['a', 'secret', 'broken', 7], ['status' => 'definitief']);

		$this->assertSame(
			[
				['id' => 'a', 'success' => true],
				['id' => 'secret', 'success' => false, 'error' => 'Insufficient clearance'],
				['id' => 'broken', 'success' => false, 'error' => 'record locked'],
				['id' => '7', 'success' => true],
			],
			$results
		);
		$this->assertSame([['a', ['status' => 'definitief']], ['7', ['status' => 'definitief']]], $updated);
	}//end testUpdateAllReportsEachDocumentAndSkipsTheUncleared()
}//end class
