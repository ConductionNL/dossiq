<?php

/**
 * Unit tests for DocumentGeneratedListener.
 *
 * Built on the REAL DocumentGeneratedEvent (a verbatim copy of Filinq's class
 * when Filinq is absent), so a wrong getter fails here and not on the first
 * document a flow makes. The filer is a double: what it does with the file is
 * GeneratedDocumentFilerTest's business; this test is about WHICH documents
 * reach it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DocumentGeneratedListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\CaseObjectReference;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;
use OCA\Filinq\Event\DocumentGeneratedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Listener\DocumentGeneratedListener
 * @covers \OCA\Dossiq\Service\Support\CaseObjectReference
 */
class DocumentGeneratedListenerTest extends TestCase {

	/**
	 * What the filer was asked to file.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $filed = [];

	/**
	 * The listener, over a real case recogniser and a recording filer.
	 *
	 * @param bool $filerThrows Whether filing fails.
	 *
	 * @return DocumentGeneratedListener The listener.
	 */
	private function listener(bool $filerThrows = false): DocumentGeneratedListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => '12', 'case_schema' => '34'][$key] ?? ''
		);

		$filer = $this->getMockBuilder(GeneratedDocumentFiler::class)
			->disableOriginalConstructor()
			->onlyMethods(['file'])
			->getMock();
		$filer->method('file')->willReturnCallback(
			function (string $caseId, int $fileId, string $title, string $mime, array $metadata) use ($filerThrows): array {
				if ($filerThrows === true) {
					throw new RuntimeException('informatieobjecttype is required');
				}

				$this->filed[] = ['caseId' => $caseId, 'fileId' => $fileId, 'title' => $title, 'mime' => $mime, 'metadata' => $metadata];

				return ['id' => 'inf-1'];
			}
		);

		return new DocumentGeneratedListener(new CaseObjectReference($settings), $filer, new NullLogger());
	}//end listener()

	/**
	 * A Filinq event, as the flow step raises it for a case.
	 *
	 * @param array<string, mixed> $overrides Replaces keys of the document.
	 *
	 * @return DocumentGeneratedEvent The event.
	 */
	private function event(array $overrides = []): DocumentGeneratedEvent {
		return new DocumentGeneratedEvent(
			document: array_merge(
				[
					'fileId' => 41,
					'path' => '/jan/files/DocuDesk/dossiq/case-1/Besluit.pdf',
					'name' => 'Besluit.pdf',
					'mime' => 'application/pdf',
					'template' => ['id' => 'inline', 'name' => 'Besluit', 'source' => 'inline'],
					'object' => ['register' => '12', 'schema' => '34', 'id' => 'case-1'],
					'metadata' => ['informatieobjecttype' => 'type-1', 'direction' => 'outgoing', 'addressees' => 'case'],
				],
				$overrides
			),
			requestingApp: 'dossiq'
		);
	}//end event()

	/**
	 * A document made for a dossiq case with a type is filed on that case.
	 *
	 * @return void
	 */
	public function testADocumentForACaseIsFiledOnIt(): void {
		$this->listener()->handle($this->event());

		self::assertSame(
			[
				[
					'caseId' => 'case-1',
					'fileId' => 41,
					'title' => 'Besluit',
					'mime' => 'application/pdf',
					'metadata' => ['informatieobjecttype' => 'type-1', 'direction' => 'outgoing', 'addressees' => 'case'],
				],
			],
			$this->filed
		);
	}//end testADocumentForACaseIsFiledOnIt()

	/**
	 * What is not dossiq's to file is left alone.
	 *
	 * @return void
	 */
	public function testWhatIsNotACaseDocumentIsLeftAlone(): void {
		$listener = $this->listener();

		$listener->handle($this->event(['object' => null]));
		$listener->handle($this->event(['fileId' => null]));
		$listener->handle($this->event(['object' => ['register' => '12', 'schema' => '99', 'id' => 'x']]));
		$listener->handle($this->event(['metadata' => []]));
		$listener->handle($this->event(['metadata' => ['informatieobjecttype' => 'type-1', GeneratedDocumentFiler::FILED_BY_CALLER => true]]));
		$listener->handle(new Event());

		self::assertSame([], $this->filed);
	}//end testWhatIsNotACaseDocumentIsLeftAlone()

	/**
	 * A filing that fails reaches the step, rather than leaving a letter outside the dossier.
	 *
	 * @return void
	 */
	public function testAFailedFilingIsNotSwallowed(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not be filed on the case');

		$this->listener(filerThrows: true)->handle($this->event());
	}//end testAFailedFilingIsNotSwallowed()
}//end class
