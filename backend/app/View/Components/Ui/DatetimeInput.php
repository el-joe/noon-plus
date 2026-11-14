<?php

namespace App\View\Components\Ui;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Country date-time field (MASTER_PLAN ADR 14): the admin types the country's
 * local time and sees the Oman equivalent under the field. Stored value: UTC.
 */
class DatetimeInput extends Component
{
    public string $localValue = '';

    public ?string $adminTimeLabel = null;

    public function __construct(
        public string $label,
        public string $name,
        public Country $country,
        ?CarbonImmutable $value = null,
        CountryClock $clock = new CountryClock,
    ) {
        if ($value !== null) {
            $this->localValue = $clock->inCountry($country, $value)->format('Y-m-d\TH:i');
            $this->adminTimeLabel = $clock->adminTimeLabel($country, $this->localValue);
        }
    }

    public function render(): View
    {
        return view('components.ui.datetime-input');
    }
}
