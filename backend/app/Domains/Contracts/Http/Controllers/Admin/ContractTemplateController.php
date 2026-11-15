<?php

namespace App\Domains\Contracts\Http\Controllers\Admin;

use App\Domains\Catalog\Models\Category;
use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Enums\ContractVersionStatus;
use App\Domains\Contracts\Models\ContractAssignment;
use App\Domains\Contracts\Models\ContractSignature;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Models\ContractVersion;
use App\Domains\Contracts\Support\ContractPublisher;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Support\AdminAccess;
use App\Domains\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Contracts (SRS §13.2–13.3, Figma 98:3625, matrix §3.2
 * `contract_templates`): templates of the current country (and legacy
 * global ones, editable by super admins only), the editor with versions
 * (save draft, publish click-wrap or schedule a notice), assignments to
 * categories / products, and the signatures of each version. Kind and
 * country are fixed at creation; a published version is never edited.
 */
class ContractTemplateController extends Controller
{
    public function __construct(private CountryContext $countryContext, private AdminAccess $adminAccess) {}

    public function index(): View
    {
        $country = $this->countryContext->current();

        return view('panels.admin.contracts.index', [
            'templates' => ContractTemplate::query()->where(fn ($query) => $query->where('country_id', $country->id)->orWhereNull('country_id'))
                ->with('publishedVersion')->withCount('versions')->orderBy('kind')->orderBy('name')->get(),
            'kinds' => ContractKind::cases(),
            'country' => $country,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::enum(ContractKind::class)],
            'mode' => ['required', Rule::in(['editor', 'file'])],
            'consent_mode' => ['required', Rule::in(['click_wrap', 'notice'])],
            'global' => ['boolean'],
            'is_default' => ['boolean'],
            'required_for_customers' => ['boolean'],
            'open_call' => ['boolean'],
            'destinations' => ['array'],
            'destinations.*' => [Rule::in(['influencer', 'affiliate', 'broker'])],
        ]);
        $global = (bool) ($data['global'] ?? false);
        abort_if($global && ! $this->adminAccess->isSuperAdmin($request->user()), 403);
        $kind = ContractKind::from($data['kind']);

        $template = ContractTemplate::query()->create([
            'country_id' => $global ? null : $this->countryContext->current()->id,
            'kind' => $kind,
            'name' => $data['name'],
            'mode' => $data['mode'],
            'consent_mode' => $kind->allowsNotice() ? $data['consent_mode'] : 'click_wrap',
            'is_default' => (bool) ($data['is_default'] ?? false),
            'required_for_customers' => (bool) ($data['required_for_customers'] ?? false),
            'open_call' => (bool) ($data['open_call'] ?? false),
            'destinations' => $data['destinations'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.contracts.show', $template->id)->with('status', __('Template created. Write the first draft.'));
    }

    public function show(int $template): View
    {
        $template = $this->template($template)->load('versions', 'assignments');

        return view('panels.admin.contracts.show', [
            'template' => $template,
            'draft' => $template->versions->firstWhere('status', ContractVersionStatus::Draft),
            'categories' => Category::query()->orderBy('name_en')->get(),
            'signatureCounts' => ContractSignature::query()->where('contract_template_id', $template->id)->selectRaw('contract_version_id, status, count(*) as total')->groupBy('contract_version_id', 'status')->get(),
        ]);
    }

    public function saveDraft(Request $request, int $template, ContractPublisher $publisher, SettingsService $settings): RedirectResponse
    {
        $template = $this->editable($request, $template);
        $maxKb = (int) $settings->get('contracts.upload_max_mb', 20) * 1024;
        $data = $request->validate([
            'body_en' => ['nullable', 'string', 'max:200000'],
            'body_ar' => ['nullable', 'string', 'max:200000'],
            'change_summary' => ['nullable', 'string', 'max:255'],
            'file_ar' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:'.$maxKb],
            'file_en' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:'.$maxKb],
        ]);

        $publisher->draft($template, $data, $request->file('file_ar'), $request->file('file_en'));

        return back()->with('status', __('Draft saved.'));
    }

    public function publish(Request $request, int $version, ContractPublisher $publisher): RedirectResponse
    {
        $version = ContractVersion::query()->findOrFail($version);
        $this->editable($request, $version->contract_template_id);
        $effectiveAt = $request->filled('effective_at') ? CarbonImmutable::parse($request->input('effective_at'), $this->countryContext->current()->timezone)->utc() : null;

        try {
            $published = $publisher->publish($version, $request->user()->id, $effectiveAt);
        } catch (TransitionRefused $refused) {
            return back()->withErrors(['action' => $refused->getMessage()]);
        }

        return back()->with('status', $published->status === ContractVersionStatus::Scheduled
            ? __('Version :v scheduled for :date (notice sent to sellers).', ['v' => $published->version, 'date' => $published->effective_at->toDateString()])
            : __('Version :v published. Earlier signatures must be renewed.', ['v' => $published->version]));
    }

    public function assign(Request $request, int $template): RedirectResponse
    {
        $template = $this->editable($request, $template);
        $data = $request->validate([
            'subject_type' => ['required', Rule::in([ContractAssignment::SUBJECT_CATEGORY, ContractAssignment::SUBJECT_PRODUCT])],
            'subject_key' => ['required', 'integer', 'min:1'],
        ]);

        if ($data['subject_type'] === ContractAssignment::SUBJECT_CATEGORY) {
            Category::query()->findOrFail($data['subject_key']);
        }

        ContractAssignment::query()->firstOrCreate([
            'contract_template_id' => $template->id,
            'country_id' => $this->countryContext->current()->id,
            'subject_type' => $data['subject_type'],
            'subject_key' => (string) $data['subject_key'],
        ]);

        return back()->with('status', __('Assignment saved.'));
    }

    public function unassign(Request $request, int $assignment): RedirectResponse
    {
        $assignment = ContractAssignment::query()->where('country_id', $this->countryContext->current()->id)->findOrFail($assignment);
        $this->editable($request, $assignment->contract_template_id);
        $assignment->delete();

        return back()->with('status', __('Assignment removed.'));
    }

    /**
     * Templates of the current country, or legacy ones.
     */
    private function template(int $id): ContractTemplate
    {
        return ContractTemplate::query()->where(fn ($query) => $query->where('country_id', $this->countryContext->current()->id)->orWhereNull('country_id'))->findOrFail($id);
    }

    /**
     * Legacy (global) templates are changed by super admins only.
     */
    private function editable(Request $request, int $id): ContractTemplate
    {
        $template = $this->template($id);
        abort_if($template->country_id === null && ! $this->adminAccess->isSuperAdmin($request->user()), 403);

        return $template;
    }
}
