<?php

/**
 * Dossiq Filinq Merge Client.
 *
 * The one place this app hands files to filinq to merge into one PDF. Filinq
 * owns the conversion and the merge, and reads every input as the person at
 * the keyboard, so a file they may not read is refused there. Filinq's
 * service is resolved through {@see FleetAppId}, because the app id and the
 * namespace moved when docudesk became filinq.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/documents-merge-pdf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Hands a case's files to filinq's merge.
 *
 * @spec openspec/specs/documents-merge-pdf/spec.md#requirement-a-cases-documents-are-merged-into-one-pdf-by-filinq
 */
class FilinqMergeClient {

	/**
	 * Filinq's merge service, below its app namespace root.
	 *
	 * @var string
	 */
	private const MERGE_SERVICE = 'Service\DocumentMergeService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves filinq's services across the rename.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Merge the files, in this order, into one PDF beside the first.
	 *
	 * @param list<int> $fileIds The Nextcloud file ids.
	 * @param string    $name    The PDF's name, '' for filinq's own.
	 * @param string    $caseId  The case the merge was started from.
	 *
	 * @return array<string, mixed> Filinq's finished job (`status`, `resultFileId`).
	 *
	 * @throws RuntimeException When filinq is absent (`filinq_unavailable`) or refuses.
	 *
	 * @spec openspec/specs/documents-merge-pdf/spec.md#requirement-a-cases-documents-are-merged-into-one-pdf-by-filinq
	 */
	public function merge(array $fileIds, string $name, string $caseId): array {
		$service = FleetAppId::getService($this->container, 'filinq', self::MERGE_SERVICE);
		if ($service === null) {
			throw new RuntimeException('filinq_unavailable: '.self::MERGE_SERVICE.' did not resolve');
		}

		$inputs = array_map(static fn (int $fileId): array => ['fileId' => $fileId, 'label' => ''], $fileIds);
		$options = [];
		if (trim(string: $name) !== '') {
			$options['name'] = $name;
		}

		return (array)$service->merge($inputs, $options, ['register' => 'dossiq', 'schema' => 'case', 'id' => $caseId]);
	}//end merge()
}//end class
