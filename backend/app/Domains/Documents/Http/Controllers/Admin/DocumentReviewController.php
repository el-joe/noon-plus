<?php

namespace App\Domains\Documents\Http\Controllers\Admin;

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Enums\DocumentRequirement;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Document review queue and Document Types (SRS §13.7, matrix §3.2
 * `documents`, `document_types`): pending uploads and certifications of
 * the current country (approve, reject with reason, private download) and
 * the requirement matrix country × business type × category.
 */
class DocumentReviewController extends Controller
{
    public function __construct(private CountryContext $countryContext, private Documents $documents) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', DocumentStatus::Pending->value);

        return view('panels.admin.documents.index', [
            'documents' => Document::query()->where('status', $status)->with('category')->oldest('id')->paginate(25)->withQueryString(),
            'types' => DocumentType::query()->where('country_id', $this->countryContext->current()->id)->get()->keyBy('id'),
            'status' => $status,
            'statuses' => DocumentStatus::cases(),
        ]);
    }

    public function review(Request $request, int $document): RedirectResponse
    {
        $document = Document::query()->findOrFail($document);

        $request->input('decision') === 'approve'
            ? $this->documents->approve($document, $request->user()->id)
            : $this->documents->reject($document, $request->user()->id, (string) $request->input('reason'));

        return back()->with('status', __('Document reviewed.'));
    }

    public function download(int $document): StreamedResponse
    {
        $document = Document::query()->findOrFail($document);

        return Storage::disk(Documents::DISK)->download($document->file_path, $document->original_name);
    }

    public function types(): View
    {
        return view('panels.admin.documents.types', [
            'types' => DocumentType::query()->where('country_id', $this->countryContext->current()->id)->orderBy('business_type')->orderBy('sort_order')->get(),
            'categories' => Category::query()->orderBy('name_en')->get()->keyBy('id'),
            'owners' => DocumentOwner::cases(),
            'requirements' => DocumentRequirement::cases(),
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:48'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'business_type' => ['required', Rule::enum(DocumentOwner::class)],
            'category_id' => ['nullable', 'integer'],
            'requirement' => ['required', Rule::enum(DocumentRequirement::class)],
            'has_expiry' => ['boolean'],
            'max_mb' => ['nullable', 'integer', 'between:1,50'],
        ]);

        if (isset($data['category_id'])) {
            Category::query()->findOrFail($data['category_id']);
        }

        DocumentType::query()->updateOrCreate(
            ['country_id' => $this->countryContext->current()->id, 'code' => $data['code'], 'business_type' => $data['business_type'], 'category_id' => $data['category_id'] ?? null],
            ['name_en' => $data['name_en'], 'name_ar' => $data['name_ar'], 'requirement' => $data['requirement'], 'has_expiry' => (bool) ($data['has_expiry'] ?? false), 'max_mb' => $data['max_mb'] ?? 10],
        );

        return back()->with('status', __('Document type saved.'));
    }
}
