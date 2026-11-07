<?php

namespace App\Enums;

/**
 * Permissions fines, attribuées aux rôles par Role::permissions().
 * Les contrôles se font côté serveur (Policies, middleware can:), puis @can dans Blade.
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';

    case AgendaView = 'agenda.view';
    case AgendaManage = 'agenda.manage';
    case RemindersSend = 'reminders.send';

    case ClientsViewAll = 'clients.view_all';
    case ClientsViewOwn = 'clients.view_own';
    case ClientsManage = 'clients.manage';

    case CasesViewAll = 'cases.view_all';
    case CasesViewOwn = 'cases.view_own';
    case CasesManage = 'cases.manage';
    case TimeLog = 'time.log';

    case InvoicesViewAll = 'invoices.view_all';
    case InvoicesViewOwn = 'invoices.view_own';
    case InvoicesManage = 'invoices.manage';

    case CashView = 'cash.view';
    case CashOperate = 'cash.operate';
    case CashValidate = 'cash.validate';

    case AccountingView = 'accounting.view';
    case AccountingManage = 'accounting.manage';
    case ReportsView = 'reports.view';

    case EmailsView = 'emails.view';
    case EmailTemplatesManage = 'email_templates.manage';

    case SettingsManage = 'settings.manage';
    case UsersManage = 'users.manage';
    case RatesManage = 'rates.manage';
    case AuditView = 'audit.view';
}
