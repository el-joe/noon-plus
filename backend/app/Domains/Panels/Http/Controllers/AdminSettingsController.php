<?php

namespace App\Domains\Panels\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Admin → Platform settings hub: links to the country-scoped configuration
 * screens the signed-in admin may open.
 */
class AdminSettingsController extends Controller
{
    public function __invoke(): View
    {
        return view('panels.admin.settings.index', [
            'sections' => [
                ['route' => 'admin.delivery', 'ability' => 'shipping_zones.view', 'title' => __('Shipping zones & rates'), 'text' => __('Zones, cities and areas, delivery methods and fees.')],
                ['route' => 'admin.settings.holidays', 'ability' => 'calendars.view', 'title' => __('Holidays'), 'text' => __('Country calendar skipped by ETAs, SLAs and payouts.')],
                ['route' => 'admin.catalog.warranty-plans', 'ability' => 'warranty_plans.view', 'title' => __('Warranty plans'), 'text' => __('Extended warranty plans sold at checkout.')],
                ['route' => 'admin.countries.payment-gateways', 'ability' => 'payment_gateways.view', 'title' => __('Payment gateways'), 'text' => __('Gateways, credentials, limits and the recommended method at checkout.')],
                ['route' => 'admin.flash-sales', 'ability' => 'flash_sales.view', 'title' => __('Flash sales'), 'text' => __('Events, submission windows and seller offers.')],
            ],
        ]);
    }
}
