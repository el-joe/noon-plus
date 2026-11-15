<?php

namespace App\Domains\Onboarding\Enums;

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Identity\Enums\MembershipRole;

/**
 * The join sections of the portal (MASTER_PLAN §5a, PORTAL_PAGES §2): each
 * one has its wizard steps, the document business type its requirements
 * come from, the agreement signed at submission and the panel it opens.
 */
enum ApplicationType: string
{
    case Vendor = 'vendor';
    case Classified = 'classified';
    case TravelAgency = 'travel_agency';
    case Marketer = 'marketer';
    case Broker = 'broker';
    case Carrier = 'carrier';

    /**
     * Wizard steps in order. `documents` is complete when every mandatory
     * document is uploaded; `agreement` is completed by submitting.
     *
     * @return array<int, string>
     */
    public function steps(): array
    {
        return match ($this) {
            self::Vendor, self::Classified => ['account', 'store', 'address', 'documents', 'agreement'],
            self::TravelAgency => ['account', 'agency'],
            self::Marketer, self::Broker => ['account', 'profile', 'documents', 'agreement'],
            self::Carrier => ['account', 'company', 'documents', 'agreement'],
        };
    }

    /**
     * Steps whose data is posted by the applicant (not virtual).
     *
     * @return array<int, string>
     */
    public function dataSteps(): array
    {
        return array_values(array_diff($this->steps(), ['documents', 'agreement']));
    }

    public function documentOwner(): DocumentOwner
    {
        return match ($this) {
            self::Vendor => DocumentOwner::Vendor,
            self::Classified => DocumentOwner::Classified,
            self::TravelAgency => DocumentOwner::Agency,
            self::Marketer => DocumentOwner::Marketer,
            self::Broker => DocumentOwner::Broker,
            self::Carrier => DocumentOwner::Carrier,
        };
    }

    public function contractKind(): ContractKind
    {
        return match ($this) {
            self::Vendor, self::Classified => ContractKind::SellerPlatform,
            self::TravelAgency => ContractKind::TravelAgency,
            self::Marketer => ContractKind::MarketerOnboarding,
            self::Broker => ContractKind::Broker,
            self::Carrier => ContractKind::ShippingCompany,
        };
    }

    /**
     * The join option (settings key `join_options.{section}`) that must be
     * enabled in the country for this wizard.
     */
    public function joinSection(): string
    {
        return match ($this) {
            self::Vendor => 'vendor',
            self::Classified => 'open_souq',
            self::TravelAgency => 'travel_agency',
            self::Marketer, self::Broker => 'marketer',
            self::Carrier => 'shipping',
        };
    }

    /**
     * Membership the approval grants; marketers get one per job type.
     */
    public function membershipRole(): MembershipRole
    {
        return match ($this) {
            self::Vendor => MembershipRole::ProductVendor,
            self::Classified => MembershipRole::ClassifiedSeller,
            self::TravelAgency => MembershipRole::TravelAgency,
            self::Marketer => MembershipRole::Affiliate,
            self::Broker => MembershipRole::Broker,
            self::Carrier => MembershipRole::CarrierSupervisor,
        };
    }

    /**
     * Panel key (config `nawy.panels`) the approved partner signs in to.
     */
    public function panel(): string
    {
        return match ($this) {
            self::Vendor, self::Classified => 'partner',
            self::TravelAgency => 'travel_agency',
            self::Marketer, self::Broker => 'marketer',
            self::Carrier => 'carrier',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Vendor => __('Product seller'),
            self::Classified => __('Open Souq seller'),
            self::TravelAgency => __('Travel agency'),
            self::Marketer => __('Creator (influencer / affiliate)'),
            self::Broker => __('Real-estate broker'),
            self::Carrier => __('Shipping company'),
        };
    }
}
