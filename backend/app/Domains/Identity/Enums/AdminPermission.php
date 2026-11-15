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
            ],
            'finance' => [
                self::OrdersView, self::ShippingZonesView, self::CalendarsView, self::WarrantyPlansView, self::PaymentGatewaysView, self::PaymentGatewaysUpdate,
                self::OrdersRefund, self::ReturnsView, self::DisputesView, self::WarrantyClaimsView,
                self::PayoutsView, self::PayoutsApprove, self::PayoutsProcess, self::CodSettlementsView, self::CodSettlementsSettle,
                self::MarketersView, self::MarketerCampaignsView, self::MarketerWithdrawalsProcess,
                self::CarrierClaimsView,
                self::CouponsView, self::GiftCardsView, self::MembershipView, self::MembershipManage, self::PaymentsReview,
                self::VendorsView, self::ActivityLogView,
            ],
            'marketing' => [
                self::FlashSalesView, self::FlashSalesManage,
                self::MarketersView, self::MarketersManage, self::MarketerCampaignsView, self::MarketerCampaignsManage,
                self::MarketerSettingsConfigure, self::CrossPromotionManage,
                self::AdsReview, self::CouponParticipationManage, self::AdvertiseInquiriesView, self::AdvertiseInquiriesManage,
                self::PagesView, self::PagesManage, self::LiveMediaView, self::BlogManage, self::HelpCenterManage,
                self::StaticPagesManage, self::FooterManage, self::NotificationTemplatesManage,
                self::CategoriesView, self::BrandsView, self::ProductsView,
                self::CouponsView, self::CouponsManage, self::GiftCardsView, self::GiftCardsManage, self::MembershipView,
            ],
            'vendor_relations' => [
                self::VendorListingsView, self::VendorListingsApprove, self::MarketersView,
                self::ContractTemplatesView, self::ContractTemplatesManage, self::DocumentsView, self::DocumentsReview, self::DocumentTypesConfigure,
                self::ApplicationsView, self::ApplicationsReview, self::JoinOptionsConfigure, self::AdvertiseInquiriesView,
                self::CategoriesView, self::CategoriesManage, self::BrandsView, self::BrandsManage, self::ProductsView, self::ProductsUpdate,
                self::VendorsView, self::VendorsUpdate, self::VendorsAssignAccountManager,
            ],
            'support' => [self::OrdersView, self::ReturnsView, self::DisputesView, self::WarrantyClaimsView, self::DeliveryView, self::HelpCenterManage, self::ReviewsView, self::ReviewsModerate, self::ProductQaModerate, self::GiftCardsView],
            'content' => [
                self::PagesView, self::PagesManage, self::AppContextsManage, self::LiveMediaView, self::LiveMediaManage,
                self::BlogManage, self::HelpCenterManage, self::StaticPagesManage, self::FooterManage, self::NotificationTemplatesManage,
                self::AdvertiseInquiriesView,
                self::ReviewsView, self::ReviewsModerate, self::ProductQaModerate,
                self::CategoriesView, self::BrandsView, self::ProductsView,
            ],
            /*
            | QC-019, matrix §3.3: assigned vendors only, finance hidden.
            */
            'account_manager' => [
                self::RestrictedToAssignedVendors, self::FinanceHidden,
                self::OrdersView, self::OrdersStatus, self::ReturnsView, self::ReturnsManage,
                self::DisputesView, self::DisputesManage, self::WarrantyClaimsView, self::WarrantyClaimsManage,
                self::VendorListingsView, self::VendorListingsApprove, self::VendorsView, self::VendorsUpdate,
                self::CategoriesView, self::BrandsView, self::ProductsView, self::CouponsView, self::FlashSalesView,
                self::ReviewsView, self::MarketerCampaignsView,
            ],
        ];
    }
}
