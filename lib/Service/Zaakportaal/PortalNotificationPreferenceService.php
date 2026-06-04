<?php

/**
 * Procest Zaakportaal Notification Preference Service
 *
 * Reads and writes a citizen's notification preferences (PortaalNotificatie-
 * Voorkeur) for the Mijn gemeente portal. The Berichtenbox channel is statutory
 * and can never be disabled. Changing the notification email starts a
 * verification flow: the new address is held as pending until the citizen
 * confirms it via a tokenised link, and the old address keeps receiving
 * notifications in the meantime. All reads/writes are scoped to the
 * authenticated subject reference (one record per subject, IDOR-safe).
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
 * @spec openspec/changes/zaakportaal-mijngemeente/tasks.md#TASK-ZMP-09
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakportaal;

use DateInterval;
use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;

/**
 * Manages citizen notification preferences with statutory guarantees.
 *
 * @psalm-suppress UnusedClass
 */
class PortalNotificationPreferenceService
{
    /**
     * Toggleable per-event preferences.
     *
     * @var array<int, string>
     */
    public const EVENT_FIELDS = [
        'eventStatuswijziging',
        'eventDocumentToegevoegd',
        'eventBerichtVanBehandelaar',
        'eventTermijnHerinnering',
    ];

    /**
     * Days a pending email verification stays valid.
     *
     * @var int
     */
    public const VERIFY_TTL_DAYS = 7;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Apply a preference patch to an existing record, enforcing the statutory
     * Berichtenbox-always-on rule and the email verification flow.
     *
     * This is the pure decision core (no persistence), extracted so it can be
     * unit-tested deterministically.
     *
     * @param array<string, mixed> $existing The current record.
     * @param array<string, mixed> $patch    The requested changes.
     * @param string               $now      The current instant (ISO), for TTL.
     *
     * @return array<string, mixed> The merged record.
     *
     * @throws OCSBadRequestException When the patched email is invalid.
     */
    public function applyPatch(array $existing, array $patch, string $now=''): array
    {
        $record = $existing;
        // Berichtenbox is statutory: force-on regardless of the request.
        $record['berichtenboxActief'] = true;

        if (array_key_exists('emailActief', $patch) === true) {
            $record['emailActief'] = (bool) $patch['emailActief'];
        }

        if (array_key_exists('smsActief', $patch) === true) {
            $record['smsActief'] = (bool) $patch['smsActief'];
        }

        if (array_key_exists('smsNummer', $patch) === true) {
            $record['smsNummer'] = trim((string) $patch['smsNummer']);
        }

        foreach (self::EVENT_FIELDS as $field) {
            if (array_key_exists($field, $patch) === true) {
                $record[$field] = (bool) $patch[$field];
            }
        }

        if (array_key_exists('emailAdres', $patch) === true) {
            $record = $this->startEmailChange(record: $record, newEmail: (string) $patch['emailAdres'], now: $now);
        }

        return $record;
    }//end applyPatch()

    /**
     * Start the email-change verification flow if the address actually changes.
     *
     * @param array<string, mixed> $record   The current record.
     * @param string               $newEmail The requested email address.
     * @param string               $now      The current instant (ISO).
     *
     * @return array<string, mixed> The record with a pending email set.
     *
     * @throws OCSBadRequestException When the email is invalid.
     */
    private function startEmailChange(array $record, string $newEmail, string $now): array
    {
        $newEmail = trim($newEmail);
        if ($newEmail === '') {
            return $record;
        }

        if (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new OCSBadRequestException('Ongeldig e-mailadres');
        }

        // No-op when unchanged and already verified.
        if ($newEmail === (string) ($record['emailAdres'] ?? '') && (bool) ($record['emailGeverifieerd'] ?? false) === true) {
            return $record;
        }

        $base = new DateTimeImmutable('now');
        if ($now !== '') {
            $base = new DateTimeImmutable($now);
        }

        $record['pendingEmailAdres']     = $newEmail;
        $record['pendingEmailToken']     = bin2hex(random_bytes(16));
        $record['pendingEmailExpiresAt'] = $base->add(new DateInterval('P'.self::VERIFY_TTL_DAYS.'D'))->format(DateTimeImmutable::ATOM);

        return $record;
    }//end startEmailChange()

    /**
     * Confirm a pending email change with a token.
     *
     * @param array<string, mixed> $record The current record.
     * @param string               $token  The verification token.
     * @param string               $now    The current instant (ISO).
     *
     * @return array<string, mixed> The record with the email promoted.
     *
     * @throws OCSBadRequestException When the token is invalid or expired.
     */
    public function confirmEmail(array $record, string $token, string $now=''): array
    {
        $pendingToken = (string) ($record['pendingEmailToken'] ?? '');
        $pendingEmail = (string) ($record['pendingEmailAdres'] ?? '');

        if ($pendingToken === '' || $pendingEmail === '') {
            throw new OCSBadRequestException('Geen openstaande e-mailverificatie');
        }

        if (hash_equals($pendingToken, $token) === false) {
            throw new OCSBadRequestException('Ongeldige verificatietoken');
        }

        $base = new DateTimeImmutable('now');
        if ($now !== '') {
            $base = new DateTimeImmutable($now);
        }

        $expires = (string) ($record['pendingEmailExpiresAt'] ?? '');
        if ($expires !== '' && new DateTimeImmutable($expires) < $base) {
            throw new OCSBadRequestException('Verificatielink is verlopen');
        }

        $record['emailAdres']            = $pendingEmail;
        $record['emailGeverifieerd']     = true;
        $record['emailActief']           = true;
        $record['pendingEmailAdres']     = '';
        $record['pendingEmailToken']     = '';
        $record['pendingEmailExpiresAt'] = '';

        return $record;
    }//end confirmEmail()

    /**
     * Retrieve (or default) the preference record for a subject.
     *
     * @param string $subjectRef The authenticated subject reference.
     *
     * @return array<string, mixed> The preference record.
     *
     * @throws OCSBadRequestException When storage is unavailable.
     */
    public function getForSubject(string $subjectRef): array
    {
        [$objectService, $register, $schema] = $this->resolve();

        try {
            $results = $objectService->findAll(
                [
                    'filters' => [
                        'register'   => (int) $register,
                        'schema'     => (int) $schema,
                        'subjectRef' => $subjectRef,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Zaakportaal: preference read failed', ['error' => $e->getMessage()]);
            throw new OCSBadRequestException('Could not retrieve preferences');
        }

        $existing = [];
        if (is_array($results) === true && count($results) > 0) {
            $existing = $this->toArray(value: reset($results));
        }

        if ($existing === []) {
            return $this->defaults(subjectRef: $subjectRef);
        }

        return $existing;
    }//end getForSubject()

    /**
     * Update the preference record for a subject from a patch.
     *
     * @param string               $subjectRef The authenticated subject reference.
     * @param array<string, mixed> $patch      The requested changes.
     *
     * @return array<string, mixed> The saved record.
     *
     * @throws OCSBadRequestException When validation or storage fails.
     */
    public function updateForSubject(string $subjectRef, array $patch): array
    {
        [$objectService, $register, $schema] = $this->resolve();

        $existing = $this->getForSubject(subjectRef: $subjectRef);
        $merged   = $this->applyPatch(existing: $existing, patch: $patch);
        $merged['subjectRef'] = $subjectRef;

        $id = (string) ($existing['id'] ?? ($existing['@self']['id'] ?? ''));

        $saved = $this->persist(objectService: $objectService, register: $register, schema: $schema, record: $merged, id: $id);

        $this->logger->info('Zaakportaal: preferences updated', ['subject' => $subjectRef]);

        return $this->toArray(value: $saved);
    }//end updateForSubject()

    /**
     * Persist a preference record, creating a new object or updating an
     * existing one when an id is known.
     *
     * @param object               $objectService The OpenRegister ObjectService.
     * @param string               $register      The register id.
     * @param string               $schema        The schema id.
     * @param array<string, mixed> $record        The record to persist.
     * @param string               $id            The existing object id ('' to create).
     *
     * @return mixed The saved object.
     */
    private function persist(object $objectService, string $register, string $schema, array $record, string $id): mixed
    {
        if ($id !== '') {
            return $objectService->saveObject($register, $schema, $record, $id);
        }

        return $objectService->saveObject($register, $schema, $record);
    }//end persist()

    /**
     * Default preference record for a subject with no stored preferences.
     *
     * @param string $subjectRef The subject reference.
     *
     * @return array<string, mixed> The defaults.
     */
    public function defaults(string $subjectRef): array
    {
        return [
            'subjectRef'                 => $subjectRef,
            'emailActief'                => false,
            'emailAdres'                 => '',
            'emailGeverifieerd'          => false,
            'berichtenboxActief'         => true,
            'smsActief'                  => false,
            'eventStatuswijziging'       => true,
            'eventDocumentToegevoegd'    => true,
            'eventBerichtVanBehandelaar' => true,
            'eventTermijnHerinnering'    => true,
        ];
    }//end defaults()

    /**
     * Resolve the ObjectService and register/schema identifiers.
     *
     * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
     *
     * @throws OCSBadRequestException When OpenRegister is unavailable.
     */
    private function resolve(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new OCSBadRequestException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('portaal_notificatie_voorkeur_schema');

        if ($register === '' || $schema === '') {
            throw new OCSBadRequestException('Portaal voorkeur schema is not configured');
        }

        return [$objectService, $register, $schema];
    }//end resolve()

    /**
     * Normalise an ObjectService result to a plain array.
     *
     * @param mixed $value The value to normalise.
     *
     * @return array<string, mixed> The normalised array.
     */
    private function toArray(mixed $value): array
    {
        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return [];
        }

        if (is_array($value) === true) {
            return $value;
        }

        return [];
    }//end toArray()
}//end class
