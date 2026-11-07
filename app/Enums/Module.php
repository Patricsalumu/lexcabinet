<?php

namespace App\Enums;

/**
 * Modules de la navigation. Un module est accessible si l'utilisateur
 * possède au moins une des permissions listées (gate « view-module »).
 */
enum Module: string
{
    case Dashboard = 'dashboard';
    case Agenda = 'agenda';
    case Clients = 'clients';
    case Cases = 'dossiers';
    case Invoices = 'factures';
    case Cash = 'caisse';
    case Accounting = 'comptabilite';
    case Emails = 'emails';
    case Settings = 'parametres';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Tableau de bord',
            self::Agenda => 'Agenda',
            self::Clients => 'Clients',
            self::Cases => 'Dossiers',
            self::Invoices => 'Factures',
            self::Cash => 'Caisse',
            self::Accounting => 'Comptabilité',
            self::Emails => 'E-mails',
            self::Settings => 'Paramètres',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Dashboard => [Permission::DashboardView],
            self::Agenda => [Permission::AgendaView, Permission::AgendaManage],
            self::Clients => [Permission::ClientsViewAll, Permission::ClientsViewOwn],
            self::Cases => [Permission::CasesViewAll, Permission::CasesViewOwn],
            self::Invoices => [Permission::InvoicesViewAll, Permission::InvoicesViewOwn],
            self::Cash => [Permission::CashView],
            self::Accounting => [Permission::AccountingView],
            self::Emails => [Permission::EmailsView],
            self::Settings => [Permission::SettingsManage, Permission::UsersManage, Permission::RatesManage, Permission::AuditView],
        };
    }

    public function url(): string
    {
        return match ($this) {
            self::Dashboard => route('dashboard'),
            self::Settings => route('settings.index'),
            self::Clients => route('clients.index'),
            self::Cases => route('cases.index'),
            self::Agenda => route('agenda.index'),
            self::Emails => route('emails.index'),
            self::Invoices => route('invoices.index'),
            self::Cash => route('cash.index'),
            self::Accounting => route('accounting.index'),
        };
    }

    public function isCurrent(): bool
    {
        return match ($this) {
            self::Dashboard => request()->routeIs('dashboard'),
            self::Settings => request()->routeIs('settings.*'),
            self::Clients => request()->routeIs('clients.*'),
            self::Cases => request()->routeIs('cases.*'),
            self::Agenda => request()->routeIs('agenda.*'),
            self::Emails => request()->routeIs('emails.*'),
            self::Invoices => request()->routeIs('invoices.*'),
            self::Cash => request()->routeIs('cash.*'),
            self::Accounting => request()->routeIs('accounting.*'),
        };
    }
}
