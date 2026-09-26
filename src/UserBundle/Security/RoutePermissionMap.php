<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\UserBundle\Security;

use Augias\UserBundle\Enum\CompanyPermission;
use function array_key_exists;
use function str_starts_with;

/**
 * Which right each page, action and live component needs.
 *
 * Every route of the application is listed, one way or the other — a test
 * fails when one is added and forgotten here, so a new screen cannot open to
 * every member by default. A route mapped to null belongs to the person, not
 * the company (their profile, the company picker), or is public.
 *
 * @see \Augias\UserBundle\Tests\Security\RoutePermissionMapTest
 */
final class RoutePermissionMap
{
    /**
     * The route live components are served from; what they need depends on
     * the component, see COMPONENTS.
     */
    public const string LIVE_COMPONENT_ROUTE = 'ux_live_component';

    private const array ROUTES = [
        // The person, the session, the public side.
        '_2fa_list' => null,
        '_access_log' => null,
        '_change_password' => null,
        '_create_company' => null,
        '_dashboard' => null,
        '_dashboard_layout_reset' => null,
        '_dashboard_layout_save' => null,
        '_dashboard_onboarding_dismiss' => null,
        '_edit_profile' => null,
        '_home' => null,
        '_login_check' => null,
        '_login_history' => null,
        '_login_main' => null,
        '_logout' => null,
        '_logout_main' => null,
        '_mcp_endpoint' => null, // Each tool checks, see McpScopeGuard.
        '_oauth_connect' => null,
        '_oauth_connect_check' => null,
        '_onboarding' => null,
        '_payments_create' => null, // The client paying online.
        '_payments_done' => null,
        '_profile' => null,
        '_profile_notifications' => null,
        '_register' => null,
        '_search' => CompanyPermission::BillingRead,
        '_search_suggestions' => CompanyPermission::BillingRead,
        '_select_company' => null,
        '_solidworx_platform_security_two_factor_resend' => null,
        '_switch_company' => null,
        '_system_install' => null,
        '_user_accept_invite' => null,
        '_user_forgot_password' => null,
        '_user_forgot_password_check_email' => null,
        '_user_password_reset' => null,
        '_verify_email' => null,
        '_view_invoice_disbursement_note_external' => null,
        '_view_invoice_external' => null,
        '_view_invoice_receipt_external' => null,
        '_view_quote_external' => null,
        '_webhook_controller' => null,

        // Seeing the company's documents and books: every member.
        '_accounting_attachment_download' => CompanyPermission::BillingRead,
        '_accounting_book' => CompanyPermission::BillingRead,
        '_accounting_declaration_view' => CompanyPermission::BillingRead,
        '_accounting_declarations' => CompanyPermission::BillingRead,
        '_accounting_index' => CompanyPermission::BillingRead,
        '_bills_index' => CompanyPermission::BillingRead,
        '_bills_view' => CompanyPermission::BillingRead,
        '_catalog_index' => CompanyPermission::BillingRead,
        '_categories_index' => CompanyPermission::BillingRead,
        '_clients_index' => CompanyPermission::BillingRead,
        '_clients_view' => CompanyPermission::BillingRead,
        '_credit_notes_index' => CompanyPermission::BillingRead,
        '_credit_notes_view' => CompanyPermission::BillingRead,
        '_einvoicing_incoming' => CompanyPermission::BillingRead,
        '_einvoicing_incoming_download' => CompanyPermission::BillingRead,
        '_invoices_disbursement_note' => CompanyPermission::BillingRead,
        '_invoices_disbursement_receipt_download' => CompanyPermission::BillingRead,
        '_invoices_get_fields' => CompanyPermission::BillingRead,
        '_invoices_index' => CompanyPermission::BillingRead,
        '_invoices_index_recurring' => CompanyPermission::BillingRead,
        '_invoices_view' => CompanyPermission::BillingRead,
        '_invoices_view_recurring' => CompanyPermission::BillingRead,
        '_payments_index' => CompanyPermission::BillingRead,
        '_quotes_get_fields' => CompanyPermission::BillingRead,
        '_quotes_index' => CompanyPermission::BillingRead,
        '_quotes_view' => CompanyPermission::BillingRead,
        '_suppliers_index' => CompanyPermission::BillingRead,
        '_suppliers_view' => CompanyPermission::BillingRead,
        '_tax_number_validate' => CompanyPermission::BillingRead,
        '_users_list' => CompanyPermission::BillingRead,

        // Changing them.
        '_action_credit_note' => CompanyPermission::BillingWrite,
        '_action_invoice' => CompanyPermission::BillingWrite,
        '_action_recurring_invoice' => CompanyPermission::BillingWrite,
        '_bills_action' => CompanyPermission::BillingWrite,
        '_bills_add' => CompanyPermission::BillingWrite,
        '_bills_create_from_receipt' => CompanyPermission::BillingWrite,
        '_bills_delete' => CompanyPermission::BillingWrite,
        '_bills_edit' => CompanyPermission::BillingWrite,
        '_bills_record_payment' => CompanyPermission::BillingWrite,
        '_catalog_add' => CompanyPermission::BillingWrite,
        '_catalog_edit' => CompanyPermission::BillingWrite,
        '_categories_add' => CompanyPermission::BillingWrite,
        '_categories_edit' => CompanyPermission::BillingWrite,
        '_clients_add' => CompanyPermission::BillingWrite,
        '_clients_delete' => CompanyPermission::BillingWrite,
        '_clients_edit' => CompanyPermission::BillingWrite,
        '_credit_notes_allocate' => CompanyPermission::BillingWrite,
        '_credit_notes_create' => CompanyPermission::BillingWrite,
        '_credit_notes_edit' => CompanyPermission::BillingWrite,
        '_credit_notes_send' => CompanyPermission::BillingWrite,
        '_einvoicing_incoming_respond' => CompanyPermission::BillingWrite,
        '_einvoicing_send' => CompanyPermission::BillingWrite,
        '_invoices_clone' => CompanyPermission::BillingWrite,
        '_invoices_clone_recurring' => CompanyPermission::BillingWrite,
        '_invoices_create' => CompanyPermission::BillingWrite,
        '_invoices_create_recurring' => CompanyPermission::BillingWrite,
        '_invoices_disbursement_receipt_delete' => CompanyPermission::BillingWrite,
        '_invoices_disbursement_receipt_upload' => CompanyPermission::BillingWrite,
        '_invoices_edit' => CompanyPermission::BillingWrite,
        '_invoices_edit_recurring' => CompanyPermission::BillingWrite,
        '_quotes_clone' => CompanyPermission::BillingWrite,
        '_quotes_create' => CompanyPermission::BillingWrite,
        '_quotes_edit' => CompanyPermission::BillingWrite,
        '_send_invoice' => CompanyPermission::BillingWrite,
        '_send_manual_reminder' => CompanyPermission::BillingWrite,
        '_send_quote' => CompanyPermission::BillingWrite,
        '_suppliers_add' => CompanyPermission::BillingWrite,
        '_suppliers_delete' => CompanyPermission::BillingWrite,
        '_suppliers_edit' => CompanyPermission::BillingWrite,
        '_transition_quote' => CompanyPermission::BillingWrite,

        // Keeping the books.
        '_accounting_attachment_delete' => CompanyPermission::AccountingWrite,
        '_accounting_declaration_submit' => CompanyPermission::AccountingWrite,
        '_accounting_entry_add' => CompanyPermission::AccountingWrite,
        '_accounting_entry_delete' => CompanyPermission::AccountingWrite,
        '_accounting_entry_edit' => CompanyPermission::AccountingWrite,
        '_accounting_period_close' => CompanyPermission::AccountingWrite,
        '_accounting_period_create' => CompanyPermission::AccountingWrite,

        // Taking the data out.
        '_datagrid_export' => CompanyPermission::Export,
        '_export_download' => CompanyPermission::Export,
        '_export_list' => CompanyPermission::Export,
        '_export_request' => CompanyPermission::Export,

        // Configuring the company.
        '_einvoicing_providers' => CompanyPermission::Settings,
        '_notification_integration' => CompanyPermission::Settings,
        '_payment_settings_index' => CompanyPermission::Settings,
        '_settings' => CompanyPermission::Settings,
        '_settings_custom_fields' => CompanyPermission::Settings,
        '_settings_custom_fields_create' => CompanyPermission::Settings,
        '_settings_custom_fields_delete' => CompanyPermission::Settings,
        '_settings_custom_fields_edit' => CompanyPermission::Settings,
        '_settings_custom_fields_reorder' => CompanyPermission::Settings,
        '_tax_rates' => CompanyPermission::Settings,
        '_tax_rates_add' => CompanyPermission::Settings,
        '_tax_rates_edit' => CompanyPermission::Settings,

        // Its people.
        '_member_remove' => CompanyPermission::ManageMembers,
        '_member_role' => CompanyPermission::ManageMembers,
        '_user_delete_invite' => CompanyPermission::ManageMembers,
        '_user_invite' => CompanyPermission::ManageMembers,
        '_user_resend_invite' => CompanyPermission::ManageMembers,
        '_users_login_history' => CompanyPermission::ManageMembers,

        // Leaving is the member's own business; the manager checks the rest.
        '_member_leave' => null,

        // The company itself.
        '_cancel_company_closure' => CompanyPermission::CloseCompany,
        '_delete_company' => CompanyPermission::CloseCompany,
        '_member_transfer_ownership' => CompanyPermission::CloseCompany,
    ];

    /**
     * Live components that change something; any other is read-only, or its
     * own business (a data grid checks its batch actions itself, the API token
     * and two-factor screens belong to the person).
     */
    private const array COMPONENTS = [
        'AddressCollection' => CompanyPermission::BillingWrite,
        'AddressInfo' => CompanyPermission::BillingWrite,
        'ClientCredit' => CompanyPermission::BillingWrite,
        'ClientForm' => CompanyPermission::BillingWrite,
        'CompanyTaxIdentifiers' => CompanyPermission::Settings,
        'ContactCollection' => CompanyPermission::BillingWrite,
        'ContactInfo' => CompanyPermission::BillingWrite,
        'CreateCreditNote' => CompanyPermission::BillingWrite,
        'CreateInvoice' => CompanyPermission::BillingWrite,
        'CreateQuote' => CompanyPermission::BillingWrite,
        'CreateRecurringInvoice' => CompanyPermission::BillingWrite,
        'CustomFieldForm' => CompanyPermission::Settings,
        'ElectronicInvoiceMarketplace' => CompanyPermission::Settings,
        'ElectronicInvoiceProviderConfiguration' => CompanyPermission::Settings,
        'NotificationMarketplace' => CompanyPermission::Settings,
        'NotificationTransportConfiguration' => CompanyPermission::Settings,
        'PaymentMarketplace' => CompanyPermission::Settings,
        'PaymentSettings' => CompanyPermission::Settings,
        'Settings' => CompanyPermission::Settings,
    ];

    public function isMapped(string $route): bool
    {
        return array_key_exists($route, self::ROUTES) || self::LIVE_COMPONENT_ROUTE === $route || str_starts_with($route, '_api_');
    }

    public function forRoute(string $route): ?CompanyPermission
    {
        return self::ROUTES[$route] ?? null;
    }

    public function forComponent(string $component): ?CompanyPermission
    {
        return self::COMPONENTS[$component] ?? null;
    }
}
