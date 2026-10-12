<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Integriq's StUF write events are carried out through the connected system writer (decision 206).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\StufProviderWriteListener;
use OCA\Dossiq\Service\Cases\ConnectedSystemCaseWriter;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufCaseQueryEvent;
use OCA\Integriq\Event\StufDocumentMutationEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\StufProviderWriteListener
 */
class StufProviderWriteListenerTest extends TestCase {

	/**
	 * What the writer was asked to carry out.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * What the writer answers.
	 *
	 * @var array<string, string>
	 */
	private array $outcome = ['identifier' => '2026-0050'];

	/**
	 * A mutation from an integriq that sends no generic operation is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAMutationWithoutAnOperationIsRefused(): void {
		$case     = new StufCaseMutationEvent(sourceId: 'src-9', referentienummer: 'REQ-5', mutatiesoort: 'W', fields: ['identificatie' => '2026-0042']);
		$document = new StufDocumentMutationEvent(sourceId: 'src-9', referentienummer: 'REQ-6', mutatiesoort: 'T', fields: ['identificatie' => 'DOC-1']);
		$this->listener()->handle($case);
		$this->listener()->handle($document);

		$this->assertNotNull($case->getRefusal());
		$this->assertNotNull($document->getRefusal());
		$this->assertSame([], $case->getAnswer());
		$this->assertSame([], $this->written);
	}//end testAMutationWithoutAnOperationIsRefused()

	/**
	 * A mapped write is handed to the writer with what the endpoint allows, and its outcome goes back on the event.
	 *
	 * @return void
	 */
	public function testAWriteIsCarriedOutWithWhatTheEndpointAllows(): void {
		$operation = ['action' => 'create', 'identifier' => 'ZSH-1', 'title' => 'Kap', 'caseTypeName' => 'Omgevingsvergunning kap'];
		$event     = new StufCaseMutationEvent(sourceId: 'src-9', referentienummer: 'REQ-5', mutatiesoort: 'T', fields: [], operation: $operation);
		$this->listener()->handle($event);

		$this->assertSame(
			[
				[
					'operation' => $operation,
					'connection' => [
						'acceptsWrites' => true,
						'caseTypes' => ['kapvergunning' => 'Omgevingsvergunning kap'],
						'documentTypes' => ['Besluit' => 'doc-type-uuid'],
					],
					'system' => 'src-9',
				],
			],
			$this->written
		);
		$this->assertNull($event->getRefusal());
		$this->assertSame('dossiq', $event->getAnsweredBy());
		$this->assertSame(['identification' => '2026-0050'], $event->getAnswer());

		$document = new StufDocumentMutationEvent(
			sourceId: 'src-9',
			referentienummer: 'REQ-6',
			mutatiesoort: 'T',
			fields: [],
			operation: ['action' => 'add-document', 'caseIdentifier' => '2026-0042']
		);
		$this->listener()->handle($document);
		$this->assertSame(['identification' => '2026-0050'], $document->getAnswer());
	}//end testAWriteIsCarriedOutWithWhatTheEndpointAllows()

	/**
	 * The writer's refusal goes back with its kind, so integriq can answer the right status.
	 *
	 * @return void
	 */
	public function testAWritersRefusalKeepsItsKind(): void {
		foreach (['forbidden', 'not-found', 'conflict', 'refused'] as $kind) {
			$this->outcome = ['refusal' => 'Nee', 'kind' => $kind];
			$event         = new StufCaseMutationEvent(sourceId: 'src-9', referentienummer: 'REQ-5', mutatiesoort: 'W', fields: [], operation: ['action' => 'update']);
			$this->listener()->handle($event);

			$this->assertSame('Nee', $event->getRefusal());
			$this->assertSame($kind, $event->getRefusalKind());
			$this->assertSame([], $event->getAnswer());
		}
	}//end testAWritersRefusalKeepsItsKind()

	/**
	 * A write on a source no stufEndpoint names is not dossiq's: unanswered, nothing written.
	 *
	 * @return void
	 */
	public function testAWriteOnAnotherAppsSourceIsLeftUnanswered(): void {
		$event = new StufCaseMutationEvent(sourceId: 'someone-elses', referentienummer: 'REQ-5', mutatiesoort: 'T', fields: [], operation: ['action' => 'create']);
		$this->listener()->handle($event);

		$this->assertFalse($event->isAnswered());
		$this->assertSame([], $this->written);
	}//end testAWriteOnAnotherAppsSourceIsLeftUnanswered()

	/**
	 * A query is not this listener's: it is left alone.
	 *
	 * @return void
	 */
	public function testAQueryIsLeftAlone(): void {
		$event = new StufCaseQueryEvent(sourceId: 'src-9', referentienummer: 'REQ-1', criteria: ['identificatie' => '2026-0042']);
		$this->listener()->handle($event);

		$this->assertFalse($event->isAnswered());
		$this->assertSame([], $this->written);
	}//end testAQueryIsLeftAlone()

	/**
	 * Build the listener over a recording writer.
	 *
	 * @return StufProviderWriteListener
	 */
	private function listener(): StufProviderWriteListener {
		$register = $this->createMock(StufRegisterAccess::class);
		$register->method('findOne')->willReturnCallback(
			static function (string $schema, array $filters): ?array {
				if ($schema === StufRegisterAccess::SCHEMA_ENDPOINT && $filters === ['integriqSource' => 'src-9']) {
					return [
						'id' => 'ep-1',
						'integriqSource' => 'src-9',
						'acceptsWrites' => true,
						'zaaktypeMappings' => ['kapvergunning' => 'Omgevingsvergunning kap'],
						'documentTypeMappings' => ['Besluit' => 'doc-type-uuid'],
					];
				}

				return null;
			}
		);

		$writer = $this->createMock(ConnectedSystemCaseWriter::class);
		$writer->method('carryOut')->willReturnCallback(
			function (array $operation, array $connection, string $system): array {
				$this->written[] = ['operation' => $operation, 'connection' => $connection, 'system' => $system];
				return $this->outcome;
			}
		);

		return new StufProviderWriteListener($register, $writer);
	}//end listener()
}//end class
