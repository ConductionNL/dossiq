<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Procest\Tests\Unit\Service\Ai
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Ai;

use OCA\Hermiq\Event\AiOversightRecordedEvent;
use OCA\Procest\Service\Ai\AiOversightDelegationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the translation from procest's audit entry to hermiq's oversight record.
 *
 * This is the seam where two vocabularies meet — procest says `modified`, hermiq
 * says `overridden` — so it is worth pinning in both directions.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
 */
class AiOversightDelegationServiceTest extends TestCase {

    /**
     * The last event the dispatcher saw.
     *
     * @var AiOversightRecordedEvent|null
     */
    private ?AiOversightRecordedEvent $dispatched = null;


    /**
     * Build the service with a dispatcher that captures, and optionally handles.
     *
     * @param boolean $handled Whether the stub hermiq records the decision.
     *
     * @return AiOversightDelegationService The service.
     */
    private function service(bool $handled=true): AiOversightDelegationService {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(
            function (Event $event) use ($handled): void {
                if ($event instanceof AiOversightRecordedEvent) {
                    $this->dispatched = $event;
                    if ($handled === true) {
                        $event->setApprovalId('approval-uuid');
                    }
                }
            }
        );

        return new AiOversightDelegationService($dispatcher, $this->createMock(LoggerInterface::class));

    }//end service()


    /**
     * A complete decision entry in procest's own shape.
     *
     * @param array<string, mixed> $overrides Keys to replace.
     *
     * @return array<string, mixed> The entry.
     */
    private function entry(array $overrides=[]): array {
        return array_merge(
            [
                'type'        => 'classification',
                'action'      => 'modified',
                'caseId'      => 'case-1',
                'model'       => 'openai/gpt-4o',
                'suggestion'  => ['documentType' => 'request'],
                'userAction'  => 'modified',
                'actualValue' => ['documentType' => 'objectionProceeding'],
                'reason'      => 'misread the header',
                'userId'      => 'alice',
                'timestamp'   => '2026-08-22T10:25:00+00:00',
            ],
            $overrides
        );

    }//end entry()


    /**
     * procest's `modified` becomes hermiq's `overridden`.
     *
     * The two vocabularies disagree and hermiq's wins, because the record lives
     * there. Getting this backwards would file every correction as a rejection.
     *
     * @return void
     */
    public function testModifiedBecomesOverridden(): void {
        $this->assertTrue($this->service()->delegate($this->entry()));

        $record = $this->dispatched->getRecord();
        $this->assertSame('overridden', $record['humanAction']);
        $this->assertSame('procest', $record['originApp']);
        $this->assertSame('case', $record['subjectType']);
        $this->assertSame('case-1', $record['subjectId']);
        $this->assertSame('alice', $record['userId']);

    }//end testModifiedBecomesOverridden()


    /**
     * The other two actions pass through unchanged.
     *
     * @param string $procest The procest userAction.
     * @param string $hermiq  The hermiq humanAction.
     *
     * @return void
     *
     * @dataProvider actionProvider
     */
    public function testActionsMap(string $procest, string $hermiq): void {
        $this->service()->delegate($this->entry(['userAction' => $procest]));

        $this->assertSame($hermiq, $this->dispatched->getRecord()['humanAction']);

    }//end testActionsMap()


    /**
     * The action vocabulary map.
     *
     * @return array<string, string[]> The data set.
     */
    public static function actionProvider(): array {
        return [
            'accepted' => ['accepted', 'accepted'],
            'rejected' => ['rejected', 'rejected'],
            'modified' => ['modified', 'overridden'],
        ];

    }//end actionProvider()


    /**
     * A suggestion-only entry is NOT an oversight decision.
     *
     * procest's log holds both "the model ran" and "a human judged it". Only the
     * second is Art. 14 evidence; sending the first would put rows in hermiq's
     * oversight log that nobody decided.
     *
     * @return void
     */
    public function testSuggestionOnlyEntryIsNotDelegated(): void {
        $entry = $this->entry(['action' => 'suggestion']);
        unset($entry['userAction']);

        $this->assertFalse($this->service()->delegate($entry));
        $this->assertNull($this->dispatched);

    }//end testSuggestionOnlyEntryIsNotDelegated()


    /**
     * A document-scoped entry reports the document as its subject.
     *
     * @return void
     */
    public function testDocumentSubjectIsUsedWhenThereIsNoCase(): void {
        $entry = $this->entry(['documentId' => 'doc-9']);
        unset($entry['caseId']);

        $this->assertTrue($this->service()->delegate($entry));
        $record = $this->dispatched->getRecord();
        $this->assertSame('document', $record['subjectType']);
        $this->assertSame('doc-9', $record['subjectId']);

    }//end testDocumentSubjectIsUsedWhenThereIsNoCase()


    /**
     * An entry with no subject at all is not delegated.
     *
     * @return void
     */
    public function testEntryWithoutASubjectIsNotDelegated(): void {
        $entry = $this->entry();
        unset($entry['caseId']);

        $this->assertFalse($this->service()->delegate($entry));
        $this->assertNull($this->dispatched);

    }//end testEntryWithoutASubjectIsNotDelegated()


    /**
     * Map-shaped suggestions and applied values are rendered, not dropped.
     *
     * procest stores these as `array|string`; hermiq's advisory context holds
     * what the human SAW, which is a rendered value either way.
     *
     * @return void
     */
    public function testMapValuesAreRendered(): void {
        $this->service()->delegate($this->entry());

        $record = $this->dispatched->getRecord();
        $this->assertStringContainsString('request', $record['suggestion']);
        $this->assertStringContainsString('objectionProceeding', $record['actualValue']);

    }//end testMapValuesAreRendered()


    /**
     * The same entry always produces the same reference, so a repair replay is
     * idempotent rather than duplicating the trail.
     *
     * @return void
     */
    public function testReferenceIsStableAcrossReplays(): void {
        $this->service()->delegate($this->entry());
        $first = $this->dispatched->getRecord()['externalRef'];

        $this->dispatched = null;
        $this->service()->delegate($this->entry());
        $second = $this->dispatched->getRecord()['externalRef'];

        $this->assertSame($first, $second);
        $this->assertNotSame('', $first);

    }//end testReferenceIsStableAcrossReplays()


    /**
     * When hermiq does not record it, the caller is told so.
     *
     * That is what lets procest keep its local copy as the only one rather than
     * believing a central record exists.
     *
     * @return void
     */
    public function testUnhandledEventReportsFalse(): void {
        $this->assertFalse($this->service(handled: false)->delegate($this->entry()));

    }//end testUnhandledEventReportsFalse()

    /**
     * A scalar (non-string) value is rendered rather than dropped.
     *
     * `confidence`-shaped fields arrive as numbers; flatten() has a branch for
     * them that nothing exercised.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testScalarValuesAreRendered(): void {
        $this->service()->delegate($this->entry(['suggestion' => 42, 'actualValue' => 7.5]));

        $record = $this->dispatched->getRecord();
        $this->assertSame('42', $record['suggestion']);
        $this->assertSame('7.5', $record['actualValue']);

    }//end testScalarValuesAreRendered()


    /**
     * An empty array renders as an empty string, not as "[]".
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testEmptyArrayRendersEmpty(): void {
        $this->service()->delegate($this->entry(['actualValue' => []]));

        $this->assertSame('', $this->dispatched->getRecord()['actualValue']);

    }//end testEmptyArrayRendersEmpty()


    /**
     * An entry that carries its own id uses it as the reference.
     *
     * That is the stable half of idempotency: a stored entry has an id, and the
     * sha1 fallback is only for entries that do not.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testEntryIdIsUsedAsTheReference(): void {
        $this->service()->delegate($this->entry(['id' => 'entry-uuid-1']));

        $this->assertSame(
            'procest:aiAuditEntry:entry-uuid-1',
            $this->dispatched->getRecord()['externalRef']
        );

    }//end testEntryIdIsUsedAsTheReference()


    /**
     * A dispatcher that throws is reported as not-delegated, never re-raised.
     *
     * The handler has already acted by this point; an audit failure must not
     * become a functional one.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testDispatchFailureIsSwallowed(): void {
        $dispatcher = $this->createMock(IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')
            ->willThrowException(new \RuntimeException('bus unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $service = new AiOversightDelegationService($dispatcher, $logger);

        $this->assertFalse($service->delegate($this->entry()));

    }//end testDispatchFailureIsSwallowed()


}//end class
