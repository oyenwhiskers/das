<?php

namespace App\Services;

use App\Models\DocumentScan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

class DocumentFindingsV1
{
    public const VERSION = '1.0';

    /** @param array<string, mixed> $payload */
    public function validate(DocumentScan $scan, array $payload): array
    {
        $payload = $this->normalizeOptionalEvidence($payload);

        $rules = [
            'schema_version' => ['required', 'in:1.0'],
            'document' => ['required', 'array:reference,page_count,reading_status,limitations'],
            'document.reference' => ['required', 'string', 'max:255'],
            'document.page_count' => ['required', 'integer', 'min:1'],
            'document.reading_status' => ['required', 'in:complete,partial,unreadable,unknown'],
            'document.limitations' => ['present', 'nullable', 'string'],
            'pages' => ['required', 'array', 'list'],
            'pages.*.number' => ['required', 'integer', 'min:1'],
            'pages.*.reading_status' => ['required', 'in:complete,partial,unreadable,unknown'],
            'pages.*.section_ids' => ['present', 'array', 'list'],
            'pages.*.section_ids.*' => ['string'],
            'pages.*.issues' => ['present', 'array', 'list'],
            'pages.*.issues.*' => ['string'],
            'sections' => ['present', 'array', 'list'],
            'sections.*.id' => ['required', 'string', 'max:100'],
            'sections.*.heading' => ['required', 'string'],
            'sections.*.parent_id' => ['present', 'nullable', 'string'],
            'sections.*.page_numbers' => ['required', 'array', 'list', 'min:1'],
            'sections.*.page_numbers.*' => ['integer'],
            'sections.*.order' => ['required', 'integer', 'min:0'],
            'entities' => ['present', 'array', 'list'],
            'entities.*.id' => ['required', 'string', 'max:100'],
            'entities.*.type' => ['required', 'in:person,organization,other'],
            'entities.*.role' => ['required', 'string'],
            'entities.*.display_name' => ['present', 'nullable', 'string'],
            'entities.*.related_entity_ids' => ['present', 'array', 'list'],
            'entities.*.related_entity_ids.*' => ['string'],
            'entities.*.page_numbers' => ['required', 'array', 'list', 'min:1'],
            'entities.*.page_numbers.*' => ['integer'],
            'fields' => ['present', 'array', 'list'],
            'fields.*.id' => ['required', 'string', 'max:100'],
            'fields.*.label' => ['required', 'string'],
            'fields.*.type' => ['required', 'in:text,number,currency,date,boolean,choice,signature,other'],
            'fields.*.section_id' => ['present', 'nullable', 'string'],
            'fields.*.entity_id' => ['present', 'nullable', 'string'],
            'fields.*.raw_value' => ['present', 'nullable', 'string'],
            'fields.*.normalized_value' => ['present'],
            'fields.*.answer_status' => ['required', 'in:answered,unanswered,ambiguous,unreadable,conflict,not_applicable'],
            'fields.*.selection_mode' => ['present', 'nullable', 'in:single,multiple'],
            'fields.*.options' => ['present', 'array', 'list'],
            'fields.*.options.*.id' => ['required', 'string', 'max:100'],
            'fields.*.options.*.label' => ['required', 'string'],
            'fields.*.options.*.mark_state' => ['required', 'in:marked,unmarked,ambiguous,unreadable'],
            'fields.*.options.*.free_text' => ['present', 'nullable', 'string'],
            'fields.*.options.*.evidence' => ['sometimes', 'nullable', 'array'],
            'fields.*.evidence' => ['required', 'array'],
            'fields.*.notes' => ['present', 'nullable', 'string'],
            'tables' => ['present', 'array', 'list'],
            'tables.*.id' => ['required', 'string', 'max:100'],
            'tables.*.section_id' => ['present', 'nullable', 'string'],
            'tables.*.entity_id' => ['present', 'nullable', 'string'],
            'tables.*.columns' => ['required', 'array', 'list', 'min:1'],
            'tables.*.columns.*.id' => ['required', 'string', 'max:100'],
            'tables.*.columns.*.heading' => ['required', 'string'],
            'tables.*.rows' => ['present', 'array', 'list'],
            'tables.*.rows.*.id' => ['required', 'string', 'max:100'],
            'tables.*.rows.*.cells' => ['present', 'array', 'list'],
            'tables.*.rows.*.cells.*.column_id' => ['required', 'string'],
            'tables.*.rows.*.cells.*.raw_value' => ['present', 'nullable', 'string'],
            'tables.*.rows.*.cells.*.type' => ['present', 'nullable', 'in:text,number,currency,date,boolean,other'],
            'tables.*.rows.*.cells.*.normalized_value' => ['present'],
            'tables.*.rows.*.cells.*.notes' => ['present', 'nullable', 'string'],
            'tables.*.rows.*.cells.*.evidence' => ['sometimes', 'nullable', 'array'],
            'tables.*.evidence' => ['required', 'array'],
            'content_blocks' => ['present', 'array', 'list'],
            'content_blocks.*.id' => ['required', 'string', 'max:100'],
            'content_blocks.*.type' => ['required', 'in:heading,paragraph,declaration,instruction,other'],
            'content_blocks.*.text' => ['required', 'string'],
            'content_blocks.*.section_id' => ['present', 'nullable', 'string'],
            'content_blocks.*.entity_id' => ['present', 'nullable', 'string'],
            'content_blocks.*.evidence' => ['required', 'array'],
            'issues' => ['present', 'array', 'list'],
            'issues.*.id' => ['required', 'string', 'max:100'],
            'issues.*.type' => ['required', 'string'],
            'issues.*.severity' => ['required', 'in:info,warning,error'],
            'issues.*.description' => ['required', 'string'],
            'issues.*.finding_id' => ['present', 'nullable', 'string'],
            'issues.*.page_number' => ['present', 'nullable', 'integer'],
            'issues.*.resolution_status' => ['required', 'in:open,resolved,dismissed'],
        ];

        foreach (['fields.*.evidence', 'fields.*.options.*.evidence', 'tables.*.evidence', 'tables.*.rows.*.cells.*.evidence', 'content_blocks.*.evidence'] as $path) {
            $rules[$path.'.page_number'] = [str_contains($path, 'options.*') || str_contains($path, 'cells.*') ? 'required_with:'.$path : 'required', 'integer'];
            $rules[$path.'.region_ref'] = ['sometimes', 'nullable', 'string'];
            $rules[$path.'.text_snippet'] = ['sometimes', 'nullable', 'string'];
            $rules[$path.'.notes'] = ['sometimes', 'nullable', 'string'];
            $rules[$path.'.bbox'] = ['sometimes', 'nullable', 'array:x,y,width,height'];
            foreach (['x', 'y', 'width', 'height'] as $coordinate) {
                $rules[$path.'.bbox.'.$coordinate] = ['required_with:'.$path.'.bbox', 'numeric', 'between:0,1'];
            }
        }

        $validator = Validator::make($payload, $rules);
        $validator->after(fn (LaravelValidator $validator) => $this->validateRelationships($validator, $scan, $payload));

        $validator->validate();

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validateRelationships(LaravelValidator $validator, DocumentScan $scan, array $payload): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $error = fn (string $path, string $message) => $validator->errors()->add($path, $message);
        if (array_diff(array_keys($payload), ['schema_version', 'document', 'pages', 'sections', 'entities', 'fields', 'tables', 'content_blocks', 'issues']) !== []) {
            $error('schema_version', 'Unknown top-level findings keys are not supported.');
        }
        if ((string) $payload['document']['reference'] !== (string) $scan->getKey()) {
            $error('document.reference', 'The document reference must match the scan.');
        }

        $pageCount = $payload['document']['page_count'];
        $pageNumbers = [];
        foreach ($payload['pages'] as $index => $page) {
            $number = $page['number'];
            if ($number > $pageCount || isset($pageNumbers[$number])) {
                $error("pages.$index.number", 'Page numbers must be unique and within the document.');
            }
            $pageNumbers[$number] = true;
        }
        for ($number = 1; $number <= $pageCount; $number++) {
            if (! isset($pageNumbers[$number])) {
                $error('pages', 'Every document page needs a page record.');
                break;
            }
        }

        $ids = [];
        $sectionIds = [];
        $entityIds = [];
        foreach (['sections', 'entities', 'fields', 'tables', 'content_blocks', 'issues'] as $group) {
            foreach ($payload[$group] as $index => $item) {
                $id = $item['id'];
                if (isset($ids[$id])) {
                    $error("$group.$index.id", 'Finding IDs must be unique.');
                }
                $ids[$id] = true;
                if ($group === 'sections') {
                    $sectionIds[$id] = true;
                }
                if ($group === 'entities') {
                    $entityIds[$id] = true;
                }
            }
        }

        foreach ($payload['sections'] as $index => $section) {
            $this->checkPages($validator, "sections.$index.page_numbers", $section['page_numbers'], $pageNumbers);
            if ($section['parent_id'] !== null && (! isset($sectionIds[$section['parent_id']]) || $section['parent_id'] === $section['id'])) {
                $error("sections.$index.parent_id", 'The parent section must exist and differ from this section.');
            }
        }
        foreach ($payload['pages'] as $index => $page) {
            foreach ($page['section_ids'] as $sectionId) {
                if (! isset($sectionIds[$sectionId])) {
                    $error("pages.$index.section_ids", 'The section must exist.');
                }
            }
        }
        foreach ($payload['entities'] as $index => $entity) {
            $this->checkPages($validator, "entities.$index.page_numbers", $entity['page_numbers'], $pageNumbers);
            foreach ($entity['related_entity_ids'] as $relatedId) {
                if (! isset($entityIds[$relatedId]) || $relatedId === $entity['id']) {
                    $error("entities.$index.related_entity_ids", 'Related entities must exist and differ from this entity.');
                }
            }
        }

        foreach (['fields', 'tables', 'content_blocks'] as $group) {
            foreach ($payload[$group] as $index => $item) {
                foreach (['section_id' => $sectionIds, 'entity_id' => $entityIds] as $key => $validIds) {
                    if ($item[$key] !== null && ! isset($validIds[$item[$key]])) {
                        $error("$group.$index.$key", 'The referenced finding must exist.');
                    }
                }
                $this->checkEvidence($validator, "$group.$index.evidence", $item['evidence'], $pageNumbers);
            }
        }

        foreach ($payload['fields'] as $index => $field) {
            $this->checkField($validator, $field, $index, $payload['issues'], $pageNumbers);
        }
        foreach ($payload['tables'] as $index => $table) {
            $columns = [];
            foreach ($table['columns'] as $column) {
                if (isset($columns[$column['id']])) {
                    $error("tables.$index.columns", 'Column IDs must be unique within a table.');
                }
                $columns[$column['id']] = true;
            }
            $rows = [];
            foreach ($table['rows'] as $rowIndex => $row) {
                if (isset($rows[$row['id']])) {
                    $error("tables.$index.rows.$rowIndex.id", 'Row IDs must be unique within a table.');
                }
                $rows[$row['id']] = true;
                $cells = [];
                foreach ($row['cells'] as $cellIndex => $cell) {
                    if (! isset($columns[$cell['column_id']]) || isset($cells[$cell['column_id']])) {
                        $error("tables.$index.rows.$rowIndex.cells.$cellIndex.column_id", 'A cell must refer to a unique table column.');
                    }
                    $cells[$cell['column_id']] = true;
                    if (isset($cell['evidence'])) {
                        $this->checkEvidence($validator, "tables.$index.rows.$rowIndex.cells.$cellIndex.evidence", $cell['evidence'], $pageNumbers);
                    }
                    if ($cell['type'] === null && $cell['normalized_value'] !== null) {
                        $error("tables.$index.rows.$rowIndex.cells.$cellIndex.type", 'A normalized cell needs a type.');
                    } elseif ($cell['type'] !== null && $cell['normalized_value'] !== null && ! $this->isTypedValue($cell['type'], $cell['normalized_value'])) {
                        $error("tables.$index.rows.$rowIndex.cells.$cellIndex.normalized_value", 'The normalized cell value does not match its type.');
                    }
                }
            }
        }
        foreach ($payload['issues'] as $index => $issue) {
            if ($issue['finding_id'] !== null && ! isset($ids[$issue['finding_id']])) {
                $error("issues.$index.finding_id", 'The finding must exist.');
            }
            if ($issue['page_number'] !== null && ! isset($pageNumbers[$issue['page_number']])) {
                $error("issues.$index.page_number", 'The page must exist.');
            }
        }
    }

    /** @param array<int, mixed> $numbers @param array<int, bool> $pages */
    private function checkPages(LaravelValidator $validator, string $path, array $numbers, array $pages): void
    {
        if (count($numbers) !== count(array_unique($numbers))) {
            $validator->errors()->add($path, 'Page references must be unique.');
        }
        foreach ($numbers as $number) {
            if (! isset($pages[$number])) {
                $validator->errors()->add($path, 'The page must exist.');
            }
        }
    }

    /**
     * Drop only unambiguous null optional evidence values Gemini may emit.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeOptionalEvidence(array $payload): array
    {
        foreach ($payload['fields'] ?? [] as $fieldIndex => $field) {
            if (! is_array($field)) {
                continue;
            }

            if (is_array($field['evidence'] ?? null)) {
                $payload['fields'][$fieldIndex]['evidence'] = $this->normalizeEvidenceNode($field['evidence']);
            }

            foreach ($field['options'] ?? [] as $optionIndex => $option) {
                if (! is_array($option)) {
                    continue;
                }

                if (array_key_exists('evidence', $option) && $option['evidence'] === null) {
                    unset($payload['fields'][$fieldIndex]['options'][$optionIndex]['evidence']);

                    continue;
                }

                if (is_array($option['evidence'] ?? null)) {
                    $payload['fields'][$fieldIndex]['options'][$optionIndex]['evidence'] = $this->normalizeEvidenceNode($option['evidence']);
                }
            }
        }

        foreach ($payload['tables'] ?? [] as $tableIndex => $table) {
            if (! is_array($table)) {
                continue;
            }

            if (is_array($table['evidence'] ?? null)) {
                $payload['tables'][$tableIndex]['evidence'] = $this->normalizeEvidenceNode($table['evidence']);
            }

            foreach ($table['rows'] ?? [] as $rowIndex => $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach ($row['cells'] ?? [] as $cellIndex => $cell) {
                    if (! is_array($cell)) {
                        continue;
                    }

                    if (array_key_exists('evidence', $cell) && $cell['evidence'] === null) {
                        unset($payload['tables'][$tableIndex]['rows'][$rowIndex]['cells'][$cellIndex]['evidence']);

                        continue;
                    }

                    if (is_array($cell['evidence'] ?? null)) {
                        $payload['tables'][$tableIndex]['rows'][$rowIndex]['cells'][$cellIndex]['evidence'] = $this->normalizeEvidenceNode($cell['evidence']);
                    }
                }
            }
        }

        foreach ($payload['content_blocks'] ?? [] as $blockIndex => $block) {
            if (! is_array($block) || ! is_array($block['evidence'] ?? null)) {
                continue;
            }

            $payload['content_blocks'][$blockIndex]['evidence'] = $this->normalizeEvidenceNode($block['evidence']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function normalizeEvidenceNode(array $evidence): array
    {
        if (array_key_exists('bbox', $evidence) && $evidence['bbox'] === null) {
            unset($evidence['bbox']);
        }

        return $evidence;
    }

    /** @param array<string, mixed> $evidence @param array<int, bool> $pages */
    private function checkEvidence(LaravelValidator $validator, string $path, array $evidence, array $pages): void
    {
        if (! isset($pages[$evidence['page_number']])) {
            $validator->errors()->add($path.'.page_number', 'The evidence page must exist.');
        }
        if (is_array($evidence['bbox'] ?? null) && ($evidence['bbox']['x'] + $evidence['bbox']['width'] > 1 || $evidence['bbox']['y'] + $evidence['bbox']['height'] > 1)) {
            $validator->errors()->add($path.'.bbox', 'The bounding box must fit within the page.');
        }
    }

    /** @param array<string, mixed> $field @param array<int, array<string, mixed>> $issues @param array<int, bool> $pages */
    private function checkField(LaravelValidator $validator, array $field, int $index, array $issues, array $pages): void
    {
        $path = "fields.$index";
        $error = fn (string $key, string $message) => $validator->errors()->add("$path.$key", $message);
        $type = $field['type'];
        $value = $field['normalized_value'];
        if ($type === 'choice') {
            if ($field['selection_mode'] === null) {
                $error('selection_mode', 'Choice fields require a selection mode.');
            }
            if ($field['options'] === []) {
                $error('options', 'Choice fields require every printed option.');
            }
            $optionIds = [];
            $marked = 0;
            $markedIds = [];
            $uncertain = false;
            foreach ($field['options'] as $optionIndex => $option) {
                if (isset($optionIds[$option['id']])) {
                    $error("options.$optionIndex.id", 'Option IDs must be unique within a field.');
                }
                $optionIds[$option['id']] = true;
                $marked += (int) ($option['mark_state'] === 'marked');
                $uncertain = $uncertain || in_array($option['mark_state'], ['ambiguous', 'unreadable'], true);
                if ($option['mark_state'] === 'marked') {
                    $markedIds[] = $option['id'];
                }
                if (isset($option['evidence'])) {
                    $this->checkEvidence($validator, "$path.options.$optionIndex.evidence", $option['evidence'], $pages);
                }
            }
            if ($value !== null && (! is_array($value) || ! array_is_list($value) || count(array_filter($value, fn (mixed $selected): bool => ! is_string($selected) || ! isset($optionIds[$selected]))) > 0 || count($value) !== count(array_unique($value)))) {
                $error('normalized_value', 'Choice values must list existing option IDs.');
            }
            if ($field['answer_status'] === 'answered' && ($marked === 0 || ! is_array($value) || count($value) !== $marked)) {
                $error('answer_status', 'Answered choices must retain their marked options.');
            }
            if ($field['answer_status'] === 'answered' && is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value) && (array_diff($value, $markedIds) !== [] || array_diff($markedIds, $value) !== [])) {
                $error('normalized_value', 'Normalized choices must match the marked options.');
            }
            if ($field['answer_status'] === 'unanswered' && ($marked > 0 || $uncertain || $value !== null)) {
                $error('answer_status', 'Unanswered choices cannot have a marked or normalized selection.');
            }
            if ($field['answer_status'] === 'answered' && $uncertain) {
                $error('answer_status', 'Uncertain option marks require an uncertain answer status.');
            }
            if (in_array($field['answer_status'], ['ambiguous', 'unreadable', 'conflict'], true) && $value !== null) {
                $error('normalized_value', 'Uncertain choices cannot have a normalized selection.');
            }
            if ($marked === 0 && $field['answer_status'] === 'answered') {
                $error('answer_status', 'A printed choice cannot be an answer without a visible mark.');
            }
            if ($field['selection_mode'] === 'single' && $marked > 1) {
                if ($field['answer_status'] !== 'conflict' || ! collect($issues)->contains(fn (array $issue): bool => $issue['type'] === 'conflicting_selection' && $issue['finding_id'] === $field['id'])) {
                    $error('answer_status', 'Multiple marked single-choice options require conflict status and an issue.');
                }
            }
        } elseif ($field['selection_mode'] !== null || $field['options'] !== []) {
            $error('options', 'Only choice fields may have selection options.');
        }

        if ($value === null) {
            return;
        }
        if (! $this->isTypedValue($type, $value)) {
            $error('normalized_value', 'The normalized value does not match the field type.');
        }
    }

    private function isTypedValue(string $type, mixed $value): bool
    {
        return match ($type) {
            'text', 'other' => is_string($value),
            'number' => is_string($value) && (bool) preg_match('/^-?\d+(?:\.\d+)?$/', $value),
            'currency' => is_array($value) && isset($value['amount'], $value['currency']) && count($value) === 2 && is_string($value['amount']) && (bool) preg_match('/^-?\d+(?:\.\d+)?$/', $value['amount']) && is_string($value['currency']) && (bool) preg_match('/^[A-Z]{3}$/', $value['currency']),
            'date' => is_string($value) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
            'boolean' => is_bool($value),
            'signature' => in_array($value, ['present', 'absent', 'ambiguous', 'unreadable'], true),
            'choice' => is_array($value) && array_is_list($value),
            default => false,
        };
    }
}
