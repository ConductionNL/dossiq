<?php

/**
 * Dossiq Platform Data Subject Rights.
 *
 * The one door this app opens onto OpenRegister's data subject rights
 * capability, and the reason it is a door rather than an implementation is
 * ADR-047: the AVG erasure, the pseudonymisation and the subject export are
 * platform capabilities, and an app that builds its own builds a second answer
 * to a question that must have one.
 *
 * So there is no erasure logic below this line. Every method here is a call
 * into `OCA\OpenRegister\Service\Gdpr`, and the shapes that come back are the
 * platform's own: `report.counts`, `report.items`, `report.protected` and the
 * `digest` on a preview, and `{destroyed, pseudonymised, withheld, refused,
 * failed, complete}` on a run. Dossiq stores them and reads them. It does not
 * recompute them, and it does not summarise them into a number a handler
 * cannot take back to the data subject.
 *
 * 🔑 A REFUSAL KEEPS THE PLATFORM'S OWN RULE NAME. OpenRegister refuses with
 * `erasure-preview-unknown`, `erasure-not-approved`, `erasure-already-run` and
 * `erasure-preview-stale`, and each of the four means something different to
 * the handler in front of the case. Collapsing them into "the erasure failed"
 * would leave a handler retrying a stale preview for ever, because the one
 * thing that fixes it, taking the preview again, is exactly what the generic
 * sentence does not say. The rule and the sentence are carried across
 * unchanged, and only the exception type changes.
 *
 * WHY THE SERVICES ARE RESOLVED BY NAME. OpenRegister is a hard dependency of
 * this app, but its GDPR services arrived in 0.2.x and an instance running an
 * older build has the app and not the services. Resolving by name lets
 * {@see isAvailable()} answer that honestly instead of the container throwing
 * on a constructor, which is the difference between a case page that says the
 * platform cannot do this yet and a case page that is a 500.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Gdpr
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
 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Gdpr;

use OCA\Dossiq\Exception\RefusedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every data subject right dossiq offers, performed by OpenRegister.
 *
 * @psalm-suppress UnusedClass Injected into DataSubjectRequestCase and the controller.
 *
 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
 */
class PlatformDataSubjectRights {

	/**
	 * OpenRegister's erasure preview computation.
	 *
	 * @var string
	 */
	private const PREVIEW_SERVICE = 'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService';

	/**
	 * OpenRegister's record of a preview, its approval and its consumption.
	 *
	 * @var string
	 */
	private const PREVIEW_STORE = 'OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore';

	/**
	 * OpenRegister's erasure run.
	 *
	 * @var string
	 */
	private const RUNNER_SERVICE = 'OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner';

	/**
	 * OpenRegister's subject export.
	 *
	 * @var string
	 */
	private const EXPORT_SERVICE = 'OCA\OpenRegister\Service\Gdpr\Export\SubjectExportService';

	/**
	 * The platform's refusal, whose rule and sentence are carried across.
	 *
	 * @var string
	 */
	private const REFUSAL = 'OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException';

	/**
	 * The erase mode that replaces the subject's values and keeps the object.
	 *
	 * Named here rather than read off the platform's constant because it is
	 * also the value an administrator writes on a case type, and a default
	 * that moves when the platform renames a constant is a default that
	 * changes what a case erases without anybody editing the case.
	 *
	 * @var string
	 */
	public const MODE_PSEUDONYMISE = 'pseudonymise';

	/**
	 * The erase mode that removes the whole owning object.
	 *
	 * @var string
	 */
	public const MODE_WHOLE_OBJECT = 'whole-object';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's GDPR services.
	 * @param LoggerInterface    $logger    Structured logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance's OpenRegister can do any of this.
	 *
	 * @return bool True when the platform's erasure services resolve.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function isAvailable(): bool {
		return $this->container->has(self::PREVIEW_SERVICE)
			&& $this->container->has(self::PREVIEW_STORE);
	}//end isAvailable()

	/**
	 * Ask the platform what an erasure would touch, and record the answer.
	 *
	 * The preview WRITES NOTHING to the objects it counts. What it does write
	 * is the preview row itself, which is what the approval and the run both
	 * name, so a preview that was never recorded cannot be approved by
	 * accident.
	 *
	 * @param string      $subject   The identifier the PII index holds for this person.
	 * @param string|null $type      Optional identifier kind, or null for every kind.
	 * @param string      $eraseMode `pseudonymise` or `whole-object`.
	 * @param string|null $requestId The case this preview answers, when there is one.
	 *
	 * @return array<string, mixed> The recorded preview: counts, items, protected, digest.
	 *
	 * @throws RefusedException When the platform is absent or refuses.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function previewErasure(
		string $subject,
		?string $type,
		string $eraseMode,
		?string $requestId,
	): array {
		$previewService = $this->service(name: self::PREVIEW_SERVICE);
		$store = $this->service(name: self::PREVIEW_STORE);

		return $this->guarded(call: function () use ($previewService, $store, $subject, $type, $eraseMode, $requestId) {
			$preview = $previewService->preview(
				subjectId: $subject,
				type: $type,
				eraseMode: $this->knownMode(mode: $eraseMode),
			);

			return $store->record(preview: $preview, requestId: $requestId)->jsonSerialize();
		});
	}//end previewErasure()

	/**
	 * Read one recorded preview back.
	 *
	 * @param string $previewId The preview uuid.
	 *
	 * @return array<string, mixed> The preview.
	 *
	 * @throws RefusedException When the platform is absent or does not know it.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function preview(string $previewId): array {
		$store = $this->service(name: self::PREVIEW_STORE);

		return $this->guarded(call: static fn (): array => $store->load(uuid: $previewId)->jsonSerialize());
	}//end preview()

	/**
	 * Approve a recorded preview on the platform.
	 *
	 * This is the platform's half of the approval. The half that matters to a
	 * handler, that the approver is not the preparer, is the transition on the
	 * case, because only dossiq knows which act prepared it.
	 *
	 * @param string $previewId The preview uuid.
	 *
	 * @return array<string, mixed> The approved preview.
	 *
	 * @throws RefusedException When the platform refuses the approval.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function approvePreview(string $previewId): array {
		$store = $this->service(name: self::PREVIEW_STORE);

		return $this->guarded(call: static fn (): array => $store->approve(uuid: $previewId)->jsonSerialize());
	}//end approvePreview()

	/**
	 * Run an approved preview, through the platform's recorded destruction.
	 *
	 * @param string $previewId The preview uuid.
	 *
	 * @return array<string, mixed> `{destroyed, pseudonymised, withheld, refused, failed, complete}`.
	 *
	 * @throws RefusedException When the preview is unapproved, spent or stale.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function runErasure(string $previewId): array {
		$store = $this->service(name: self::PREVIEW_STORE);
		$runner = $this->service(name: self::RUNNER_SERVICE);

		return $this->guarded(call: static function () use ($store, $runner, $previewId): array {
			$record = $store->requireRunnable(uuid: $previewId);
			$outcome = $runner->run(record: $record);
			$store->consume(preview: $record, outcome: $outcome);

			return $outcome;
		});
	}//end runErasure()

	/**
	 * Ask the platform to produce this subject's own machine readable export.
	 *
	 * @param string      $subject   The identifier the PII index holds.
	 * @param string|null $type      Optional identifier kind.
	 * @param string|null $requestId The case this export answers.
	 *
	 * @return array<string, mixed> The export record, including `downloadable` and `expiresAt`.
	 *
	 * @throws RefusedException When the platform is absent or refuses.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function requestExport(string $subject, ?string $type, ?string $requestId): array {
		$service = $this->service(name: self::EXPORT_SERVICE);

		return $this->guarded(
			call: static fn (): array => $service->request(
				subject: $subject,
				type: $type,
				requestId: $requestId,
			)->jsonSerialize()
		);
	}//end requestExport()

	/**
	 * Read one export's state back, including whether it can still be taken.
	 *
	 * 🔑 `downloadable` IS THE PLATFORM'S ANSWER, NOT A DATE COMPARISON HERE.
	 * The export is downloadable only when it is ready AND unexpired, and the
	 * platform decides both. A case that compared `expiresAt` to its own clock
	 * would offer a link for a file that is still being assembled.
	 *
	 * @param string $exportId The export uuid.
	 *
	 * @return array<string, mixed> The export record, or an empty array when it is gone.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	public function export(string $exportId): array {
		$service = $this->service(name: self::EXPORT_SERVICE);

		return $this->guarded(call: static function () use ($service, $exportId): array {
			$export = $service->load(uuid: $exportId);
			if ($export === null) {
				return [];
			}

			return $export->jsonSerialize();
		});
	}//end export()

	/**
	 * Resolve one platform service, or refuse in a way a case page can show.
	 *
	 * @param string $name The fully qualified service name.
	 *
	 * @return object The service.
	 *
	 * @throws RefusedException When this instance's OpenRegister does not have it.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function service(string $name): object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			$service = null;
			$this->logger->warning(
				'OpenRegister data subject rights service did not resolve: ' . $name,
				['exception' => $e->getMessage()]
			);
		}

		if (is_object($service) === false) {
			throw new RefusedException(
				rule: 'data-subject-rights-unavailable',
				sentence: 'This OpenRegister cannot answer data subject requests yet. Ask an administrator to update it.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $service;
	}//end service()

	/**
	 * Run one platform call, translating its refusal without flattening it.
	 *
	 * @param callable():array<string, mixed> $call The platform call.
	 *
	 * @return array<string, mixed> Whatever the platform answered.
	 *
	 * @throws RefusedException When the platform refused, with its own rule.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function guarded(callable $call): array {
		try {
			return $call();
		} catch (Throwable $e) {
			if (is_a($e, self::REFUSAL) === true
				&& is_callable([$e, 'getRule']) === true
				&& is_callable([$e, 'getStatusCode']) === true
			) {
				// Called through `call_user_func` rather than `$e->getRule()`:
				// the class is resolved by name, so nothing in this app
				// type-hints it and the analysers see only a Throwable. A
				// `@var` claiming otherwise is a claim about a class they
				// cannot read, which is how a docblock starts disagreeing with
				// the code under it.
				throw new RefusedException(
					rule: (string)call_user_func([$e, 'getRule']),
					sentence: $e->getMessage(),
					status: (int)call_user_func([$e, 'getStatusCode']),
					previous: $e,
				);
			}

			$this->logger->error(
				'OpenRegister refused a data subject rights call for a reason it does not name',
				['exception' => $e->getMessage()]
			);

			throw new RefusedException(
				rule: 'data-subject-rights-failed',
				sentence: 'OpenRegister could not complete this request. Nothing was erased.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}//end try
	}//end guarded()

	/**
	 * The erase mode, or the safer of the two when the value is not one.
	 *
	 * Pseudonymisation rather than whole-object destruction is the fallback on
	 * purpose: an unrecognised mode is a configuration mistake, and the
	 * mistake that keeps the object is the one that can be corrected
	 * afterwards.
	 *
	 * @param string $mode The requested mode.
	 *
	 * @return string A mode the platform knows.
	 *
	 * @spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md
	 */
	private function knownMode(string $mode): string {
		if (trim($mode) === self::MODE_WHOLE_OBJECT) {
			return self::MODE_WHOLE_OBJECT;
		}

		return self::MODE_PSEUDONYMISE;
	}//end knownMode()
}//end class
