<?php

namespace App\Support\Tenancy;

/**
 * Tables portant la colonne firm_id : celles qu'on exporte pour un cabinet (phase M4).
 * Liste à tenir à jour à chaque nouvelle table métier.
 */
final class FirmTables
{
    /** @var list<string> */
    public const TABLES = [
        'users', 'settings', 'exchange_rates', 'counters', 'clients', 'cases', 'case_events', 'time_entries', 'documents',
        'appointments', 'email_templates', 'email_logs', 'email_replies', 'invoices', 'invoice_lines', 'credit_notes',
        'treasury_accounts', 'cash_sessions', 'cash_movements', 'advance_allocations', 'accounts', 'accounting_periods',
        'journal_entries', 'journal_lines', 'activity_log',
    ];
}
