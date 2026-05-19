<?php

/**
 * Procest Advisory Body Service
 *
 * Registry service for internal departments and external advisory organizations
 * that can receive consultation requests.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Registry service for advisory bodies (internal departments and external organisations).
 *
 * @spec openspec/changes/consultation-management/tasks.md#task-3
 */
class AdvisoryBodyService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service
     * @param IMailer         $mailer          The mail sender
     * @param IURLGenerator   $urlGenerator    The URL generator
     * @param LoggerInterface $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IMailer $mailer,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Search advisory bodies by specialization tag or name.
     *
     * Each body is scored as follows:
     *   +10 for each specialization tag that contains $query (case-insensitive)
     *   +5  if the body name contains $query (case-insensitive)
     * Results are returned sorted by score descending.
     * An empty query returns all active bodies unsorted.
     *
     * @param string $query Search term
     *
     * @return array<int, array<string, mixed>> Scored and sorted advisory bodies
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function searchBySpecialization(string $query): array
    {
        $bodies = $this->listAll();

        if ($query === '') {
            return $bodies;
        }

        $lowerQuery = mb_strtolower($query);
        $scored     = [];

        foreach ($bodies as $body) {
            $score = 0;

            // +5 if name contains query.
            $name = mb_strtolower((string) ($body['name'] ?? ''));
            if ($name !== '' && str_contains($name, $lowerQuery) === true) {
                $score += 5;
            }

            // +10 per matching specialization tag.
            $tags = ($body['specializations'] ?? []);
            if (is_array($tags) === true) {
                foreach ($tags as $tag) {
                    $lowerTag = mb_strtolower((string) $tag);
                    if (str_contains($lowerTag, $lowerQuery) === true) {
                        $score += 10;
                    }
                }
            }

            if ($score > 0) {
                $scored[] = ['_score' => $score, 'body' => $body];
            }
        }//end foreach

        // Sort by score descending.
        usort(
                $scored,
                static function (array $a, array $b): int {
                    return $b['_score'] <=> $a['_score'];
                }
                );

        return array_map(static fn (array $item): array => $item['body'], $scored);
    }//end searchBySpecialization()

    /**
     * Fetch a single advisory body by UUID.
     *
     * @param string $id The advisory body UUID
     *
     * @return array<string, mixed>|null The advisory body record or null when not found
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function getAdvisoryBody(string $id): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advisory_body_schema');

        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $result = $objectService->findObject($register, $schema, $id);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to fetch advisory body: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return null;
        }

        return $this->normalizeResult(result: $result);
    }//end getAdvisoryBody()

    /**
     * List all active advisory bodies (active=true or missing active field).
     *
     * @return array<int, array<string, mixed>> Active advisory body records
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function listAll(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('advisory_body_schema');

        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        try {
            $results = $objectService->findObjects(
                register: $register,
                schema: $schema,
                params: ['active' => true, '_limit' => 100]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to list advisory bodies: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        if (is_array($results) === true) {
            return $results;
        }

        return [];
    }//end listAll()

    /**
     * Generate a cryptographically secure 32-byte hex token for an external consultation.
     *
     * The caller is responsible for storing the token on the consultation record.
     *
     * @param string $consultationId The consultation UUID (unused internally; provided for audit context)
     *
     * @return string A 64-character hex token
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function issueSecureToken(string $consultationId): string
    {
        return bin2hex(random_bytes(32));
    }//end issueSecureToken()

    /**
     * Send the initial consultation email to an external advisory body.
     *
     * Builds a plain-text email with a secure response link and dispatches it
     * via the Nextcloud mailer. Errors are logged but not re-thrown so that a
     * mail failure does not roll back the consultation record.
     *
     * @param array<string, mixed> $consultation The consultation record
     * @param array<string, mixed> $advisoryBody The external advisory body record
     * @param string               $token        The secure response token
     *
     * @return void
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function sendExternalConsultationEmail(
        array $consultation,
        array $advisoryBody,
        string $token,
    ): void {
        try {
            $toEmail   = (string) ($advisoryBody['email'] ?? '');
            $number    = (string) ($consultation['consultationNumber'] ?? '');
            $onderwerp = (string) ($consultation['onderwerp'] ?? '');
            $vraag     = (string) ($consultation['vraagstelling'] ?? '');
            $deadline  = (string) ($consultation['uiterlijkeReactiedatum'] ?? '');

            $responseLink = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkTo(
                    appName: '',
                    file: 'index.php/apps/procest/public/consultation/'.$token
                )
            );

            $subject = "Adviesaanvraag {$number}: {$onderwerp}";

            $body = implode(
                    "\n",
                    [
                        'Geachte,',
                        '',
                        'Er is een adviesaanvraag ingediend voor uw organisatie.',
                        '',
                        "Adviesaanvraag: {$number}",
                        "Onderwerp: {$onderwerp}",
                        "Vraagstelling: {$vraag}",
                        "Deadline: {$deadline}",
                        '',
                        "U kunt reageren via: {$responseLink}",
                        '',
                        'Met vriendelijke groet,',
                        'Procest',
                    ]
                    );

            $message = $this->mailer->createMessage();
            $message->setTo([$toEmail]);
            $message->setSubject($subject);
            $message->setPlainBody($body);

            $this->mailer->send($message);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to send external consultation email: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
        }//end try
    }//end sendExternalConsultationEmail()

    /**
     * Check that an external body has a non-empty email address configured.
     *
     * @param array<string, mixed> $body The advisory body record
     *
     * @return bool True when type=external and email is non-empty
     *
     * @spec openspec/changes/consultation-management/tasks.md#task-3
     */
    public function validateExternalBodyHasEmail(array $body): bool
    {
        $type  = (string) ($body['type'] ?? '');
        $email = (string) ($body['email'] ?? '');

        return $type === 'external' && $email !== '';
    }//end validateExternalBodyHasEmail()

    /**
     * Normalize an OpenRegister return value to a plain associative array.
     *
     * @param mixed $result The OpenRegister return value
     *
     * @return array<string, mixed>|null Normalized record or null when result is empty/null
     */
    private function normalizeResult(mixed $result): ?array
    {
        if (is_array($result) === true && empty($result) === false) {
            return $result;
        }

        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $data = $result->jsonSerialize();
            if (is_array($data) === true && empty($data) === false) {
                return $data;
            }
        }

        return null;
    }//end normalizeResult()
}//end class
