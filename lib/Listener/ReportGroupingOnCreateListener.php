<?php

/**
 * Queues the report grouping of a newly created case.
 *
 * Hermiq places each incoming report in a group of similar reports once, when
 * it arrives. This listener only decides that a case was created and hands the
 * rest to {@see ReportGroupingJob}: a slow or absent hermiq must never stop a
 * case being created, and whether the case type groups its reports at all is
 * read off the request, by the job.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\BackgroundJob\ReportGroupingJob;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Queues a ReportGroupingJob for every created case.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */
class ReportGroupingOnCreateListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IJobList                 $jobList      The job list.
	 * @param ObjectSchemaSlugResolver $slugResolver Resolves the schema slug of a payload.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly ObjectSchemaSlugResolver $slugResolver,
	) {
	}//end __construct()

	/**
	 * Queue the grouping of a created case.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $event->getObject()->jsonSerialize();

		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$caseId = (string)($payload['id'] ?? ($payload['uuid'] ?? ''));
		if ($caseId === '') {
			return;
		}

		$this->jobList->add(ReportGroupingJob::class, ['caseId' => $caseId]);
	}//end handle()
}//end class
