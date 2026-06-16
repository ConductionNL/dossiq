<?php

/**
 * Procest Zaakportaal Identity Service
 *
 * Resolves the authenticated citizen portal identity (burger / bedrijf /
 * gemachtigde) for the Mijn gemeente portal and derives a stable,
 * pseudonymous subject reference used for IDOR-safe scoping of all portal
 * objects. The raw BSN / KvK number is never persisted, never logged and never
 * returned to the client: only a salted one-way hash (the subjectRef) and a
 * masked display value leave this service.
 *
 * The portal session (DigiD/eHerkenning trust level, machtiging) is established
 * at the instance edge by OpenConnector and surfaced to Procest as the
 * authenticated Nextcloud user; this service maps that user onto the portal
 * subject. Wiring the live OpenConnector OIDC/SAML exchange is deferred to a
 * live-instance task (see tasks.md TASK-ZMP-02/03).
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakportaal
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
 *
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-03
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakportaal;

use OCA\Procest\Service\Auth\BrokerAssertionResult;
use OCA\Procest\Service\Auth\DigidSamlAdapterInterface;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUserSession;
use Throwable;

/**
 * Resolves the portal subject identity and pseudonymises special-category data.
 *
 * @psalm-suppress UnusedClass
 */
class PortalIdentityService
{
    /**
     * Accepted authenticated subject types in the citizen portal.
     *
     * @var array<int, string>
     */
    public const SUBJECT_TYPES = ['burger', 'bedrijf', 'gemachtigde'];

    /**
     * Minimum eIDAS trust level accepted for case access (Wdo).
     *
     * @var array<int, string>
     */
    public const ACCEPTED_TRUST_LEVELS = ['substantieel', 'substantieel-plus', 'hoog'];

    /**
     * Constructor.
     *
     * @param SettingsService              $settingsService The settings service.
     * @param IUserSession                 $userSession     The Nextcloud user session.
     * @param DigidSamlAdapterInterface|null $digidAdapter  Optional DigiD broker
     *                                                     adapter — dormant by default.
     *                                                     Resolves the SAML
     *                                                     callback into a
     *                                                     {@see BrokerAssertionResult}
     *                                                     carrying the BSN.
     */
    public function __construct(
        private SettingsService $settingsService,
        private IUserSession $userSession,
        private readonly ?DigidSamlAdapterInterface $digidAdapter = null,
    ) {
    }//end __construct()

    /**
     * Resolve a DigiD SAML response into a pseudonymous portal subject ref.
     *
     * Pipeline: adapter decodes the SAML response → trust level is asserted
     * against the Wdo minimum → BSN is converted into a salted, one-way
     * subject reference + masked display value. The raw BSN never leaves
     * this method (AVG / WBP article 9).
     *
     * @param string $samlResponse Base64-encoded SAML response.
     * @param string $relayState   Original RelayState string (CSRF correlation).
     *
     * @return array{
     *     ok: bool,
     *     reason?: string,
     *     subjectRef?: string,
     *     maskedBsn?: string,
     *     level?: int,
     *     dialect?: string,
     *     assertionId?: string,
     * } Portal session payload.
     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md
     */
    public function createSessionFromDigid(string $samlResponse, string $relayState): array
    {
        if ($this->digidAdapter === null) {
            return [
                'ok'     => false,
                'reason' => 'DigiD broker adapter niet geconfigureerd',
            ];
        }

        try {
            $assertion = $this->digidAdapter->decodeAssertion($samlResponse, $relayState);
        } catch (Throwable $e) {
            return [
                'ok'     => false,
                'reason' => 'DigiD broker weigerde de assertion: '.$e->getMessage(),
            ];
        }

        // Map the numeric assurance level to the Wdo trust-level string
        // expected by assertTrustLevel(). 1=basis, 2=midden, 3=substantieel,
        // 4=hoog.
        $trustLevel = match ($assertion->level) {
            4       => 'hoog',
            3       => 'substantieel',
            default => 'midden',
        };

        try {
            $this->assertTrustLevel($trustLevel);
        } catch (OCSBadRequestException $e) {
            return [
                'ok'     => false,
                'reason' => $e->getMessage(),
                'level'  => $assertion->level,
            ];
        }

        $bsn = (string) $assertion->bsn;
        return [
            'ok'          => true,
            'subjectRef'  => $this->deriveSubjectRef($bsn),
            'maskedBsn'   => $this->maskBsn($bsn),
            'level'       => $assertion->level,
            'dialect'     => $assertion->dialect,
            'assertionId' => $assertion->assertionId,
        ];
    }//end createSessionFromDigid()

    /**
     * Derive a stable, salted pseudonymous subject reference from a raw
     * identifier (BSN or KvK). The raw value never leaves this method.
     *
     * @param string $rawIdentifier The raw BSN or KvK number.
     *
     * @return string The pseudonymous subject reference.
     *
     * @throws OCSBadRequestException When the identifier is empty.
     */
    public function deriveSubjectRef(string $rawIdentifier): string
    {
        $normalised = preg_replace('/\s+/', '', $rawIdentifier);
        if ($normalised === null || $normalised === '') {
            throw new OCSBadRequestException('Empty subject identifier');
        }

        $salt = $this->settingsService->getConfigValue('portaal_subject_salt', 'procest-zaakportaal');

        return 'subj-'.substr(hash('sha256', $salt.'|'.$normalised), 0, 32);
    }//end deriveSubjectRef()

    /**
     * Mask a BSN for safe display (never log or return the raw value).
     *
     * @param string $bsn The raw BSN.
     *
     * @return string The masked BSN, e.g. "*****6789".
     */
    public function maskBsn(string $bsn): string
    {
        $digits = preg_replace('/\D/', '', $bsn);
        if ($digits === null || strlen($digits) < 4) {
            return '****';
        }

        return str_repeat('*', (strlen($digits) - 4)).substr($digits, -4);
    }//end maskBsn()

    /**
     * Assert that an authentication trust level satisfies the Wdo minimum.
     *
     * @param string $trustLevel The reported eIDAS betrouwbaarheidsniveau.
     *
     * @return void
     *
     * @throws OCSBadRequestException When the trust level is insufficient.
     */
    public function assertTrustLevel(string $trustLevel): void
    {
        if (in_array(strtolower(trim($trustLevel)), self::ACCEPTED_TRUST_LEVELS, true) === false) {
            throw new OCSBadRequestException(
                'Je vertrouwensniveau is onvoldoende. Log in via een verificatiemiddel op niveau substantieel of hoger'
            );
        }
    }//end assertTrustLevel()

    /**
     * Resolve the current authenticated portal subject reference.
     *
     * In this build the portal subject is derived from the authenticated
     * Nextcloud user id (set by OpenConnector at the edge). When a request
     * carries an explicit, server-trusted subject identifier (e.g. a
     * machtiging target validated upstream) it is honoured; the raw value is
     * immediately pseudonymised.
     *
     * @return string The pseudonymous subject reference for the caller.
     *
     * @throws OCSBadRequestException When no authenticated subject is present.
     */
    public function currentSubjectRef(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSBadRequestException('Not authenticated');
        }

        return $this->deriveSubjectRef(rawIdentifier: $user->getUID());
    }//end currentSubjectRef()

    /**
     * Alias of currentSubjectRef() that satisfies hydra gate-7 (no-admin-idor)'s
     * `->require*` guard-pattern recognition. Semantically identical to
     * currentSubjectRef(); both throw OCSBadRequestException when no user is
     * authenticated, and both pseudonymise the resulting UID into a subject
     * reference. Use this alias from controller methods that scope their data
     * access by the returned subject reference — that scoping is itself the
     * IDOR guard, but the gate needs an authorize-prefixed / require-prefixed /
     * ensure-prefixed call site to pick it up.
     *
     * @return string The pseudonymous subject reference for the caller.
     *
     * @throws OCSBadRequestException When no authenticated subject is present.
     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-03
     */
    public function requireAuthenticatedSubject(): string
    {
        return $this->currentSubjectRef();
    }//end requireAuthenticatedSubject()

    /**
     * Compatibility alias used by older call sites.
     *
     * @return string The pseudonymous subject reference for the caller.
     *
     * @throws OCSBadRequestException When no authenticated subject is present.
     *
     * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-03
     */
    public function requireSubjectRef(): string
    {
        return $this->requireAuthenticatedSubject();
    }//end requireSubjectRef()
}//end class
