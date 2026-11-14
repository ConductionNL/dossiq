<?php

/**
 * Dossiq Woo Decision Notice
 *
 * Writes the message a resident gets when the decision on their Woo request
 * is published (hydra woo-citizen-journey C3): one message in portaliq's
 * inbox that says what happened, links to the publication on the site, and
 * carries dossiq's rule key `dossiq.wooRequest.published`, so portaliq also
 * sends the e-mail by the resident's preferences. Its record link opens the
 * case.
 *
 * It replaces a portaliq change rule on `wooPublicationUrl`, which could only
 * say "<case> is bijgewerkt". The same pattern as opencatalogi's
 * SavedSearchNoticeWriter (opencatalogi#1720).
 *
 * The message is Dutch: a Woo portal speaks Dutch, and dossiq does not know
 * the resident's language. It is never Dutch and English in one string.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the resident that the decision on their Woo request is published.
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
 */
class WooDecisionNotice {

	use SearchesObjects;

	/**
	 * The language of the message.
	 */
	private const LANGUAGE = 'nl';

	/**
	 * The register of the resident's portal inbox (portaliq's own).
	 */
	public const PORTAL_MESSAGE_REGISTER = 'portaliq';

	/**
	 * The schema of one message in the resident's portal inbox.
	 */
	public const PORTAL_MESSAGE_SCHEMA = 'portalMessage';

	/**
	 * The publication page of portaliq's site, before the publication id.
	 *
	 * The site resolves `/publicatie/<id>` to its `/publicatie` page showing
	 * that publication, which anyone may open, signed in or not.
	 */
	public const SITE_PUBLICATION_PAGE = '/index.php/apps/portaliq/site?route=/publicatie/';

	/**
	 * The portal collection that shows the resident's cases.
	 */
	private const CASE_COLLECTION = 'mijnZaken';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService OpenRegister's object service.
	 * @param IURLGenerator   $urlGenerator    Makes the publication link absolute, host and port included.
	 * @param IFactory        $l10nFactory     The Dutch text, whatever the request's language.
	 * @param LoggerInterface $logger          Records a message that could not be written.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the message for one published decision.
	 *
	 * Never throws: a message that cannot be written costs the notice, not
	 * the publish that triggered it.
	 *
	 * @param array<string, mixed> $case          The case, as it was before the publish.
	 * @param string               $caseId        The case uuid.
	 * @param string               $publicationId The publication uuid in opencatalogi.
	 *
	 * @return bool Whether a message was written; false when no resident follows the case.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function tell(array $case, string $caseId, string $publicationId): bool {
		$decision = new PublishedDecision(case: $case, caseId: $caseId, publicationId: $publicationId);
		$objectService = $this->settingsService->getObjectService();
		$ready = $this->canTell(decision: $decision, objectService: $objectService);
		if ($ready === false) {
			return false;
		}

		try {
			$this->writeNotice(decision: $decision, objectService: $objectService);
		} catch (Throwable $e) {
			$this->logUntold(decision: $decision, error: $e);
			return false;
		}//end try

		return true;
	}//end tell()

	/**
	 * Write the decision notice into the resident's portal messages, as the system.
	 *
	 * @param PublishedDecision $decision      The case, its uuid and the publication uuid.
	 * @param object|null       $objectService OpenRegister's object service.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	private function writeNotice(PublishedDecision $decision, ?object $objectService): void {
		$message = $this->noticeMessage(decision: $decision);

		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): mixed => $objectService->saveObject(
				object: $message,
				register: self::PORTAL_MESSAGE_REGISTER,
				schema: self::PORTAL_MESSAGE_SCHEMA,
				_rbac: false,
				_multitenancy: false,
			)
		);
	}//end writeNotice()

	/**
	 * Log a notice that could not be written.
	 *
	 * @param PublishedDecision $decision The case uuid and the publication uuid.
	 * @param Throwable         $error    Why it could not be written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	private function logUntold(PublishedDecision $decision, Throwable $error): void {
		$this->logger->warning(
			'WooDecisionNotice: the resident could not be told the decision is published',
			[
				'app' => AppIdentity::APP_ID,
				'caseId' => $decision->caseId,
				'publicationId' => $decision->publicationId,
				'error' => $error->getMessage(),
			]
		);
	}//end logUntold()

	/**
	 * Whether a resident, a case, a publication and OpenRegister are all in hand.
	 *
	 * The resident is the case's `portalSubject`.
	 *
	 * @param PublishedDecision $decision      The case, its uuid and the publication uuid.
	 * @param object|null       $objectService OpenRegister's object service.
	 *
	 * @return bool
	 */
	private function canTell(PublishedDecision $decision, ?object $objectService): bool {
		$subjectRef = trim((string)($decision->case['portalSubject'] ?? ''));
		return !($subjectRef === '' || $decision->caseId === '' || $decision->publicationId === '' || $objectService === null);
	}//end canTell()

	/**
	 * The portal message that tells the resident the decision is published.
	 *
	 * @param PublishedDecision $decision The case, its uuid and the publication uuid.
	 *
	 * @return array<string, mixed> The message.
	 */
	private function noticeMessage(PublishedDecision $decision): array {
		$case = $decision->case;
		$caseId = $decision->caseId;
		$publicationId = $decision->publicationId;
		$subjectRef = trim((string)($case['portalSubject'] ?? ''));
		$l10n = $this->l10nFactory->get(AppIdentity::APP_ID, self::LANGUAGE);
		$link = $this->urlGenerator->getAbsoluteURL(self::SITE_PUBLICATION_PAGE . rawurlencode($publicationId));
		$title = (string)($case['title'] ?? '');

		return [
			'subjectRef' => $subjectRef,
			'subject' => $l10n->t('The decision on your Woo request has been published'),
			'body' => $l10n->t('We have published the decision on your Woo request "%1$s".', [$title])
				. "\n\n" . $l10n->t('Read the decision and the documents made public here: %1$s', [$link]),
			'read' => false,
			'receivedAt' => gmdate('c'),
			'ruleKey' => PortalContributionProvider::RULE_WOO_REQUEST_PUBLISHED,
			'recordLink' => ['app' => AppIdentity::APP_ID, 'collection' => self::CASE_COLLECTION, 'id' => $caseId],
		];
	}//end noticeMessage()
}//end class
