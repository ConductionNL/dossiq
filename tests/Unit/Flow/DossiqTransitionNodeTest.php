<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Flow\DossiqTxCreateTaskNode;
use OCA\Dossiq\Service\Transitions\ActionResult;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Covers the shared node wrapper.
 *
 * Create-task stands in for every dossiq node: they differ only in their
 * handler and their required keys, and the behaviour worth pinning lives in
 * the shared base.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
 */
class DossiqTransitionNodeTest extends TestCase {

    /**
     * @var CreateTaskHandler&\PHPUnit\Framework\MockObject\MockObject
     */
    private $handler;

    /**
     * @var DossiqTxCreateTaskNode
     */
    private DossiqTxCreateTaskNode $node;


    /**
     * Set up the node over a mocked handler.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->handler = $this->createMock(CreateTaskHandler::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $params=[]): string {
                return vsprintf($text, $params);
            }
        );
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturn('/apps/dossiq/img/app-dark.svg');

        $this->node = new DossiqTxCreateTaskNode($this->handler, $l10n, $urls);

    }//end setUp()


    /**
     * A complete config for this node.
     *
     * @return array<string, mixed> The config.
     */
    private function config(): array {
        return [
            'title' => 'Controleer het dossier',
        ];

    }//end config()


    /**
     * The node takes the plain transition id.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testTheNodeTakesThePlainTransitionId(): void {
        $this->assertSame('dossiq.createTask', $this->node->getId());

    }//end testTheNodeTakesThePlainTransitionId()


    /**
     * A successful action puts its data on the item.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testSuccessfulActionWritesItsResultOntoTheItem(): void {
        $this->handler->method('handle')->willReturn(new ActionResult(true, null, ['sent' => 1]));

        $out = $this->node->execute(
            [['json' => ['id' => 'case-1', 'title' => 'Bezwaar']]],
            $this->config(),
            []
        );

        $this->assertCount(1, $out);
        $this->assertSame(['sent' => 1], $out[0]['json']['actionResult']);
        $this->assertSame('case-1', $out[0]['json']['id']);

    }//end testSuccessfulActionWritesItsResultOntoTheItem()


    /**
     * A handler's case writes travel with the outgoing item.
     *
     * The handler stores its field through the partial-write seam; the NEXT
     * step's snapshot must already carry it, or that step reasons from a case
     * that predates its own flow. This is how `besluitDocument` went missing
     * live: stored by the document step, absent from the item, erased by the
     * next step's save.
     *
     * @return void
     *
     * @spec openspec/specs/case-flow-human-steps/spec.md
     */
    public function testCaseChangesAreStampedOntoTheOutgoingItem(): void {
        $this->handler->method('handle')->willReturn(
            new ActionResult(true, null, ['rendered' => 'Besluit'], ['besluitDocument' => 'Besluit'])
        );

        $out = $this->node->execute(
            [['json' => ['id' => 'case-1', 'title' => 'Bezwaar']]],
            $this->config(),
            []
        );

        $this->assertSame('Besluit', $out[0]['json']['besluitDocument']);
        $this->assertSame('case-1', $out[0]['json']['id']);

    }//end testCaseChangesAreStampedOntoTheOutgoingItem()


    /**
     * The output key is configurable.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testOutputKeyIsConfigurable(): void {
        $this->handler->method('handle')->willReturn(new ActionResult(true, null, ['sent' => 1]));

        $out = $this->node->execute(
            [['json' => []]],
            array_merge($this->config(), ['output' => 'taskResult']),
            []
        );

        $this->assertArrayHasKey('taskResult', $out[0]['json']);

    }//end testOutputKeyIsConfigurable()


    /**
     * A FAILED action throws instead of passing the item through.
     *
     * This is the one that matters. Returning the item unchanged would leave
     * the output key absent, and a downstream router would take its default
     * branch exactly as though the action had succeeded — the engine's onError
     * policy only ever sees failures that propagate out of execute().
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testFailedActionThrowsRatherThanPassingThrough(): void {
        $this->handler->method('handle')->willReturn(new ActionResult(false, 'task_store_unavailable'));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('task_store_unavailable');

        $this->node->execute([['json' => []]], $this->config(), []);

    }//end testFailedActionThrowsRatherThanPassingThrough()


    /**
     * A config missing a required key is rejected at EXECUTION, not only on save.
     *
     * validateConfig() runs when a flow is saved; a seeded or imported flow
     * reaches execute() without ever having been saved through the editor.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testUnvalidatedConfigIsRejectedAtExecution(): void {
        $this->handler->expects($this->never())->method('handle');

        $config = $this->config();
        unset($config['title']);

        $this->expectException(UnexpectedValueException::class);
        $this->node->execute([['json' => []]], $config, []);

    }//end testUnvalidatedConfigIsRejectedAtExecution()


    /**
     * validateConfig() names the key it is missing.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testValidateConfigNamesTheMissingKey(): void {
        $config = $this->config();
        unset($config['title']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('title');

        $this->node->validateConfig($config);

    }//end testValidateConfigNamesTheMissingKey()


    /**
     * Every item in the batch is acted on.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/automatic-actions-surface/spec.md
     */
    public function testEveryItemInTheBatchIsActedOn(): void {
        $this->handler->expects($this->exactly(3))->method('handle')
            ->willReturn(new ActionResult(true, null, []));

        $out = $this->node->execute(
            [['json' => ['id' => 'a']], ['json' => ['id' => 'b']], ['json' => ['id' => 'c']]],
            $this->config(),
            []
        );

        $this->assertCount(3, $out);

    }//end testEveryItemInTheBatchIsActedOn()


}//end class
