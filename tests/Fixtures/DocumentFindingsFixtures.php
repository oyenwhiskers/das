<?php

namespace Tests\Fixtures;

class DocumentFindingsFixtures
{
    /** @return array<string, mixed> */
    public static function identity(int $scanId): array
    {
        $document = self::base($scanId);
        $document['entities'][] = self::entity('person-1', 'person', 'Holder', 'Amina Noor');
        $document['fields'][] = self::field('name', 'Full name', 'text', 'Amina Noor', 'person-1');
        $document['fields'][] = self::field('birth', 'Date of birth', 'date', '1990-04-02', 'person-1');
        $document['fields'][1]['raw_value'] = '02 APR 1990';

        return $document;
    }

    /** @return array<string, mixed> */
    public static function payslip(int $scanId): array
    {
        $document = self::base($scanId);
        $document['entities'][] = self::entity('employee', 'person', 'Employee', 'Ravi Tan');
        $document['entities'][] = self::entity('employer', 'organization', 'Employer', 'Example Works');
        $document['fields'][] = self::field('employee-name', 'Employee', 'text', 'Ravi Tan', 'employee');
        $document['fields'][] = self::field('employer-name', 'Employer', 'text', 'Example Works', 'employer');
        $document['fields'][] = self::field('gross-pay', 'Gross salary', 'currency', ['amount' => '4280.35', 'currency' => 'MYR'], 'employee');
        $document['fields'][] = self::field('deduction', 'Deductions', 'currency', ['amount' => '312.20', 'currency' => 'MYR'], 'employee');
        $document['fields'][2]['raw_value'] = 'RM 4,280.35';
        $document['fields'][3]['raw_value'] = 'RM 312.20';

        return $document;
    }

    /** @return array<string, mixed> */
    public static function bankStatement(int $scanId): array
    {
        $document = self::base($scanId);
        $document['entities'][] = self::entity('bank', 'organization', 'Issuer', 'Example Bank');
        $document['fields'][] = self::field('account', 'Account number', 'text', '001-234', null);
        $document['tables'][] = [
            'id' => 'transactions', 'section_id' => null, 'entity_id' => null,
            'columns' => [
                ['id' => 'date', 'heading' => 'Date'],
                ['id' => 'description', 'heading' => 'Description'],
                ['id' => 'amount', 'heading' => 'Amount (MYR)'],
            ],
            'rows' => [[
                'id' => 'transaction-1',
                'cells' => [
                    ['column_id' => 'date', 'raw_value' => '02 JAN 2026', 'type' => 'date', 'normalized_value' => '2026-01-02', 'notes' => null, 'evidence' => ['page_number' => 1]],
                    ['column_id' => 'description', 'raw_value' => 'Purchase', 'type' => 'text', 'normalized_value' => 'Purchase', 'notes' => null],
                    ['column_id' => 'amount', 'raw_value' => '-12.50', 'type' => 'currency', 'normalized_value' => ['amount' => '-12.50', 'currency' => 'MYR'], 'notes' => null],
                ],
            ]],
            'evidence' => ['page_number' => 1],
        ];

        return $document;
    }

    /** @return array<string, mixed> */
    public static function application(int $scanId): array
    {
        $document = self::base($scanId);
        $document['sections'][] = ['id' => 'personal', 'heading' => 'Personal details', 'parent_id' => null, 'page_numbers' => [1], 'order' => 1];
        $document['pages'][0]['section_ids'] = ['personal'];
        $document['entities'][] = self::entity('applicant', 'person', 'Applicant', 'Maya Lim');
        $document['entities'][] = self::entity('spouse', 'person', 'Spouse', 'Lee Lim');
        $document['fields'][] = self::field('applicant-name', 'Applicant name', 'text', 'Maya Lim', 'applicant', 'personal');
        $document['fields'][] = self::field('spouse-name', 'Spouse name', 'text', 'Lee Lim', 'spouse', 'personal');
        $document['fields'][] = self::field('unanswered', 'Previous address', 'text', null, 'applicant', 'personal', 'unanswered');
        $document['fields'][] = self::choice('marital', 'Marital status', 'single', [
            self::option('single', 'Single', 'unmarked'), self::option('married', 'Married', 'marked'), self::option('divorced', 'Divorced', 'unmarked'),
        ], ['married']);
        $document['fields'][] = self::choice('wealth', 'Source of wealth', 'multiple', [
            self::option('salary', 'Salary', 'marked'), self::option('savings', 'Savings', 'marked'),
            self::option('other', 'Others (specify)', 'marked', 'Family gift'),
        ], ['salary', 'savings', 'other']);
        $document['fields'][] = self::choice('fatca', 'US tax status', 'single', [
            self::option('yes', 'Yes', 'unmarked'), self::option('no', 'No', 'marked'),
        ], ['no']);

        return $document;
    }

    /** @return array<string, mixed> */
    public static function complexBlankForm(int $scanId): array
    {
        $document = self::base($scanId);
        $document['entities'][] = self::entity('applicant', 'person', 'Applicant', null);
        $document['entities'][] = self::entity('spouse', 'person', 'Spouse', null);
        $brackets = ['Below RM25,000', 'RM25,001 - RM50,000', 'RM50,001 - RM100,000', 'RM100,001 - RM200,000', 'RM200,001 - RM500,000', 'Above RM500,000'];
        foreach (['annual-income', 'joint-income', 'net-assets', 'investment-portfolio'] as $id) {
            $document['fields'][] = self::choice($id, ucwords(str_replace('-', ' ', $id)), 'single', array_map(
                fn (int $index, string $label): array => self::option('bracket-'.($index + 1), $label, 'unmarked'),
                array_keys($brackets), $brackets
            ), null, 'unanswered');
            $document['fields'][array_key_last($document['fields'])]['entity_id'] = $id === 'joint-income' ? null : 'applicant';
        }
        $wealth = ['Employment', 'Business', 'Savings', 'Inheritance', 'Gift', 'Investment', 'Property', 'Pension', 'Insurance', 'Loan', 'Others (specify)'];
        $document['fields'][] = self::choice('source-of-wealth', 'Source of wealth', 'multiple', array_map(
            fn (int $index, string $label): array => self::option('source-'.($index + 1), $label, 'unmarked'),
            array_keys($wealth), $wealth
        ), null, 'unanswered');
        foreach (['fatca', 'pep'] as $id) {
            $document['fields'][] = self::choice($id, strtoupper($id).' declaration', 'single', [
                self::option('yes', 'Yes', 'unmarked'), self::option('no', 'No', 'unmarked'),
            ], null, 'unanswered');
        }
        $document['fields'][] = self::choice('objectives', 'Investment objectives', 'multiple', [
            self::option('income', 'Income', 'unmarked'), self::option('growth', 'Growth', 'unmarked'),
        ], null, 'unanswered');
        $document['fields'][] = self::choice('products', 'Investment products', 'multiple', [
            self::option('equities', 'Equities', 'unmarked'), self::option('funds', 'Funds', 'unmarked'),
        ], null, 'unanswered');
        $document['fields'][] = self::field('spouse-name', 'Spouse name', 'text', null, 'spouse', null, 'unanswered');
        $document['fields'][] = self::field('witness-name', 'Witness name', 'text', null, null, null, 'unanswered');
        $document['tables'][] = [
            'id' => 'related-people', 'section_id' => null, 'entity_id' => 'applicant',
            'columns' => [['id' => 'name', 'heading' => 'Name'], ['id' => 'relationship', 'heading' => 'Relationship']],
            'rows' => [], 'evidence' => ['page_number' => 1],
        ];

        return $document;
    }

    /** @return array<string, mixed> */
    public static function conflictingForm(int $scanId): array
    {
        $document = self::base($scanId);
        $document['fields'][] = self::choice('conflict', 'Select one', 'single', [
            self::option('a', 'A', 'marked'), self::option('b', 'B', 'marked'),
        ], null, 'conflict');
        $document['fields'][] = self::choice('uncertain', 'Consent', 'single', [
            self::option('yes', 'Yes', 'ambiguous'), self::option('no', 'No', 'unmarked'),
        ], null, 'ambiguous');
        $document['fields'][] = self::field('illegible', 'Reference', 'text', null, null, null, 'unreadable');
        $document['issues'][] = self::issue('issue-conflict', 'conflicting_selection', 'conflict');
        $document['issues'][] = self::issue('issue-uncertain', 'ambiguous_checkbox', 'uncertain');
        $document['issues'][] = self::issue('issue-illegible', 'illegible_handwriting', 'illegible');

        return $document;
    }

    /** @return array<string, mixed> */
    public static function contract(int $scanId): array
    {
        $document = self::base($scanId);
        $document['entities'][] = self::entity('signer-a', 'person', 'Signatory', 'Nora Azmi');
        $document['entities'][] = self::entity('signer-b', 'organization', 'Signatory', 'Example Trading');
        $document['content_blocks'][] = ['id' => 'clause-1', 'type' => 'paragraph', 'text' => 'Payment is due within thirty days.', 'section_id' => null, 'entity_id' => null, 'evidence' => ['page_number' => 1]];
        $document['content_blocks'][] = ['id' => 'declaration', 'type' => 'declaration', 'text' => 'The parties agree to these terms.', 'section_id' => null, 'entity_id' => null, 'evidence' => ['page_number' => 1]];
        $document['fields'][] = self::field('signature-a', 'Signature of Nora Azmi', 'signature', 'present', 'signer-a');
        $document['fields'][] = self::field('signature-b', 'Signature of Example Trading', 'signature', 'absent', 'signer-b');

        return $document;
    }

    /** @return array<string, mixed> */
    private static function base(int $scanId): array
    {
        return [
            'schema_version' => '1.0',
            'document' => ['reference' => (string) $scanId, 'page_count' => 1, 'reading_status' => 'complete', 'limitations' => null],
            'pages' => [['number' => 1, 'reading_status' => 'complete', 'section_ids' => [], 'issues' => []]],
            'sections' => [], 'entities' => [], 'fields' => [], 'tables' => [], 'content_blocks' => [], 'issues' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function entity(string $id, string $type, string $role, ?string $name): array
    {
        return ['id' => $id, 'type' => $type, 'role' => $role, 'display_name' => $name, 'related_entity_ids' => [], 'page_numbers' => [1]];
    }

    /** @return array<string, mixed> */
    private static function field(string $id, string $label, string $type, mixed $value, ?string $entityId, ?string $sectionId = null, string $status = 'answered'): array
    {
        return ['id' => $id, 'label' => $label, 'type' => $type, 'section_id' => $sectionId, 'entity_id' => $entityId,
            'raw_value' => is_string($value) ? $value : null, 'normalized_value' => $value, 'answer_status' => $status,
            'selection_mode' => null, 'options' => [], 'evidence' => ['page_number' => 1], 'notes' => null];
    }

    /** @param array<int, array<string, mixed>> $options @param array<int, string>|null $selected @return array<string, mixed> */
    private static function choice(string $id, string $label, string $mode, array $options, ?array $selected, string $status = 'answered'): array
    {
        $field = self::field($id, $label, 'choice', $selected, null, null, $status);
        $field['selection_mode'] = $mode;
        $field['options'] = $options;

        return $field;
    }

    /** @return array<string, mixed> */
    private static function option(string $id, string $label, string $mark, ?string $freeText = null): array
    {
        return ['id' => $id, 'label' => $label, 'mark_state' => $mark, 'free_text' => $freeText];
    }

    /** @return array<string, mixed> */
    private static function issue(string $id, string $type, string $findingId): array
    {
        return ['id' => $id, 'type' => $type, 'severity' => 'warning', 'description' => $type,
            'finding_id' => $findingId, 'page_number' => 1, 'resolution_status' => 'open'];
    }
}
