<?php

declare(strict_types=1);

namespace Plugins\LoanSync\Domain;

/**
 * The entities a device may push and pull, and the columns it may write.
 *
 * THIS IS AN ALLOWLIST, and that is the whole point of it. A sync endpoint
 * takes an entity name and a bag of fields from a handset that a field officer
 * carries around a trading centre. Without a fixed list, "update customer 7"
 * and "update customer 7, and set branch_id to one you do not work at" are the
 * same request. Every name below was GENERATED from the migrated schema, so a
 * column that does not exist cannot appear here.
 *
 * Five columns are never writable by a client and are absent from every list:
 *
 *   rev         the server assigns it; it IS the cursor
 *   device_id   the server stamps it from the authenticated device, so a
 *               handset cannot attribute its writes to another one
 *   created_at  server clock
 *   updated_at  server clock
 *   deleted_at  set by the delete ACTION, never by a field update — otherwise
 *               an "update" could resurrect or tombstone a row silently
 *
 * `id` is absent too: it is client-minted and arrives as the operation's
 * subject, not as a field to be overwritten.
 */
final readonly class EntityCatalogue
{
    private function __construct(
        public string $name,
        public string $table,
        /** @var list<string> columns a client may set when CREATING the row */
        public array $columns,
        /**
         * Columns a client may change AFTERWARDS.
         *
         * Null means "the same as on create", which is the ordinary case. A
         * narrower list makes a row effectively write-once except for the few
         * fields that are allowed to move.
         *
         * @var list<string>|null
         */
        public ?array $updatable = null,
        /**
         * Whether a client may tombstone the row at all.
         *
         * False means the record is permanent: it can be superseded, but never
         * made to disappear.
         */
        public bool $deletable = true,
        /**
         * Columns this entity stores as a BOOLEAN.
         *
         * The four drivers the kernel supports store one four ways —
         * TINYINT(1), BOOLEAN, a dynamically typed INTEGER and BIT — and PDO
         * hands each back in its own shape. A pull page is JSON a handset
         * parses, so left alone the same row would travel as `1` from MySQL
         * and `true` from PostgreSQL: the wire format would depend on where
         * the server happens to be deployed.
         *
         * Named here because the catalogue is the only thing that knows this
         * schema; converted in the repository, which is the layer allowed to
         * know about drivers.
         *
         * @var list<string>
         */
        public array $booleans = [],
    ) {
    }

    /** Columns a client may change on an existing row. */
    public function updatableColumns(): array
    {
        return $this->updatable ?? $this->columns;
    }

    /**
     * Keeps only the fields this entity accepts on an UPDATE.
     *
     * @param  array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>} [accepted, ignored]
     */
    public function filterUpdate(array $input): array
    {
        $allowed  = $this->updatableColumns();
        $accepted = [];
        $ignored  = [];

        foreach ($input as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $accepted[$key] = $value;
                continue;
            }

            $ignored[] = (string) $key;
        }

        return [$accepted, $ignored];
    }

    /** @return array<string, self> */
    public static function all(): array
    {
        static $map = null;

        return $map ??= [
            'customers' => new self(
                name:    'customers',
                table:   'customers',
                columns: [
                'customer_number', 'first_name', 'last_name', 'full_name', 'phone',
                'alt_phone', 'national_id', 'gender', 'date_of_birth', 'occupation',
                'business_type', 'district', 'subcounty', 'parish', 'village',
                'location', 'branch_id', 'field_officer_id', 'savings_balance',
                'photo_path', 'guarantor', 'next_of_kin', 'documents', 'status',
                'notes', 'registered_on', 'registered_by',
                ],
            ),
            'branches' => new self(
                name:    'branches',
                table:   'branches',
                columns: [
                'name', 'code', 'district', 'address', 'phone', 'manager_staff_id',
                'opened_on', 'status', 'opening_cash', 'opening_float',
                'money_set_at',
                ],
            ),
            'staff_members' => new self(
                name:    'staff_members',
                table:   'staff_members',
                columns: [
                'user_id', 'name', 'phone', 'email', 'role', 'branch_id', 'region',
                'banned', 'hired_on', 'photo_path',
                ],
                booleans: ['banned'],
            ),
            'business_hours' => new self(
                name:    'business_hours',
                table:   'business_hours',
                columns: [
                'branch_id', 'days', 'opening_time', 'closing_time',
                'close_reminder',
                ],
                booleans: ['close_reminder'],
            ),
            'loans' => new self(
                name:    'loans',
                table:   'loans',
                columns: [
                'loan_number', 'customer_id', 'customer_name', 'branch_id',
                'loan_amount', 'amount_given', 'total_to_be_paid', 'paid_so_far',
                'loan_balance', 'interest_rate', 'payment_type',
                'number_of_payments', 'paid_installments',
                'next_installment_amount', 'status', 'given_date', 'due_date',
                'next_due_date', 'days_overdue', 'office_charge',
                'office_charge_config', 'late_payment_penalty',
                'late_penalty_config', 'penalty_charged', 'penalty_paid',
                'field_officer_id', 'field_officer_name', 'given_by',
                'loan_product', 'loan_purpose', 'notes', 'security',
                'agreement_uploaded', 'agreement_path', 'signatures', 'needs_review',
                ],
                booleans: ['agreement_uploaded', 'needs_review'],
            ),
            'loan_installments' => new self(
                name:    'loan_installments',
                table:   'loan_installments',
                columns: [
                'loan_id', 'installment_number', 'due_date', 'amount',
                'paid_amount', 'status', 'paid_date', 'penalty_applied',
                ],
                booleans: ['penalty_applied'],
            ),
            'loan_timeline' => new self(
                name:    'loan_timeline',
                table:   'loan_timeline',
                columns: [
                'loan_id', 'event_type', 'event_date', 'description', 'amount',
                'balance_after', 'actor_id',
                ],
            ),
            'loan_payments' => new self(
                name:    'loan_payments',
                table:   'loan_payments',
                columns: [
                'receipt_number', 'loan_id', 'customer_id', 'customer_name',
                'branch_id', 'amount', 'principal_part', 'interest_part',
                'penalty_part', 'payment_method', 'reference', 'payment_date',
                'recorded_at', 'recorded_by', 'recorded_by_name',
                'recorded_by_role', 'allocation', 'balance_after', 'photo_path',
                'notes', 'corrected_by_payment_id', 'voided', 'void_reason',
                ],
                // THE LEDGER IS WRITE-ONCE.
                //
                // A repayment was computed on a handset and PRINTED ON A
                // RECEIPT the customer is holding. Letting a later sync rewrite
                // `amount` or the principal/interest/penalty split would make
                // the server disagree with a piece of paper in someone's
                // pocket, silently, with no record that it ever changed.
                //
                // A mistake is corrected the way the schema already provides:
                // void the original with a reason, and point at the payment
                // that supersedes it. Both rows survive, and the correction is
                // visible instead of being an edit nobody can see.
                updatable: ['voided', 'void_reason', 'corrected_by_payment_id'],
                // And it is never tombstoned. A payment that existed can never
                // be arithmetically forgotten — that is what makes the ledger
                // reconcilable at all.
                deletable: false,
                booleans: ['voided'],
            ),
            'collections' => new self(
                name:    'collections',
                table:   'collections',
                columns: [
                'branch_id', 'amount', 'destination', 'destination_detail',
                'reference', 'collected_on', 'recorded_by', 'recorded_by_name',
                'proof_path', 'notes',
                ],
            ),
            'handovers' => new self(
                name:    'handovers',
                table:   'handovers',
                columns: [
                'branch_id', 'from_staff_id', 'from_name', 'from_role',
                'to_staff_id', 'to_name', 'to_role', 'amount', 'method',
                'handed_on', 'proof_path', 'recorded_by', 'notes',
                ],
            ),
            'business_days' => new self(
                name:    'business_days',
                table:   'business_days',
                columns: [
                'branch_id', 'business_date', 'status', 'expected_opening_cash',
                'counted_opening_cash', 'opening_difference_reason',
                'opening_proof_path', 'opened_at', 'opened_by',
                'expected_closing_cash', 'counted_closing_cash',
                'closing_difference_reason', 'closing_proof_path', 'closed_at',
                'closed_by', 'collections_total', 'loans_disbursed_total',
                'expenses_total', 'handed_over_total',
                ],
            ),
            'expenses' => new self(
                name:    'expenses',
                table:   'expenses',
                columns: [
                'branch_id', 'category', 'amount', 'description', 'payee',
                'payment_method', 'reference', 'spent_on', 'receipt_path',
                'recorded_by', 'recorded_by_name', 'notes',
                ],
            ),
            'visits' => new self(
                name:    'visits',
                table:   'visits',
                columns: [
                'customer_id', 'loan_id', 'visit_type', 'visited_on', 'visited_at',
                'outcome', 'notes', 'latitude', 'longitude', 'photo_path',
                'recorded_by',
                ],
            ),
            'reminders' => new self(
                name:    'reminders',
                table:   'reminders',
                columns: [
                'customer_id', 'loan_id', 'channel', 'amount_quoted', 'message',
                'sent_at', 'sent_by',
                ],
            ),
            // path, mime, bytes, checksum, uploaded and uploaded_from_device are
            // SERVER-OWNED: they describe bytes the server itself received,
            // sanitised, scanned and stored (see AttachmentService). A handset
            // that could write them could claim a file is uploaded with a path
            // that does not exist, or attribute it to another device. The
            // handset states only what it knows before the bytes move: which
            // record the file belongs to, what it is, and when it was taken.
            'attachments' => new self(
                name:    'attachments',
                table:   'attachments',
                columns: [
                'entity', 'entity_id', 'kind', 'captured_at',
                ],
                booleans: ['uploaded'],
            ),
        ];
    }

    public static function has(string $entity): bool
    {
        return isset(self::all()[$entity]);
    }

    /** @throws \DomainException when the entity is not syncable */
    public static function get(string $entity): self
    {
        return self::all()[$entity]
            ?? throw new \DomainException(sprintf('Entity [%s] is not syncable.', $entity));
    }

    /**
     * Every syncable name, in an order a full pull can be applied in: a row is
     * never sent before the row it points at.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Keeps only the fields this entity accepts.
     *
     * Silently DROPPING an unknown field rather than refusing the whole
     * operation is deliberate: a phone running last month's build will send a
     * column this server does not have, and refusing the write would strand
     * that handset until somebody walks the officer through a store update.
     * The dropped names are returned so the caller can record what was ignored
     * instead of discarding it in silence.
     *
     * @param  array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>} [accepted, ignored]
     */
    public function filter(array $input): array
    {
        $accepted = [];
        $ignored  = [];

        foreach ($input as $key => $value) {
            if (in_array($key, $this->columns, true)) {
                $accepted[$key] = $value;
                continue;
            }

            $ignored[] = (string) $key;
        }

        return [$accepted, $ignored];
    }
}
