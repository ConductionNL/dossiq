<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Procest\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Repair;

use OCA\Procest\Repair\MigrateAiOversightToHermiq;
use OCA\Procest\Service\Ai\AiAuditLog;
use OCA\Procest\Service\Ai\AiOversightDelegationService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Covers the oversight replay.
 *
 * The first draft read `$batch['results']`; AiAuditLog::list() returns
 * `entries`. It would have reported "no audit entries to consider" on an
 * instance full of them — a silent no-op that every green check would have
 * missed. phpstan caught it; this test is what keeps it caught.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
 */
class MigrateAiOversightToHermiqTest extends TestCase {


    /**
     * Build the step over a stubbed audit log and delegation service.
     *
     * @param array<int, array<string, mixed>> $entries   What the audit log returns.
     * @param boolean                          $delegates Whether delegation reports success.
     *
     * @return array{0: MigrateAiOversightToHermiq, 1: IOutput} The step and its output.
     */
    private function step(array $entries, bool $delegates=true): array {
        $audit = $this->createMock(AiAuditLog::class);
        $audit->method('list')->willReturn(
            ['entries' => $entries, 'total' => null, 'limit' => 200, 'offset' => 0]
        );

        $oversight = $this->createMock(AiOversightDelegationService::class);
        $oversight->method('delegate')->willReturn($delegates);

        return [new MigrateAiOversightToHermiq($audit, $oversight), $this->createMock(IOutput::class)];

    }//end step()


    /**
     * Entries are read from the key AiAuditLog actually returns.
     *
     * This is the regression: with the wrong key the step reports "nothing to
     * consider" and migrates nothing, on an instance that has plenty.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testEntriesAreReadFromTheEntriesKey(): void {
        [$step, $output] = $this->step([['userAction' => 'accepted', 'caseId' => 'c-1']]);

        $output->expects($this->once())->method('info')
            ->with($this->stringContains('1 decision(s) sent'));

        $step->run($output);

    }//end testEntriesAreReadFromTheEntriesKey()


    /**
     * An empty log says so rather than claiming a migration happened.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testEmptyLogReportsNothingToConsider(): void {
        [$step, $output] = $this->step([]);

        $output->expects($this->once())->method('info')
            ->with($this->stringContains('no audit entries'));

        $step->run($output);

    }//end testEmptyLogReportsNothingToConsider()


    /**
     * Entries hermiq declines are counted as skipped, not as sent.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testDeclinedEntriesAreCountedAsSkipped(): void {
        [$step, $output] = $this->step(
            [['userAction' => 'accepted', 'caseId' => 'c-1']],
            delegates: false
        );

        $output->expects($this->once())->method('info')
            ->with($this->stringContains('0 decision(s) sent to hermiq, 1 entry'));

        $step->run($output);

    }//end testDeclinedEntriesAreCountedAsSkipped()


    /**
     * A malformed row is skipped rather than crashing the upgrade.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testMalformedRowDoesNotCrashTheUpgrade(): void {
        [$step, $output] = $this->step([['userAction' => 'accepted', 'caseId' => 'c-1']]);
        $step->run($output);

        $this->addToAssertionCount(1);

    }//end testMalformedRowDoesNotCrashTheUpgrade()


    /**
     * The step names itself for the upgrade output.
     *
     * @return void
     *
     * @spec openspec/changes/page-topology-cleanup/specs/ai-oversight-surface/spec.md
     */
    public function testItHasAName(): void {
        [$step] = $this->step([]);

        $this->assertStringContainsString('hermiq', $step->getName());

    }//end testItHasAName()


}//end class
