<?php

namespace App\Domains\Identity\Enums;

/**
 * Admin permissions used by the commerce screens (role-permission matrix §3.2,
 * format `{module}.{action}`). Each one is also a Gate ability of the same name.
 */
enum AdminPermission: string
{
    case OrdersView = 'orders.view';
    case OrdersStatus = 'orders.status';
    case OrdersForceCancel = 'orders.force_cancel';
    case VendorListingsView = 'vendor_listings.view';
    case VendorListingsApprove = 'vendor_listings.approve';
    case ShippingZonesView = 'shipping_zones.view';
    case ShippingZonesConfigure = 'shipping_zones.configure';
    case CalendarsView = 'calendars.view';
    case CalendarsConfigure = 'calendars.configure';
    case WarrantyPlansView = 'warranty_plans.view';
    case WarrantyPlansUpdate = 'warranty_plans.update';
    case FlashSalesView = 'flash_sales.view';
    case FlashSalesManage = 'flash_sales.manage';
    case PaymentGatewaysView = 'payment_gateways.view';
    case PaymentGatewaysUpdate = 'payment_gateways.update';
    case OrdersRefund = 'orders.refund';
    case ReturnsView = 'returns.view';
    case ReturnsManage = 'returns.manage';
    case DisputesView = 'disputes.view';
    case DisputesManage = 'disputes.manage';
    case WarrantyClaimsView = 'warranty_claims.view';
    case WarrantyClaimsManage = 'warranty_claims.manage';
    case PayoutsView = 'payouts.view';
    case PayoutsApprove = 'payouts.approve';
    case PayoutsProcess = 'payouts.process';
    case CodSettlementsView = 'cod_settlements.view';
    case CodSettlementsSettle = 'cod_settlements.settle';
    case MarketersView = 'marketers.view';
    case MarketersManage = 'marketers.manage';
    case MarketerCampaignsView = 'marketer_campaigns.view';
    case MarketerCampaignsManage = 'marketer_campaigns.manage';
    case MarketerSettingsConfigure = 'marketer_settings.configure';
    case MarketerWithdrawalsProcess = 'marketer_withdrawals.process';
    case CrossPromotionManage = 'cross_promotion.manage';
    case ContractTemplatesView = 'contract_templates.view';
    case ContractTemplatesManage = 'contract_templates.manage';
    case DocumentsView = 'documents.view';
    case DocumentsReview = 'documents.review';
    case DocumentTypesConfigure = 'document_types.configure';
    case ClassifiedsReview = 'classifieds.review';
    case TravelPackagesReview = 'travel_packages.review';
    case AdsReview = 'ads.review';
    // D14: travel promotion products (price per country, durations, slot cap) and paid promotions (cancel / refund).
    case TravelPromotionsView = 'travel_promotions.view';
    case TravelPromotionsManage = 'travel_promotions.manage';
    case CouponParticipationManage = 'coupon_participation.manage';
    case DeliveryView = 'delivery_assignments.view';
    case DeliveryDispatch = 'delivery_assignments.manage';
    case DeliveryAgentsManage = 'delivery_agents.manage';
    case CarriersManage = 'shipping_companies.manage';
    case CarrierClaimsView = 'carrier_claims.view';
    case CarrierClaimsManage = 'carrier_claims.manage';
    case LiveMediaView = 'live_media.view';
    case LiveMediaManage = 'live_media.manage';
    case PagesView = 'pages.view';
    case PagesManage = 'pages.manage';
    case AppContextsManage = 'app_contexts.manage';
    case BlogManage = 'blog.manage';
    case HelpCenterManage = 'help_center.manage';
    case StaticPagesManage = 'static_pages.manage';
    case FooterManage = 'footer.manage';
    case NotificationTemplatesManage = 'notification_templates.manage';
    case ApplicationsView = 'applications.view';
    case ApplicationsReview = 'applications.approve';
    case JoinOptionsConfigure = 'join_options.configure';
    case AdvertiseInquiriesView = 'advertise_inquiries.view';
    case AdvertiseInquiriesManage = 'advertise_inquiries.manage';
    case ReviewsView = 'reviews.view';
    case ReviewsModerate = 'reviews.moderate';
    case ProductQaModerate = 'product_qa.moderate';
    case CategoriesView = 'categories.view';
    case CategoriesManage = 'categories.manage';
    case BrandsView = 'brands.view';
    case BrandsManage = 'brands.manage';
    case ProductsView = 'products.view';
    case ProductsUpdate = 'products.update';
    case CouponsView = 'coupons.view';
    case CouponsManage = 'coupons.manage';
    case GiftCardsView = 'gift_cards.view';
    case GiftCardsManage = 'gift_cards.manage';
    case MembershipView = 'membership.view';
    case MembershipManage = 'membership.manage';
    case PaymentsReview = 'payments.review';
    case VendorsView = 'vendors.view';
    case VendorsUpdate = 'vendors.update';
    case VendorsAssignAccountManager = 'vendors.assign_account_manager';
    case AdminsView = 'admins.view';
    case AdminsManage = 'admins.manage';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';
    case ActivityLogView = 'activity_log.view';
    case SystemHealthView = 'system_health.view';
    case SystemHealthManage = 'system_health.manage';
    // QA wave 3 (QC-016 customer account states; matrix "customers": Ops / Support E, Marketing V).
    case CustomersView = 'customers.view';
    case CustomersUpdate = 'customers.update';
    // QA wave 5 (QC-007, matrix §3.2 Support "tickets": Support F, Ops V, Content view, account managers S·E).
    case TicketsView = 'tickets.view';
    case TicketsManage = 'tickets.manage';
    // Fulfilled by Nawy: view stock and queues; warehouse operations (receive, pick & pack, transfers, removals, adjustments); configure warehouses and fees.
    case FbnView = 'fbn.view';
    case FbnOperate = 'fbn.operate';
    case FbnConfigure = 'fbn.configure';
    // WP11 extras: Admin → Data migration (quarantine review, legacy records, redirects, reconciliation). Super admin + Ops.
    case DataMigrationView = 'data_migration.view';
    case DataMigrationManage = 'data_migration.manage';
    // FR-FIN-6 / FR-FIN-7: acquisition commission rules and payouts; vendor subscription plans and subscriptions. Finance (vendor relations view).
    case AcquisitionCommissionsView = 'acquisition_commissions.view';
    case AcquisitionCommissionsManage = 'acquisition_commissions.manage';
    case VendorSubscriptionsView = 'vendor_subscriptions.view';
    case VendorSubscriptionsManage = 'vendor_subscriptions.manage';
    // QC-016: product requests (§1.3), profile change requests (§7.3), packaging catalogue and orders (§6.3).
    case ProductRequestsView = 'product_requests.view';
    case ProductRequestsReview = 'product_requests.review';
    case ChangeRequestsView = 'change_requests.view';
    case ChangeRequestsReview = 'change_requests.review';
    case PackagingView = 'packaging.view';
    case PackagingManage = 'packaging.manage';
    // FR-CTY-10 / FR-ANL-2: cross-country reports (reporting DB) and the celebrity monthly report. Finance + Ops + Marketing (celebrity report).
    case ReportsView = 'reports.view';
    case CelebrityReportsView = 'celebrity_reports.view';
    // Exchange rates (PayPal USD conversion; finance) and domain / subdomain mapping (QC-044; super admin only by default).
    case ExchangeRatesView = 'exchange_rates.view';
    case ExchangeRatesManage = 'exchange_rates.manage';
    case DomainsView = 'domains.view';
    case DomainsManage = 'domains.manage';
    // Finance → Ledger (read-only accounts, balances and journal entries). Finance; super admins bypass.
    case LedgerView = 'ledger.view';

    /*
    | Restrictions (matrix §3.3): held by account managers. They narrow what
    | an admin sees, so a super admin never "has" them.
    */
    case RestrictedToAssignedVendors = 'admin.restricted_to_assigned_vendors';
    case FinanceHidden = 'finance.hidden';

    /**
     * A restriction narrows access instead of granting it.
     */
    public function isRestriction(): bool
    {
        return in_array($this, [self::RestrictedToAssignedVendors, self::FinanceHidden], true);
    }

    /**
     * Permission groups for the roles screen: module => permissions.
     *
     * @return array<string, array<int, self>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[strtok($permission->value, '.')][] = $permission;
        }

        return $groups;
    }

    /**
     * Default admin roles (matrix §3.1–3.2) and the permissions they hold.
     * Super admins (an admin membership without a country) bypass checks.
     *
     * @return array<string, array<int, self>>
     */
    public static function defaultRoles(): array
    {
        return [
            'operations' => [
                self::OrdersView, self::OrdersStatus, self::OrdersForceCancel,
                self::VendorListingsView, self::VendorListingsApprove,
                self::ShippingZonesView, self::ShippingZonesConfigure,
                self::CalendarsView, self::WarrantyPlansView, self::WarrantyPlansUpdate, self::FlashSalesView,
                self::ReturnsView, self::ReturnsManage, self::DisputesView, self::DisputesManage,
                self::WarrantyClaimsView, self::WarrantyClaimsManage, self::CodSettlementsView,
                self::MarketerCampaignsView, self::DocumentsView,
                self::ClassifiedsReview, self::TravelPackagesReview,
                self::DeliveryView, self::DeliveryDispatch, self::DeliveryAgentsManage, self::CarriersManage,
                self::CarrierClaimsView, self::CarrierClaimsManage,
                self::ReviewsView, self::ReviewsModerate, self::ProductQaModerate,
                self::CategoriesView, self::CategoriesManage, self::BrandsView, self::BrandsManage, self::ProductsView, self::ProductsUpdate,
                self::VendorsView, self::VendorsUpdate, self::ActivityLogView, self::SystemHealthView,
                self::CustomersView, self::CustomersUpdate, self::TicketsView,
                self::DataMigrationView, self::DataMigrationManage,
                self::ReportsView,
                self::FbnView, self::FbnOperate, self::FbnConfigure,
                self::ProductRequestsView, self::ProductRequestsReview, self::ChangeRequestsView, self::PackagingView, self::PackagingManage,
            ],
            'finance' => [
                self::OrdersView, self::ShippingZonesView, self::CalendarsView, self::WarrantyPlansView, self::PaymentGatewaysView, self::PaymentGatewaysUpdate,
                self::OrdersRefund, self::ReturnsView, self::DisputesView, self::WarrantyClaimsView,
                self::PayoutsView, self::PayoutsApprove, self::PayoutsProcess, self::CodSettlementsView, self::CodSettlementsSettle,
                self::MarketersView, self::MarketerCampaignsView, self::MarketerWithdrawalsProcess,
                self::CarrierClaimsView,
                self::CouponsView, self::GiftCardsView, self::MembershipView, self::MembershipManage, self::PaymentsReview, self::TravelPromotionsView,
                self::VendorsView, self::ActivityLogView, self::FbnView,
                self::AcquisitionCommissionsView, self::AcquisitionCommissionsManage, self::VendorSubscriptionsView, self::VendorSubscriptionsManage,
                self::ReportsView, self::CelebrityReportsView,
                self::ChangeRequestsView, self::ChangeRequestsReview, self::PackagingView,
                self::ExchangeRatesView, self::ExchangeRatesManage,
                self::LedgerView,
            ],
            'marketing' => [
                self::FlashSalesView, self::FlashSalesManage,
                self::MarketersView, self::MarketersManage, self::MarketerCampaignsView, self::MarketerCampaignsManage,
                self::MarketerSettingsConfigure, self::CrossPromotionManage,
                self::AdsReview, self::CouponParticipationManage, self::AdvertiseInquiriesView, self::AdvertiseInquiriesManage,
                self::TravelPromotionsView, self::TravelPromotionsManage,
                self::PagesView, self::PagesManage, self::LiveMediaView, self::BlogManage, self::HelpCenterManage,
                self::StaticPagesManage, self::FooterManage, self::NotificationTemplatesManage,
                self::CategoriesView, self::BrandsView, self::ProductsView,
                self::CouponsView, self::CouponsManage, self::GiftCardsView, self::GiftCardsManage, self::MembershipView,
                self::CustomersView,
                self::CelebrityReportsView,
            ],
            'vendor_relations' => [
                self::VendorListingsView, self::VendorListingsApprove, self::MarketersView,
                self::ContractTemplatesView, self::ContractTemplatesManage, self::DocumentsView, self::DocumentsReview, self::DocumentTypesConfigure,
                self::ApplicationsView, self::ApplicationsReview, self::JoinOptionsConfigure, self::AdvertiseInquiriesView,
                self::CategoriesView, self::CategoriesManage, self::BrandsView, self::BrandsManage, self::ProductsView, self::ProductsUpdate,
                self::VendorsView, self::VendorsUpdate, self::VendorsAssignAccountManager,
                self::VendorSubscriptionsView,
                self::ProductRequestsView, self::ProductRequestsReview, self::ChangeRequestsView, self::ChangeRequestsReview,
            ],
            /*
            | QC-045, matrix §3.2 "disputes, returns, warranty_claims": Support = A.
            */
            'support' => [
                self::OrdersView, self::ReturnsView, self::ReturnsManage, self::DisputesView, self::DisputesManage,
                self::WarrantyClaimsView, self::WarrantyClaimsManage, self::DeliveryView, self::HelpCenterManage,
                self::ReviewsView, self::ReviewsModerate, self::ProductQaModerate, self::GiftCardsView,
                self::CustomersView, self::CustomersUpdate, self::TicketsView, self::TicketsManage,
            ],
            'content' => [
                self::PagesView, self::PagesManage, self::AppContextsManage, self::LiveMediaView, self::LiveMediaManage,
                self::BlogManage, self::HelpCenterManage, self::StaticPagesManage, self::FooterManage, self::NotificationTemplatesManage,
                self::AdvertiseInquiriesView,
                self::ReviewsView, self::ReviewsModerate, self::ProductQaModerate,
                self::CategoriesView, self::BrandsView, self::ProductsView, self::TicketsView,
            ],
            /*
            | QC-019, matrix §3.3: assigned vendors only, finance hidden.
            */
            /*
            | FBN warehouse staff: stock, receiving, pick & pack, transfers,
            | removals and adjustments; orders read-only.
            */
            'warehouse' => [
                self::FbnView, self::FbnOperate, self::OrdersView, self::DeliveryView,
            ],
            'account_manager' => [
                self::RestrictedToAssignedVendors, self::FinanceHidden,
                self::OrdersView, self::OrdersStatus, self::ReturnsView, self::ReturnsManage,
                self::DisputesView, self::DisputesManage, self::WarrantyClaimsView, self::WarrantyClaimsManage,
                self::VendorListingsView, self::VendorListingsApprove, self::VendorsView, self::VendorsUpdate,
                self::CategoriesView, self::BrandsView, self::ProductsView, self::CouponsView, self::FlashSalesView,
                self::ReviewsView, self::MarketerCampaignsView, self::TicketsView, self::TicketsManage,
            ],
        ];
    }
}
