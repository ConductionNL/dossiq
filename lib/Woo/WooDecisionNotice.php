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
 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the resident that the decision on their Woo request is published.
 *
 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
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
	 * @param SettingsService $settingsService OpenRegister's object service.
	 * @param IURLGenerator   $urlGenerator    Makes the publication link absolute, host and port included.
	 * @param IFactory        $l10nFactory     The Dutch text, whatever the request's language.
	 * @param LoggerInterface $logger          Records a message that could not be written.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
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
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function tell(array $case, string $caseId, string $publicationId): bool {
		$subjectRef = trim((string)($case['portalSubject'] ?? ''));
		$objectService = $this->settingsService->getObjectService();
		if ($subjectRef === '' || $caseId === '' || $publicationId === '' || $objectService === null) {
			return false;
		}

		try {
			$l10n = $this->l10nFactory->get(Application::APP_ID, self::LANGUAGE);
			$link = $this->urlGenerator->getAbsoluteURL(self::SITE_PUBLICATION_PAGE . rawurlencode($publicationId));
			$title = (string)($case['title'] ?? '');

			$message = [
				'subjectRef' => $subjectRef,
				'subject' => $l10n->t('The decision on your Woo request has been published'),
				'body' => $l10n->t('We have published the decision on your Woo request "%1$s".', [$title])
					. "\n\n" . $l10n->t('Read the decision and the documents made public here: %1$s', [$link]),
				'read' => false,
				'receivedAt' => gmdate('c'),
				'ruleKey' => PortalContributionProvider::RULE_WOO_REQUEST_PUBLISHED,
				'recordLink' => ['app' => Application::APP_ID, 'collection' => self::CASE_COLLECTION, 'id' => $caseId],
			];

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
		} catch (Throwable $e) {
			$this->logger->warning(
				'WooDecisionNotice: the resident could not be told the decision is published',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'publicationId' => $publicationId, 'error' => $e->getMessage()]
			);
			return false;
		}//end try

		return true;
	}//end tell()
}//end class
