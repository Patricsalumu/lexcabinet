<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Lawyer = 'avocat';
    case Secretary = 'secretaire';
    case Cashier = 'caissier';
    case Accountant = 'comptable';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Lawyer => 'Avocat',
            self::Secretary => 'Secrétariat',
            self::Cashier => 'Caisse',
            self::Accountant => 'Comptabilité',
        };
    }

    /**
     * Permissions accordées à chaque rôle (cf. CLAUDE.md, « Rôles et droits »).
     * Les droits d'un utilisateur sont l'union des permissions de ses rôles.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Lawyer => [
                Permission::DashboardView,
                Permission::AgendaView,
                Permission::ClientsViewOwn,
                Permission::CasesViewOwn,
                Permission::TimeLog,
                Permission::InvoicesViewOwn,
            ],
            self::Secretary => [
                Permission::DashboardView,
                Permission::AgendaView,
                Permission::AgendaManage,
                Permission::ClientsViewAll,
                Permission::ClientsManage,
                Permission::CasesViewAll,
                Permission::CasesManage,
                Permission::RemindersSend,
                Permission::EmailsView,
                Permission::EmailTemplatesManage,
            ],
            self::Cashier => [
                Permission::DashboardView,
                Permission::InvoicesViewAll,
                Permission::CashView,
                Permission::CashOperate,
            ],
            self::Accountant => [
                Permission::DashboardView,
                Permission::InvoicesViewAll,
                Permission::InvoicesManage,
                Permission::CashView,
                Permission::AccountingView,
                Permission::AccountingManage,
                Permission::ReportsView,
            ],
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
