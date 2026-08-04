<?php

/**
 * Procest case-email attachment resolver.
 *
 * Owns the H5 security control for case email: an attachment reference
 * supplied by the caller is resolved through *that caller's own* user folder,
 * never through the raw filesystem. Resolving via IRootFolder::getUserFolder()
 * confines every reference to files the user can already reach and makes path
 * traversal outside that folder impossible.
 *
 * Split out of CaseEmailService so that service states the intent ("attach the
 * requested files") while the file-resolution and failure policy lives here.
 *
 * A reference that cannot be resolved or attached is logged and skipped rather
 * than aborting the send — a broken attachment must not swallow the message.
 *
 * @category Service
 * @package  OCA\Procest\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Email;

use OCA\Procest\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;

/**
 * Resolves case-email attachments from the calling user's own folder.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseEmailAttachmentResolver
{
    /**
     * Constructor.
     *
     * @param IRootFolder     $rootFolder  Root folder for user-file access
     * @param IUserSession    $userSession Current user session
     * @param LoggerInterface $logger      Logger
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Attach the requested files to a message, resolved from the caller's own folder.
     *
     * H5: resolving via the user folder restricts file access to the calling
     * user's own files and prevents path traversal outside that folder. A file
     * that cannot be resolved or attached is logged and skipped.
     *
     * @param IMessage      $message     The message under construction
     * @param array<string> $attachments File references to attach
     * @param string        $caseId      The case UUID (logging context)
     *
     * @return void
     *
     * @spec openspec/specs/case-management/spec.md
     */
    public function attach(IMessage $message, array $attachments, string $caseId): void
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser !== null && count($attachments) > 0) {
            $userFolder = $this->rootFolder->getUserFolder($currentUser->getUID());
            foreach ($attachments as $fileRef) {
                try {
                    $file      = $userFolder->get((string) $fileRef);
                    $localPath = $file->getStorage()->getLocalFile($file->getInternalPath());
                    if ($localPath !== null && $localPath !== false) {
                        $message->attachFile($localPath);
                    }
                } catch (NotFoundException $e) {
                    $this->logger->warning(
                        'Attachment file not found in user folder',
                        ['app' => Application::APP_ID, 'fileRef' => $fileRef, 'caseId' => $caseId]
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Failed to attach file',
                        ['app' => Application::APP_ID, 'fileRef' => $fileRef, 'error' => $e->getMessage()]
                    );
                }//end try
            }//end foreach
        }//end if
    }//end attach()
}//end class
