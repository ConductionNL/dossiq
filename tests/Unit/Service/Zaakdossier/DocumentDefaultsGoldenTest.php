<?php

/**
 * Golden test for DocumentDefaults::forNewFile: the whole record a new file
 * gets, keys in order, for each actor and case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Zaakdossier\DocumentDefaults;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use OCP\Files\File;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class DocumentDefaultsGoldenTest extends TestCase {

	private function defaults(?IUser $actor): DocumentDefaults {
		$store = $this->createMock(DocumentRecordStore::class);
		$store->method('findDocumentType')->willReturnCallback(
			static fn (string $typeId): array => match ($typeId) {
				'iot-1' => ['id' => 'iot-1', 'vertrouwelijkheidaanduiding' => ' zaakvertrouwelijk '],
				default => ['uuid' => 'iot-first'],
			}
		);
		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$caseTypes->method('effectiveCaseType')->willReturnCallback(
			static fn (string $caseTypeId): array => ($caseTypeId === 'ct-1') ? ['defaultInformatieobjecttype' => ' iot-1 '] : []
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($actor);

		return new DocumentDefaults(store: $store, caseTypes: $caseTypes, userSession: $session);
	}//end defaults()

	private function node(): File {
		$node = $this->createMock(File::class);
		$node->method('getName')->willReturn('Advies.v2.pdf');
		$node->method('getSize')->willReturn(5);
		$node->method('getMimeType')->willReturn('application/pdf');
		$node->method('getId')->willReturn(77);
		$node->method('fopen')->willReturnCallback(
			static function (): mixed {
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, 'hallo');
				rewind($stream);
				return $stream;
			}
		);
		return $node;
	}//end node()

	private function record(DocumentDefaults $defaults, array $case): array {
		$record = $defaults->forNewFile(node: $this->node(), case: $case);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $record['creatiedatum']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $record['integrity']['date']);
		$record['creatiedatum']      = 'TODAY';
		$record['integrity']['date'] = 'NOW';
		return $record;
	}//end record()

	public function testTheWholeRecord(): void {
		$actor = $this->createMock(IUser::class);
		$actor->method('getUID')->willReturn('anna');
		$actor->method('getDisplayName')->willReturn('Anna de Vries');
		$integrity = ['algorithm' => 'sha256', 'value' => hash('sha256', 'hallo'), 'date' => 'NOW'];
		$record    = static fn (string $classification, string $author, string $type, string $direction): array => [
			'title' => 'Advies.v2',
			'fileName' => 'Advies.v2.pdf',
			'bestandsomvang' => 5,
			'format' => 'application/pdf',
			'vertrouwelijkheidaanduiding' => $classification,
			'auteur' => $author,
			'status' => 'draft',
			'informatieobjecttype' => $type,
			'direction' => $direction,
			'keywords' => [],
			'creatiedatum' => 'TODAY',
			'bronorganisatie' => '',
			'taal' => 'nld',
			'description' => '',
			'integrity' => $integrity,
			'fileId' => 77,
		];

		$this->assertSame($record('zaakvertrouwelijk', 'Anna de Vries', 'iot-1', 'internal'), $this->record($this->defaults($actor), ['caseType' => ['id' => 'ct-1'], 'assignee' => 'anna']));
		$this->assertSame($record('zaakvertrouwelijk', 'Anna de Vries', 'iot-1', 'incoming'), $this->record($this->defaults($actor), ['caseType' => ' ct-1 ', 'assignee' => 'bob']));
		$this->assertSame($record(DocumentDefaults::FALLBACK_CLASSIFICATION, '', 'iot-first', 'incoming'), $this->record($this->defaults(null), ['caseType' => 7, 'assignee' => '']));
	}//end testTheWholeRecord()
}//end class
