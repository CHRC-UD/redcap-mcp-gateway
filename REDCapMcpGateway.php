<?php

namespace REDCapMcpGateway;

use ExternalModules\AbstractExternalModule;

/**
 * REDCap-side API gateway for MCP tools.
 *
 * This module deliberately exposes small, read-only actions. MCP transport and
 * client configuration remain outside REDCap; this class is invoked through the
 * standard /api/ endpoint and therefore uses REDCap API-token authentication.
 */
class REDCapMcpGateway extends AbstractExternalModule
{
    /**
     * @param string $action
     * @param array<string,mixed> $payload
     * @param int|string|null $project_id
     * @param string|null $user_id
     * @param string $format
     * @param string $returnFormat
     * @param string $csvDelim
     * @return array<string,mixed>
     */
    public function redcap_module_api($action, $payload, $project_id, $user_id, $format, $returnFormat, $csvDelim)
    {
        if ($returnFormat !== 'json') {
            return $this->framework->apiErrorResponse('This action only supports returnFormat=json.', 400);
        }

        // A project-bound token is required. Do not let a super API token select
        // an arbitrary project through a request parameter.
        if (!is_numeric($project_id) || empty($user_id)) {
            return $this->framework->apiErrorResponse('A project-bound API token is required.', 403);
        }

        if (!$this->hasProjectDesignRights($user_id, (int) $project_id)) {
            return $this->framework->apiErrorResponse('Project Design/Setup rights are required for this action.', 403);
        }

        switch ($action) {
            case 'project-overview': return $this->projectOverview((int) $project_id);
            case 'project-schema':
                return $this->projectSchema((int) $project_id, $payload);
            case 'project-structure': return $this->projectStructure((int) $project_id);
            case 'list-reports': return $this->listReports((int) $project_id);
            case 'get-report': return $this->getReport((int) $project_id, $payload);
            case 'export-records': return $this->exportRecords((int) $project_id, $payload);
            case 'summarize-fields': return $this->summarizeFields((int) $project_id, $payload);
            case 'summarize-report': return $this->summarizeReport((int) $project_id, $payload);
            case 'aggregate-identifier-fields': return $this->aggregateIdentifierFields((int) $project_id, $payload);
            case 'aggregate-categorical-counts': return $this->aggregateCategoricalCounts((int) $project_id, $payload);
            case 'aggregate-checkbox-counts': return $this->aggregateCheckboxCounts((int) $project_id, $payload);
            case 'aggregate-numeric-distribution': return $this->aggregateNumericDistribution((int) $project_id, $payload);
            case 'aggregate-age-distribution': return $this->aggregateAgeDistribution((int) $project_id, $payload);
            case 'aggregate-date-distribution': return $this->aggregateDateDistribution((int) $project_id, $payload);
            case 'aggregate-completion-counts': return $this->aggregateCompletionCounts((int) $project_id, $payload);
            case 'aggregate-missingness': return $this->aggregateMissingness((int) $project_id, $payload);
            default:
                return $this->framework->apiErrorResponse('Unsupported MCP gateway action.', 404);
        }
    }

    private function projectOverview(int $projectId): array
    {
        $project = new \Project($projectId);
        return $this->framework->apiJsonResponse([
            'project_id' => $projectId,
            'title' => $project->project['app_title'] ?? '',
            'longitudinal' => !empty($project->longitudinal),
            'record_id_field' => $project->table_pk,
            'instruments' => $project->forms ?? [],
            'identifiers_allowed' => $this->identifiersAllowed($projectId),
        ]);
    }

    private function listReports(int $projectId): array
    {
        $blocked = $this->identifierFieldNames($projectId);
        $identifiersAllowed = $this->identifiersAllowed($projectId);
        $reports = [];
        foreach (\DataExport::getReports(null, [], [], $projectId) as $report) {
            $fields = is_array($report['fields'] ?? null) ? $report['fields'] : [];
            $excluded = $identifiersAllowed ? [] : $this->blockedFieldsInList($fields, $blocked);
            $reports[] = [
                'report_id' => (int) ($report['report_id'] ?? 0),
                'title' => $report['title'] ?? '',
                'fields' => $identifiersAllowed ? $fields : array_values(array_diff($fields, $excluded)),
                'excluded_identifier_fields' => $excluded,
                'filter_logic' => $report['limiter_logic'] ?? '',
            ];
        }
        return $this->framework->apiJsonResponse(['project_id' => $projectId, 'identifiers_allowed' => $identifiersAllowed, 'reports' => $reports]);
    }

    /** @param array<string,mixed> $payload */
    private function getReport(int $projectId, array $payload): array
    {
        $reportId = (int) ($payload['report-id'] ?? 0);
        if (!$reportId || !\DataExport::validateReportId($projectId, $reportId)) {
            return $this->framework->apiErrorResponse('report-id must identify a report in this project.', 400);
        }
        $identifiersAllowed = $this->identifiersAllowed($projectId);
        // Label headers cannot be safely mapped back to data-dictionary names,
        // so disable them while identifier suppression is active.
        $data = \REDCap::getReport($reportId, 'array', !empty($payload['labels']), $identifiersAllowed && !empty($payload['label-headers']));
        $offset = $this->nonNegativeInt($payload['offset'] ?? 0, 0);
        $limit = $this->boundedInt($payload['limit'] ?? 500, 500, 1, 1000);
        [$rows, $excluded] = $this->stripIdentifierColumns($projectId, array_values(is_array($data) ? $data : []), $identifiersAllowed);
        return $this->framework->apiJsonResponse([
            'project_id' => $projectId,
            'report_id' => $reportId,
            'identifiers_allowed' => $identifiersAllowed,
            'excluded_identifier_fields' => $excluded,
            'label_headers_applied' => $identifiersAllowed && !empty($payload['label-headers']),
            'total_rows' => count($rows),
            'offset' => $offset,
            'limit' => $limit,
            'data' => array_slice($rows, $offset, $limit),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function projectSchema(int $projectId, array $payload): array
    {
        $fields = $this->stringList($payload['fields'] ?? []);
        $forms = $this->stringList($payload['forms'] ?? []);

        $dictionary = \REDCap::getDataDictionary($projectId, 'array', false, $fields, $forms);

        return $this->framework->apiJsonResponse([
            'project_id' => $projectId,
            'identifiers_allowed' => $this->identifiersAllowed($projectId),
            'metadata' => $dictionary,
        ]);
    }

    private function projectStructure(int $projectId): array
    {
        $project = new \Project($projectId);
        $events = [];
        foreach ($project->eventInfo ?? [] as $eventId => $event) {
            $events[] = [
                'event_id' => (int) $eventId,
                'unique_event_name' => $project->getUniqueEventNames($eventId),
                'event_name' => $event['name'] ?? '',
                'arm_number' => (int) ($event['arm_num'] ?? 1),
                'arm_name' => $event['arm_name'] ?? '',
                'day_offset' => $event['day_offset'] ?? null,
                'forms' => array_values($project->eventsForms[$eventId] ?? []),
            ];
        }
        $dags = [];
        foreach (($project->getGroups() ?: []) as $groupId => $groupName) {
            $dags[] = ['group_id' => (int) $groupId, 'name' => $groupName, 'unique_name' => $project->getUniqueGroupNames($groupId)];
        }
        return $this->framework->apiJsonResponse([
            'project_id' => $projectId,
            'longitudinal' => !empty($project->longitudinal),
            'arms' => $project->events ?? [],
            'events' => $events,
            'data_access_groups' => $dags,
            'repeating_forms_events' => $project->getRepeatingFormsEvents(),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function exportRecords(int $projectId, array $payload): array
    {
        $limit = $this->boundedInt($payload['limit'] ?? 200, 200, 1, 1000);
        $offset = $this->nonNegativeInt($payload['offset'] ?? 0, 0);
        $identifiersAllowed = $this->identifiersAllowed($projectId);
        [$fields, $excluded, $unknown] = $this->requestedFields($projectId, $payload, $identifiersAllowed);
        if (!empty($unknown)) {
            return $this->framework->apiErrorResponse('Unknown field name(s) requested. REDCap export was not run.', 400);
        }
        if (empty($fields)) {
            return $this->framework->apiErrorResponse('No exportable fields remain. Specify a non-Identifier field or enable identifier export for this project.', 403);
        }
        $data = $this->getFlatData($projectId, $payload, $fields, $limit, $offset);
        [$data, $returnedExcluded] = $this->stripIdentifierColumns($projectId, $data, $identifiersAllowed);
        return $this->framework->apiJsonResponse([
            'project_id' => $projectId,
            'identifiers_allowed' => $identifiersAllowed,
            'excluded_identifier_fields' => array_values(array_unique(array_merge($excluded, $returnedExcluded))),
            'offset' => $offset,
            'limit' => $limit,
            'returned_rows' => count($data),
            'truncated' => count($data) === $limit,
            'data' => $data,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function summarizeFields(int $projectId, array $payload): array
    {
        $fields = $this->stringList($payload['fields'] ?? []);
        if (empty($fields)) {
            return $this->framework->apiErrorResponse('summarize-fields requires one or more fields.', 400);
        }
        $identifiersAllowed = $this->identifiersAllowed($projectId);
        [$fields, $unknown] = $this->validateRequestedFields($projectId, $fields);
        if (!empty($unknown)) {
            return $this->framework->apiErrorResponse('Unknown field name(s) requested. REDCap export was not run.', 400);
        }
        [$fields, $excluded] = $this->filterIdentifierFields($projectId, $fields, $identifiersAllowed);
        if (empty($fields)) {
            return $this->framework->apiErrorResponse('No summarizable fields remain. Specify a non-Identifier field or enable identifier export for this project.', 403);
        }
        $limit = $this->boundedInt($payload['limit'] ?? 1000, 1000, 1, 5000);
        $data = $this->getFlatData($projectId, $payload, $fields, $limit, $this->nonNegativeInt($payload['offset'] ?? 0, 0));
        [$data, $returnedExcluded] = $this->stripIdentifierColumns($projectId, $data, $identifiersAllowed);
        $summary = [];
        foreach ($fields as $field) {
            $values = [];
            $missing = 0;
            foreach ($data as $row) {
                $value = $row[$field] ?? '';
                if ($value === '' || $value === null) { $missing++; continue; }
                $values[(string) $value] = ($values[(string) $value] ?? 0) + 1;
            }
            arsort($values);
            $summary[$field] = [
                'nonmissing' => array_sum($values),
                'missing' => $missing,
                'distinct_values' => count($values),
                'value_counts' => array_slice($values, 0, 50, true),
                'values_truncated' => count($values) > 50,
            ];
        }
        return $this->framework->apiJsonResponse([
            'project_id' => $projectId,
            'identifiers_allowed' => $identifiersAllowed,
            'excluded_identifier_fields' => array_values(array_unique(array_merge($excluded, $returnedExcluded))),
            'rows_analyzed' => count($data),
            'limit' => $limit,
            'truncated' => count($data) === $limit,
            'fields' => $summary,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function summarizeReport(int $projectId, array $payload): array
    {
        $reportId = (int) ($payload['report-id'] ?? 0);
        if (!$reportId || !\DataExport::validateReportId($projectId, $reportId)) {
            return $this->framework->apiErrorResponse('report-id must identify a report in this project.', 400);
        }
        $identifiersAllowed = $this->identifiersAllowed($projectId);
        $limit = $this->boundedInt($payload['limit'] ?? 1000, 1000, 1, 5000);
        $rows = array_values((array) \REDCap::getReport($reportId, 'array', !empty($payload['labels']), false));
        [$rows, $returnedExcluded] = $this->stripIdentifierColumns($projectId, $rows, $identifiersAllowed);
        $rows = array_slice($rows, $this->nonNegativeInt($payload['offset'] ?? 0, 0), $limit);
        $fields = $this->stringList($payload['fields'] ?? []);
        if (!empty($fields)) {
            [$fields, $unknown] = $this->validateRequestedFields($projectId, $fields);
            if (!empty($unknown)) {
                return $this->framework->apiErrorResponse('Unknown field name(s) requested. Report summary was not run.', 400);
            }
        }
        if (empty($fields) && !empty($rows[0]) && is_array($rows[0])) $fields = array_keys($rows[0]);
        [$fields, $excluded] = $this->filterIdentifierFields($projectId, $fields, $identifiersAllowed);
        if (empty($fields)) {
            return $this->framework->apiErrorResponse('No summarizable report fields remain. Enable identifier export for this project to summarize Identifier fields.', 403);
        }
        $summary = [];
        foreach ($fields as $field) {
            $values = []; $missing = 0;
            foreach ($rows as $row) {
                $value = $row[$field] ?? '';
                if ($value === '' || $value === null) { $missing++; continue; }
                $values[(string) $value] = ($values[(string) $value] ?? 0) + 1;
            }
            arsort($values);
            $summary[$field] = ['nonmissing' => array_sum($values), 'missing' => $missing, 'distinct_values' => count($values), 'value_counts' => array_slice($values, 0, 50, true), 'values_truncated' => count($values) > 50];
        }
        return $this->framework->apiJsonResponse(['project_id' => $projectId, 'report_id' => $reportId, 'identifiers_allowed' => $identifiersAllowed, 'excluded_identifier_fields' => array_values(array_unique(array_merge($excluded, $returnedExcluded))), 'rows_analyzed' => count($rows), 'limit' => $limit, 'fields' => $summary]);
    }

    /**
     * Aggregate protected fields without ever returning a record-level value.
     * Identifier-export permission does not affect this deliberately narrow path.
     *
     * @param array<string,mixed> $payload
     */
    private function aggregateIdentifierFields(int $projectId, array $payload): array
    {
        $fields = $this->stringList($payload['fields'] ?? []);
        if (empty($fields)) {
            return $this->framework->apiErrorResponse('aggregate-identifier-fields requires one or more fields.', 400);
        }
        [$fields, $unknown] = $this->validateRequestedFields($projectId, $fields);
        if (!empty($unknown)) {
            return $this->framework->apiErrorResponse('Unknown field name(s) requested. REDCap export was not run.', 400);
        }

        $numericFields = $this->stringList($payload['numeric_fields'] ?? []);
        $ageFields = $this->stringList($payload['date_fields_as_age'] ?? []);
        [$numericFields, $unknownNumeric] = $this->validateRequestedFields($projectId, $numericFields);
        [$ageFields, $unknownAge] = $this->validateRequestedFields($projectId, $ageFields);
        if (!empty($unknownNumeric) || !empty($unknownAge) || !empty(array_diff(array_merge($numericFields, $ageFields), $fields))) {
            return $this->framework->apiErrorResponse('numeric_fields and date_fields_as_age must contain requested, valid fields.', 400);
        }
        if ($this->filterLogicReferencesProtectedField($projectId, $payload['filter_logic'] ?? null)) {
            return $this->framework->apiErrorResponse('filter_logic may not reference Identifier or identifier-keyword fields when using aggregate-identifier-fields.', 403);
        }

        $limit = $this->boundedInt($payload['limit'] ?? 5000, 5000, 1, 5000);
        $minimumCellSize = $this->aggregateMinimumCellSize($projectId);
        $asOfDate = $this->aggregateReferenceDate($payload['as_of_date'] ?? null);
        if ($asOfDate === null) {
            return $this->framework->apiErrorResponse('as_of_date must use YYYY-MM-DD format.', 400);
        }

        // Raw values exist only inside this method and are replaced with aggregate
        // statistics before the response is created.
        $data = $this->getFlatData($projectId, array_merge($payload, [
            'raw_or_label' => 'label',
            'checkbox_labels' => true,
        ]), $fields, $limit, $this->nonNegativeInt($payload['offset'] ?? 0, 0));
        $dictionary = \REDCap::getDataDictionary($projectId, 'array');
        $aggregates = [];
        foreach ($fields as $field) {
            if (in_array($field, $ageFields, true)) {
                $aggregates[$field] = $this->ageAggregate($data, $field, $asOfDate, $minimumCellSize);
            } elseif (in_array($field, $numericFields, true) || $this->isNumericField($dictionary[$field] ?? [])) {
                $aggregates[$field] = $this->numericAggregate($data, $field, $minimumCellSize);
            } elseif ($this->isCodedCategoricalField($dictionary[$field] ?? [])) {
                $aggregates[$field] = $this->categoricalAggregate($data, $field, $minimumCellSize);
            } else {
                return $this->framework->apiErrorResponse("Field '$field' must be a validated number, a requested date-to-age field, or a coded categorical field.", 400);
            }
        }

        return $this->framework->apiJsonResponse([
            'project_id' => $projectId,
            'identifier_aggregates_only' => true,
            'minimum_cell_size' => $minimumCellSize,
            'reference_date' => $asOfDate->format('Y-m-d'),
            'rows_analyzed' => count($data),
            'limit' => $limit,
            'truncated' => count($data) === $limit,
            'aggregates' => $aggregates,
        ]);
    }

    /**
     * Return a full-project coded-category breakdown with per-category small-cell
     * suppression. Deliberately disallows caller-defined cohorts, pagination, and
     * record selection to prevent differencing and subgroup-size attacks.
     *
     * @param array<string,mixed> $payload
     */
    private function aggregateCategoricalCounts(int $projectId, array $payload): array
    {
        $field = is_string($payload['field'] ?? null) ? $payload['field'] : '';
        if ($field === '') return $this->framework->apiErrorResponse('aggregate-categorical-counts requires field.', 400);
        [$validFields, $unknown] = $this->validateRequestedFields($projectId, [$field]);
        if (!empty($unknown) || count($validFields) !== 1) {
            return $this->framework->apiErrorResponse('field must be a valid REDCap field name.', 400);
        }
        if ($this->hasCategoricalBreakdownScope($payload)) {
            return $this->framework->apiErrorResponse('aggregate-categorical-counts only supports full-project counts; records, events, groups, filter_logic, offset, and limit are not permitted.', 403);
        }

        $dictionary = \REDCap::getDataDictionary($projectId, 'array');
        $definition = $dictionary[$field] ?? [];
        if (!$this->isSingleSelectCategoricalField($definition)) {
            return $this->framework->apiErrorResponse('field must be a coded radio button, dropdown, yes/no, or true/false field.', 400);
        }

        $minimumCellSize = max(
            $this->aggregateMinimumCellSize($projectId),
            $this->boundedInt($payload['min_cell_size'] ?? null, 0, 2, 100)
        );
        $data = $this->getFlatData($projectId, ['raw_or_label' => 'raw'], [$field], 5000, 0);
        $counts = [];
        foreach ($data as $row) {
            $value = $row[$field] ?? '';
            if ($value !== '' && $value !== null) $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
        }

        $categories = [];
        foreach ($this->categoricalLabels($definition) as $code => $label) {
            $count = $counts[(string) $code] ?? 0;
            $categories[] = $count >= $minimumCellSize
                ? ['category' => $label, 'count' => $count]
                : ['category' => $label, 'count' => "suppressed (n<$minimumCellSize)"];
        }
        return $this->framework->apiJsonResponse([
            'project_id' => $projectId,
            'field' => $field,
            'field_label' => $definition['field_label'] ?? $field,
            'minimum_cell_size' => $minimumCellSize,
            'full_project_only' => true,
            'maximum_rows_analyzed' => 5000,
            'categories' => $categories,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function hasCategoricalBreakdownScope(array $payload): bool
    {
        if (!empty($this->stringList($payload['records'] ?? [])) || !empty($this->stringList($payload['events'] ?? [])) || !empty($this->stringList($payload['groups'] ?? []))) return true;
        if (is_string($payload['filter_logic'] ?? null) && trim($payload['filter_logic']) !== '') return true;
        return !empty($payload['offset']) || array_key_exists('limit', $payload);
    }

    /** @param array<string,mixed> $field */
    private function isSingleSelectCategoricalField(array $field): bool
    {
        return in_array($field['field_type'] ?? '', ['radio', 'dropdown', 'yesno', 'truefalse'], true);
    }

    /** @param array<string,mixed> $field @return array<string,string> */
    private function categoricalLabels(array $field): array
    {
        $fieldType = $field['field_type'] ?? '';
        if ($fieldType === 'yesno') return ['1' => 'Yes', '0' => 'No'];
        if ($fieldType === 'truefalse') return ['1' => 'True', '0' => 'False'];
        $labels = parseEnum($field['select_choices_or_calculations'] ?? '');
        return is_array($labels) ? $labels : [];
    }

    /** @param array<string,mixed> $payload */
    private function aggregateCheckboxCounts(int $projectId, array $payload): array
    {
        [$field, $definition, $error] = $this->aggregateFieldDefinition($projectId, $payload, 'checkbox');
        if ($error !== null) return $this->framework->apiErrorResponse($error, 400);
        $minimum = $this->effectiveMinimumCellSize($projectId, $payload);
        $data = $this->getFlatData($projectId, ['raw_or_label' => 'raw'], [$field], 5000, 0);
        $counts = [];
        foreach ($data as $row) {
            foreach ($row as $column => $value) {
                if (strpos($column, $field . '___') === 0 && (string) $value === '1') {
                    $counts[substr($column, strlen($field) + 3)] = ($counts[substr($column, strlen($field) + 3)] ?? 0) + 1;
                }
            }
        }
        return $this->framework->apiJsonResponse($this->protectedBreakdownResponse($projectId, $field, $definition, $this->suppressedCategories($this->categoricalLabels($definition), $counts, $minimum), $minimum));
    }

    /** @param array<string,mixed> $payload */
    private function aggregateNumericDistribution(int $projectId, array $payload): array
    {
        [$field, $definition, $error] = $this->aggregateFieldDefinition($projectId, $payload, 'numeric');
        if ($error !== null) return $this->framework->apiErrorResponse($error, 400);
        $min = filter_var($definition['text_validation_min'] ?? null, FILTER_VALIDATE_FLOAT);
        $max = filter_var($definition['text_validation_max'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($min === false || $max === false || $min >= $max) {
            return $this->framework->apiErrorResponse('Numeric distribution requires a REDCap field with validated minimum and maximum values.', 400);
        }
        $minimum = $this->effectiveMinimumCellSize($projectId, $payload);
        $data = $this->getFlatData($projectId, ['raw_or_label' => 'raw'], [$field], 5000, 0);
        $width = ($max - $min) / 5;
        $bins = array_fill(0, 5, 0);
        foreach ($data as $row) {
            $value = $row[$field] ?? null;
            if (!is_numeric($value) || $value < $min || $value > $max) continue;
            $index = min(4, (int) floor(((float) $value - $min) / $width));
            $bins[$index]++;
        }
        $categories = [];
        foreach ($bins as $index => $count) {
            $low = $min + ($index * $width);
            $high = $index === 4 ? $max : $min + (($index + 1) * $width);
            $categories[] = $count >= $minimum
                ? ['category' => sprintf('%.2f–%.2f', $low, $high), 'count' => $count]
                : ['category' => sprintf('%.2f–%.2f', $low, $high), 'count' => "suppressed (n<$minimum)"];
        }
        return $this->framework->apiJsonResponse($this->protectedBreakdownResponse($projectId, $field, $definition, $categories, $minimum));
    }

    /** @param array<string,mixed> $payload */
    private function aggregateAgeDistribution(int $projectId, array $payload): array
    {
        [$field, $definition, $error] = $this->aggregateFieldDefinition($projectId, $payload, 'date');
        if ($error !== null) return $this->framework->apiErrorResponse($error, 400);
        $asOf = $this->aggregateReferenceDate($payload['as_of_date'] ?? null);
        if ($asOf === null) return $this->framework->apiErrorResponse('as_of_date must use YYYY-MM-DD format.', 400);
        $minimum = $this->effectiveMinimumCellSize($projectId, $payload);
        $labels = ['0–17', '18–34', '35–49', '50–64', '65+'];
        $counts = array_fill_keys($labels, 0);
        foreach ($this->getFlatData($projectId, ['raw_or_label' => 'raw'], [$field], 5000, 0) as $row) {
            try {
                $date = new \DateTimeImmutable((string) ($row[$field] ?? ''));
                if ($date > $asOf) continue;
                $age = $date->diff($asOf)->y;
                $bucket = $age < 18 ? '0–17' : ($age < 35 ? '18–34' : ($age < 50 ? '35–49' : ($age < 65 ? '50–64' : '65+')));
                $counts[$bucket]++;
            } catch (\Exception $exception) { }
        }
        $categories = $this->suppressedCategories(array_combine($labels, $labels), $counts, $minimum);
        $response = $this->protectedBreakdownResponse($projectId, $field, $definition, $categories, $minimum);
        $response['reference_date'] = $asOf->format('Y-m-d');
        return $this->framework->apiJsonResponse($response);
    }

    /** @param array<string,mixed> $payload */
    private function aggregateDateDistribution(int $projectId, array $payload): array
    {
        [$field, $definition, $error] = $this->aggregateFieldDefinition($projectId, $payload, 'date');
        if ($error !== null) return $this->framework->apiErrorResponse($error, 400);
        $minimum = $this->effectiveMinimumCellSize($projectId, $payload);
        $counts = [];
        foreach ($this->getFlatData($projectId, ['raw_or_label' => 'raw'], [$field], 5000, 0) as $row) {
            try { $year = (new \DateTimeImmutable((string) ($row[$field] ?? '')))->format('Y'); $counts[$year] = ($counts[$year] ?? 0) + 1; } catch (\Exception $exception) { }
        }
        ksort($counts);
        return $this->framework->apiJsonResponse($this->protectedBreakdownResponse($projectId, $field, $definition, $this->suppressedCategories($counts, $counts, $minimum), $minimum));
    }

    /** @param array<string,mixed> $payload */
    private function aggregateCompletionCounts(int $projectId, array $payload): array
    {
        if ($this->hasCategoricalBreakdownScope($payload)) return $this->framework->apiErrorResponse('aggregate-completion-counts only supports full-project counts.', 403);
        $forms = $this->stringList($payload['forms'] ?? []);
        $project = new \Project($projectId);
        if (empty($forms) || !empty(array_diff($forms, array_keys($project->forms ?? [])))) return $this->framework->apiErrorResponse('forms must contain one or more valid instrument names.', 400);
        $minimum = $this->effectiveMinimumCellSize($projectId, $payload);
        $data = $this->getFlatData($projectId, ['raw_or_label' => 'raw'], array_map(static fn($form) => $form . '_complete', $forms), 5000, 0);
        $results = [];
        foreach ($forms as $form) {
            $counts = ['Incomplete' => 0, 'Unverified' => 0, 'Complete' => 0];
            foreach ($data as $row) { $value = (string) ($row[$form . '_complete'] ?? ''); if ($value === '0') $counts['Incomplete']++; elseif ($value === '1') $counts['Unverified']++; elseif ($value === '2') $counts['Complete']++; }
            $results[$form] = $this->suppressedCategories($counts, $counts, $minimum);
        }
        return $this->framework->apiJsonResponse(['project_id' => $projectId, 'identifier_aggregates_only' => true, 'full_project_only' => true, 'minimum_cell_size' => $minimum, 'maximum_rows_analyzed' => 5000, 'forms' => $results]);
    }

    /** @param array<string,mixed> $payload */
    private function aggregateMissingness(int $projectId, array $payload): array
    {
        if ($this->hasCategoricalBreakdownScope($payload)) return $this->framework->apiErrorResponse('aggregate-missingness only supports full-project counts.', 403);
        $fields = $this->stringList($payload['fields'] ?? []);
        [$fields, $unknown] = $this->validateRequestedFields($projectId, $fields);
        if (empty($fields) || !empty($unknown)) return $this->framework->apiErrorResponse('fields must contain one or more valid REDCap fields.', 400);
        $minimum = $this->effectiveMinimumCellSize($projectId, $payload);
        $data = $this->getFlatData($projectId, ['raw_or_label' => 'raw'], $fields, 5000, 0);
        $results = [];
        foreach ($fields as $field) {
            $counts = ['Missing' => 0, 'Nonmissing' => 0];
            foreach ($data as $row) { empty($row[$field]) && $row[$field] !== '0' ? $counts['Missing']++ : $counts['Nonmissing']++; }
            $results[$field] = $this->suppressedCategories($counts, $counts, $minimum);
        }
        return $this->framework->apiJsonResponse(['project_id' => $projectId, 'identifier_aggregates_only' => true, 'full_project_only' => true, 'minimum_cell_size' => $minimum, 'maximum_rows_analyzed' => 5000, 'fields' => $results]);
    }

    /** @param array<string,mixed> $payload @return array{0:string,1:array<string,mixed>,2:?string} */
    private function aggregateFieldDefinition(int $projectId, array $payload, string $requiredType): array
    {
        if ($this->hasCategoricalBreakdownScope($payload)) return ['', [], 'This aggregate only supports full-project counts.'];
        $field = is_string($payload['field'] ?? null) ? $payload['field'] : '';
        [$valid, $unknown] = $this->validateRequestedFields($projectId, [$field]);
        $definition = $valid ? (\REDCap::getDataDictionary($projectId, 'array')[$valid[0]] ?? []) : [];
        $validType = $requiredType === 'checkbox' ? (($definition['field_type'] ?? '') === 'checkbox') : ($requiredType === 'numeric' ? $this->isNumericField($definition) : str_starts_with((string) ($definition['text_validation_type_or_show_slider_number'] ?? ''), 'date'));
        return [$valid[0] ?? '', $definition, (!empty($unknown) || !$field || !$validType) ? "field must be a valid $requiredType field." : null];
    }

    /** @param array<string,string> $labels @param array<string,int> $counts @return array<int,array<string,mixed>> */
    private function suppressedCategories(array $labels, array $counts, int $minimum): array
    {
        $categories = [];
        foreach ($labels as $code => $label) { $count = $counts[(string) $code] ?? 0; $categories[] = $count >= $minimum ? ['category' => $label, 'count' => $count] : ['category' => $label, 'count' => "suppressed (n<$minimum)"]; }
        return $categories;
    }

    /** @param array<string,mixed> $definition @param array<int,array<string,mixed>> $categories @return array<string,mixed> */
    private function protectedBreakdownResponse(int $projectId, string $field, array $definition, array $categories, int $minimum): array
    {
        return ['project_id' => $projectId, 'field' => $field, 'field_label' => $definition['field_label'] ?? $field, 'identifier_aggregates_only' => true, 'full_project_only' => true, 'minimum_cell_size' => $minimum, 'maximum_rows_analyzed' => 5000, 'categories' => $categories];
    }

    /** @param array<string,mixed> $payload */
    private function effectiveMinimumCellSize(int $projectId, array $payload): int
    {
        return max($this->aggregateMinimumCellSize($projectId), $this->boundedInt($payload['min_cell_size'] ?? null, 0, 2, 100));
    }

    /** @param array<int,array<string,mixed>> $data @return array<string,mixed> */
    private function numericAggregate(array $data, string $field, int $minimumCellSize): array
    {
        $values = [];
        foreach ($data as $row) {
            $value = $row[$field] ?? null;
            if ($value !== '' && $value !== null && is_numeric($value)) $values[] = (float) $value;
        }
        if (count($values) < $minimumCellSize) return ['type' => 'numeric', 'suppressed' => true];
        return ['type' => 'numeric', 'count' => count($values), 'mean' => round(array_sum($values) / count($values), 2), 'minimum' => min($values), 'maximum' => max($values)];
    }

    /** @param array<int,array<string,mixed>> $data @return array<string,mixed> */
    private function ageAggregate(array $data, string $field, \DateTimeImmutable $asOfDate, int $minimumCellSize): array
    {
        $ages = [];
        foreach ($data as $row) {
            $value = $row[$field] ?? '';
            if (!is_string($value) || $value === '') continue;
            try {
                $date = new \DateTimeImmutable($value);
                if ($date <= $asOfDate) $ages[] = $date->diff($asOfDate)->y;
            } catch (\Exception $exception) {
                // Invalid dates are excluded without exposing their source value.
            }
        }
        if (count($ages) < $minimumCellSize) return ['type' => 'age_from_date', 'suppressed' => true];
        return ['type' => 'age_from_date', 'count' => count($ages), 'mean_age' => round(array_sum($ages) / count($ages), 2), 'minimum_age' => min($ages), 'maximum_age' => max($ages)];
    }

    /** @param array<int,array<string,mixed>> $data @return array<string,mixed> */
    private function categoricalAggregate(array $data, string $field, int $minimumCellSize): array
    {
        $counts = [];
        foreach ($data as $row) {
            $value = $row[$field] ?? '';
            if ($value === '' || $value === null) continue;
            $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
        }
        $visible = [];
        foreach ($counts as $value => $count) {
            if ($count >= $minimumCellSize) $visible[$value] = $count;
        }
        arsort($visible);
        if (empty($visible)) return ['type' => 'categorical', 'suppressed' => true];
        return ['type' => 'categorical', 'counts' => $visible, 'suppressed_categories' => count($counts) - count($visible)];
    }

    /** @param array<string,mixed> $field */
    private function isNumericField(array $field): bool
    {
        return in_array($field['text_validation_type_or_show_slider_number'] ?? '', ['integer', 'number'], true);
    }

    /** @param array<string,mixed> $field */
    private function isCodedCategoricalField(array $field): bool
    {
        return in_array($field['field_type'] ?? '', ['radio', 'dropdown', 'checkbox', 'yesno', 'truefalse'], true);
    }

    private function aggregateMinimumCellSize(int $projectId): int
    {
        return $this->boundedInt($this->getProjectSetting('minimum-aggregate-cell-size', $projectId), 5, 2, 100);
    }

    private function aggregateReferenceDate($value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') return new \DateTimeImmutable('today');
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        try { return new \DateTimeImmutable($value); } catch (\Exception $exception) { return null; }
    }

    private function filterLogicReferencesProtectedField(int $projectId, $filterLogic): bool
    {
        if (!is_string($filterLogic) || trim($filterLogic) === '') return false;
        $protected = $this->identifierFieldNames($projectId);
        foreach (array_keys(getBracketedFields($filterLogic, true, true, false)) as $field) {
            // Longitudinal logic can prefix a field with an event reference.
            $field = preg_replace('/^.*\]\[/', '', (string) $field) ?: (string) $field;
            if ($this->isBlockedIdentifierField($field, $protected)) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $payload @return array{0:string[],1:string[],2:string[]} */
    private function requestedFields(int $projectId, array $payload, bool $identifiersAllowed): array
    {
        $fields = $this->stringList($payload['fields'] ?? []);
        $forms = $this->stringList($payload['forms'] ?? []);
        if (!empty($forms)) {
            $dictionary = \REDCap::getDataDictionary($projectId, 'array', false, [], $forms);
            $fields = array_merge($fields, array_keys($dictionary));
        }
        [$fields, $unknown] = $this->validateRequestedFields($projectId, array_values(array_unique($fields)));
        [$fields, $excluded] = $this->filterIdentifierFields($projectId, $fields, $identifiersAllowed);
        return [$fields, $excluded, $unknown];
    }

    /** @param array<string,mixed> $payload @param string[] $fields @return array<int,array<string,mixed>> */
    private function getFlatData(int $projectId, array $payload, array $fields, int $limit, int $offset): array
    {
        $result = \REDCap::getData([
            'project_id' => $projectId,
            'return_format' => 'json',
            'records' => $this->stringList($payload['records'] ?? []),
            'fields' => $fields,
            'events' => $this->stringList($payload['events'] ?? []),
            'groups' => $this->stringList($payload['groups'] ?? []),
            'filterLogic' => is_string($payload['filter_logic'] ?? null) ? $payload['filter_logic'] : null,
            'exportDataAccessGroups' => !empty($payload['include_dags']),
            'exportAsLabels' => ($payload['raw_or_label'] ?? 'raw') === 'label',
            'exportCsvHeadersAsLabels' => $this->identifiersAllowed($projectId) && !empty($payload['label_headers']),
            'outputCheckboxLabel' => !empty($payload['checkbox_labels']),
            'rowLimit' => $limit,
            'rowBegin' => $offset,
            'includeRepeatingFields' => true,
        ]);
        $data = is_string($result) ? json_decode($result, true) : $result;
        return is_array($data) ? array_values($data) : [];
    }

    private function identifiersAllowed(int $projectId): bool
    {
        return !empty($this->getProjectSetting('allow-identifiers', $projectId));
    }

    /** @return string[] */
    private function identifierFieldNames(int $projectId): array
    {
        $identifierFields = ['redcap_survey_identifier'];
        $identifierKeywords = $this->identifierKeywords();
        foreach (\REDCap::getDataDictionary($projectId, 'array') as $fieldName => $field) {
            if (in_array(strtolower((string) ($field['identifier'] ?? '')), ['y', '1', 'yes'], true) || $this->matchesIdentifierKeyword($fieldName, $field, $identifierKeywords)) {
                $identifierFields[] = $fieldName;
            }
        }
        return array_values(array_unique($identifierFields));
    }

    /** @return string[] */
    private function identifierKeywords(): array
    {
        global $identifier_keywords;
        $source = trim((string) ($identifier_keywords ?? ''));
        if ($source === '') $source = \System::identifier_keywords_default;
        $keywords = preg_split('/[\r\n,;]+/', $source) ?: [];
        return array_values(array_filter(array_map(static function ($keyword): string {
            return strtolower(trim($keyword));
        }, $keywords), static function ($keyword): bool {
            return $keyword !== '';
        }));
    }

    /** @param array<string,mixed> $field @param string[] $keywords */
    private function matchesIdentifierKeyword(string $fieldName, array $field, array $keywords): bool
    {
        $haystack = strtolower($fieldName . ' ' . ($field['field_label'] ?? ''));
        foreach ($keywords as $keyword) {
            if (strpos($haystack, $keyword) !== false) return true;
        }
        return false;
    }

    /** @param string[] $fields @return array{0:string[],1:string[]} */
    private function validateRequestedFields(int $projectId, array $fields): array
    {
        $dictionary = \REDCap::getDataDictionary($projectId, 'array');
        $allowedSystemFields = [
            'redcap_event_name', 'redcap_data_access_group',
            'redcap_repeat_instrument', 'redcap_repeat_instance',
        ];
        $valid = [];
        $unknown = [];
        foreach ($fields as $field) {
            $baseField = explode('___', $field, 2)[0];
            if (isset($dictionary[$baseField]) || in_array($field, $allowedSystemFields, true)) {
                $valid[] = $field;
            } else {
                $unknown[] = $field;
            }
        }
        return [array_values(array_unique($valid)), array_values(array_unique($unknown))];
    }

    /** @param string[] $fields @param string[] $blocked @return string[] */
    private function blockedFieldsInList(array $fields, array $blocked): array
    {
        $excluded = [];
        foreach ($fields as $field) {
            if (is_string($field) && $this->isBlockedIdentifierField($field, $blocked)) $excluded[] = $field;
        }
        return array_values(array_unique($excluded));
    }

    /** @param string[] $fields @return array{0:string[],1:string[]} */
    private function filterIdentifierFields(int $projectId, array $fields, bool $identifiersAllowed): array
    {
        if ($identifiersAllowed) return [array_values(array_unique($fields)), []];
        $blocked = $this->identifierFieldNames($projectId);
        $excluded = $this->blockedFieldsInList($fields, $blocked);
        return [array_values(array_diff($fields, $excluded)), $excluded];
    }

    /** @param array<int,array<string,mixed>> $rows @return array{0:array<int,array<string,mixed>>,1:string[]} */
    private function stripIdentifierColumns(int $projectId, array $rows, bool $identifiersAllowed): array
    {
        if ($identifiersAllowed) return [$rows, []];
        $blocked = $this->identifierFieldNames($projectId);
        $excluded = [];
        foreach ($rows as &$row) {
            if (!is_array($row)) continue;
            foreach (array_keys($row) as $field) {
                if ($this->isBlockedIdentifierField((string) $field, $blocked)) {
                    unset($row[$field]);
                    $excluded[] = $field;
                }
            }
        }
        unset($row);
        return [$rows, array_values(array_unique($excluded))];
    }

    /** @param string[] $blocked */
    private function isBlockedIdentifierField(string $field, array $blocked): bool
    {
        $baseField = explode('___', $field, 2)[0];
        return in_array($baseField, $blocked, true);
    }

    private function boundedInt($value, int $default, int $min, int $max): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        return ($value === false) ? $default : max($min, min($max, $value));
    }

    private function nonNegativeInt($value, int $default): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        return ($value === false) ? $default : max(0, $value);
    }

    private function hasProjectDesignRights(string $userId, int $projectId): bool
    {
        $rights = \REDCap::getUserRights($userId, $projectId);
        return (int) ($rights[$userId]['design'] ?? 0) === 1;
    }

    /** @return string[] */
    private function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static function ($item): bool {
            return is_string($item) && $item !== '';
        }));
    }
}
