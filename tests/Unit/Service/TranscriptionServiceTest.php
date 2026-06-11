<?php

/**
 * TranscriptionService Unit Tests
 *
 * Covers queueing, transcription via injected TranscriberInterface, retry
 * backoff, manual fallback after MAX_RETRIES, and idempotent re-queue.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TranscriberInterface;
use OCA\Procest\Service\TranscriptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Procest\Service\TranscriptionService
 */
class TranscriptionServiceTest extends TestCase
{
    /**
     * @return void
     */
    public function testQueueRejectsNonVoiceMemo(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);

        $service->queue(['type' => 'photo', 'id' => 'fe-1']);
    }//end testQueueRejectsNonVoiceMemo()

    /**
     * @return void
     */
    public function testQueueRejectsTooLongVoiceMemo(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Voice memo too long');

        $service->queue([
            'type'            => 'voice_memo',
            'durationSeconds' => 600,
            'id'              => 'fe-1',
        ]);
    }//end testQueueRejectsTooLongVoiceMemo()

    /**
     * @return void
     */
    public function testQueueSetsStatusAndTimestamp(): void
    {
        $service = $this->makeService();

        $out = $service->queue([
            'type'            => 'voice_memo',
            'durationSeconds' => 60,
            'id'              => 'fe-1',
        ]);

        self::assertSame(TranscriptionService::STATUS_QUEUED, $out['transcriptionStatus']);
        self::assertNotEmpty($out['transcriptionQueuedAt']);
        self::assertSame(0, $out['transcriptionAttempts']);
    }//end testQueueSetsStatusAndTimestamp()

    /**
     * @return void
     */
    public function testQueueIsIdempotentForAlreadyQueued(): void
    {
        $service = $this->makeService();

        $in = [
            'type'                  => 'voice_memo',
            'durationSeconds'       => 60,
            'id'                    => 'fe-1',
            'transcriptionStatus'   => TranscriptionService::STATUS_DONE,
            'transcription'         => 'reeds verwerkt',
        ];
        self::assertSame($in, $service->queue($in));
    }//end testQueueIsIdempotentForAlreadyQueued()

    /**
     * @return void
     */
    public function testProcessSuccessfulTranscriptionMarksDone(): void
    {
        $transcriber = $this->createMock(TranscriberInterface::class);
        $transcriber->expects(self::once())
            ->method('transcribe')
            ->with(blobRef: 'blob://abc', language: 'nl')
            ->willReturn('Inspecteur ziet schade aan de gevel.');

        $service = $this->makeService(transcriber: $transcriber);

        $out = $service->process([
            'type'                => 'voice_memo',
            'id'                  => 'fe-1',
            'localBlobRef'        => 'blob://abc',
            'language'            => 'nl',
            'transcriptionStatus' => TranscriptionService::STATUS_QUEUED,
        ]);

        self::assertSame(TranscriptionService::STATUS_DONE, $out['transcriptionStatus']);
        self::assertSame('Inspecteur ziet schade aan de gevel.', $out['transcription']);
        self::assertNotEmpty($out['transcriptionCompletedAt']);
    }//end testProcessSuccessfulTranscriptionMarksDone()

    /**
     * @return void
     */
    public function testProcessFallsBackToManualWhenNoTranscriber(): void
    {
        $service = $this->makeService();

        $out = $service->process([
            'type'                => 'voice_memo',
            'id'                  => 'fe-1',
            'transcriptionStatus' => TranscriptionService::STATUS_QUEUED,
        ]);

        self::assertSame(TranscriptionService::STATUS_FALLBACK, $out['transcriptionStatus']);
        self::assertStringContainsString('No transcriber', $out['transcriptionNote']);
    }//end testProcessFallsBackToManualWhenNoTranscriber()

    /**
     * @return void
     */
    public function testProcessRequeuesOnRecoverableError(): void
    {
        $transcriber = $this->createMock(TranscriberInterface::class);
        $transcriber->method('transcribe')->willThrowException(new \RuntimeException('LLM 503'));

        $service = $this->makeService(transcriber: $transcriber);

        $out = $service->process([
            'type'                  => 'voice_memo',
            'id'                    => 'fe-1',
            'transcriptionStatus'   => TranscriptionService::STATUS_QUEUED,
            'transcriptionAttempts' => 0,
        ]);

        self::assertSame(TranscriptionService::STATUS_QUEUED, $out['transcriptionStatus']);
        self::assertSame(1, $out['transcriptionAttempts']);
        self::assertSame('LLM 503', $out['transcriptionLastError']);
    }//end testProcessRequeuesOnRecoverableError()

    /**
     * @return void
     */
    public function testProcessFallsBackToManualAfterMaxRetries(): void
    {
        $transcriber = $this->createMock(TranscriberInterface::class);
        $transcriber->method('transcribe')->willThrowException(new \RuntimeException('LLM 500'));

        $service = $this->makeService(transcriber: $transcriber);

        $out = $service->process([
            'type'                  => 'voice_memo',
            'id'                    => 'fe-1',
            'transcriptionStatus'   => TranscriptionService::STATUS_QUEUED,
            'transcriptionAttempts' => TranscriptionService::MAX_RETRIES - 1,
        ]);

        self::assertSame(TranscriptionService::STATUS_FALLBACK, $out['transcriptionStatus']);
        self::assertStringContainsString('Auto-transcription failed', $out['transcriptionNote']);
    }//end testProcessFallsBackToManualAfterMaxRetries()

    /**
     * @return void
     */
    public function testProcessSkipsNonQueuedRecords(): void
    {
        $transcriber = $this->createMock(TranscriberInterface::class);
        $transcriber->expects(self::never())->method('transcribe');

        $service = $this->makeService(transcriber: $transcriber);

        $in = [
            'type'                => 'voice_memo',
            'id'                  => 'fe-1',
            'transcriptionStatus' => TranscriptionService::STATUS_DONE,
            'transcription'       => 'klaar',
        ];
        self::assertSame($in, $service->process($in));
    }//end testProcessSkipsNonQueuedRecords()

    /**
     * @return void
     */
    public function testManualTranscribeMarksDoneWithNote(): void
    {
        $service = $this->makeService();

        $out = $service->manualTranscribe(
            ['type' => 'voice_memo', 'id' => 'fe-1'],
            'Handmatige tekst'
        );

        self::assertSame(TranscriptionService::STATUS_DONE, $out['transcriptionStatus']);
        self::assertSame('Handmatige tekst', $out['transcription']);
        self::assertSame('Manual transcription.', $out['transcriptionNote']);
    }//end testManualTranscribeMarksDoneWithNote()

    /**
     * @param TranscriberInterface|null $transcriber
     *
     * @return TranscriptionService
     */
    private function makeService(?TranscriberInterface $transcriber=null): TranscriptionService
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn(null);
        return new TranscriptionService(
            settingsService: $settings,
            logger: new NullLogger(),
            transcriber: $transcriber,
        );
    }//end makeService()
}//end class
