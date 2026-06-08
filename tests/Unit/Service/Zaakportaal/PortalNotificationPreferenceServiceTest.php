<?php

/**
 * PortalNotificationPreferenceService Unit Tests
 *
 * Exercises the pure decision core of the notification-preference service:
 * the statutory Berichtenbox-always-on rule, the email-change verification
 * flow (pending address, tokenised confirmation, expiry) and per-event toggles.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Zaakportaal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Zaakportaal;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Zaakportaal\PortalNotificationPreferenceService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PortalNotificationPreferenceService.
 *
 * @covers \OCA\Procest\Service\Zaakportaal\PortalNotificationPreferenceService
 */
class PortalNotificationPreferenceServiceTest extends TestCase
{

    private PortalNotificationPreferenceService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new PortalNotificationPreferenceService(
            $this->createMock(SettingsService::class),
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Berichtenbox can never be disabled, even when explicitly requested off.
     *
     * @return void
     */
    public function testBerichtenboxAlwaysStaysActive(): void
    {
        $existing = $this->service->defaults('subj-x');
        $patched  = $this->service->applyPatch($existing, ['berichtenboxActief' => false]);
        $this->assertTrue($patched['berichtenboxActief']);
    }//end testBerichtenboxAlwaysStaysActive()

    /**
     * Disabling email is honoured.
     *
     * @return void
     */
    public function testEmailCanBeDisabled(): void
    {
        $existing = $this->service->defaults('subj-x');
        $existing['emailActief'] = true;
        $patched = $this->service->applyPatch($existing, ['emailActief' => false]);
        $this->assertFalse($patched['emailActief']);
    }//end testEmailCanBeDisabled()

    /**
     * A new email address is held as pending (not active) until verified, and
     * the old address keeps receiving notifications meanwhile.
     *
     * @return void
     */
    public function testNewEmailStartsVerificationFlow(): void
    {
        $existing = $this->service->defaults('subj-x');
        $existing['emailAdres']        = 'old@example.nl';
        $existing['emailGeverifieerd'] = true;

        $patched = $this->service->applyPatch($existing, ['emailAdres' => 'new@example.nl'], '2026-04-15T10:00:00+00:00');

        $this->assertSame('old@example.nl', $patched['emailAdres'], 'old email stays active until verified');
        $this->assertSame('new@example.nl', $patched['pendingEmailAdres']);
        $this->assertNotSame('', $patched['pendingEmailToken']);
        $this->assertNotSame('', $patched['pendingEmailExpiresAt']);
    }//end testNewEmailStartsVerificationFlow()

    /**
     * An invalid email is rejected.
     *
     * @return void
     */
    public function testInvalidEmailRejected(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->applyPatch($this->service->defaults('subj-x'), ['emailAdres' => 'not-an-email']);
    }//end testInvalidEmailRejected()

    /**
     * Confirming with the correct token promotes the pending email.
     *
     * @return void
     */
    public function testConfirmEmailPromotesPending(): void
    {
        $existing = $this->service->defaults('subj-x');
        $existing['emailAdres'] = 'old@example.nl';
        $patched = $this->service->applyPatch($existing, ['emailAdres' => 'new@example.nl'], '2026-04-15T10:00:00+00:00');

        $confirmed = $this->service->confirmEmail($patched, $patched['pendingEmailToken'], '2026-04-16T10:00:00+00:00');

        $this->assertSame('new@example.nl', $confirmed['emailAdres']);
        $this->assertTrue($confirmed['emailGeverifieerd']);
        $this->assertSame('', $confirmed['pendingEmailToken']);
    }//end testConfirmEmailPromotesPending()

    /**
     * A wrong token is rejected.
     *
     * @return void
     */
    public function testConfirmEmailRejectsWrongToken(): void
    {
        $existing = $this->service->defaults('subj-x');
        $patched  = $this->service->applyPatch($existing, ['emailAdres' => 'new@example.nl'], '2026-04-15T10:00:00+00:00');

        $this->expectException(OCSBadRequestException::class);
        $this->service->confirmEmail($patched, 'wrong-token', '2026-04-16T10:00:00+00:00');
    }//end testConfirmEmailRejectsWrongToken()

    /**
     * An expired verification (>7 days) is rejected.
     *
     * @return void
     */
    public function testConfirmEmailRejectsExpired(): void
    {
        $existing = $this->service->defaults('subj-x');
        $patched  = $this->service->applyPatch($existing, ['emailAdres' => 'new@example.nl'], '2026-04-15T10:00:00+00:00');

        $this->expectException(OCSBadRequestException::class);
        // 8 days later -> past the 7-day TTL.
        $this->service->confirmEmail($patched, $patched['pendingEmailToken'], '2026-04-23T10:00:01+00:00');
    }//end testConfirmEmailRejectsExpired()

    /**
     * Per-event toggles are applied.
     *
     * @return void
     */
    public function testEventToggles(): void
    {
        $patched = $this->service->applyPatch($this->service->defaults('subj-x'), ['eventStatuswijziging' => false]);
        $this->assertFalse($patched['eventStatuswijziging']);
        $this->assertTrue($patched['eventBerichtVanBehandelaar']);
    }//end testEventToggles()
}//end class
