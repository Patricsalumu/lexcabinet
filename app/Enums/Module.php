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

    /** Modules livrés ; les autres affichent une page « à venir » (route « module »). */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Dashboard, self::Settings, self::Clients, self::Cases, self::Agenda, self::Emails], true);
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
            default => route('module', $this->value),
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
            default => request()->routeIs('module') && request()->route('module') === $this->value,
        };
    }

    /** Phase de livraison du module (les modules futurs affichent une page « à venir »). */
    public function phase(): int
    {
        return match ($this) {
            self::Dashboard, self::Settings => 1,
            self::Clients, self::Cases => 2,
            self::Agenda, self::Emails => 3,
            self::Invoices => 4,
            self::Cash => 5,
            self::Accounting => 6,
        };
    }
}
