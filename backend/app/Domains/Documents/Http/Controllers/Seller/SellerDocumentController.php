<?php

namespace App\Domains\Documents\Http\Controllers\Seller;

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seller Center → Documents (SRS §13.7): required store documents of the
 * country, product certifications per category, uploads (private disk) and
 * their review status.
 */
class SellerDocumentController extends Controller
{
    public function __construct(private VendorWorkspace $workspace, private Documents $documents, private CountryContext $countryContext) {}

    public function index(): View
    {
        $vendor = $this->workspace->vendor();

        return view('panels.partner.documents.index', [
            'requirements' => $this->documents->requirements(DocumentOwner::Vendor),
            'certificationTypes' => DocumentType::query()->where('country_id', $this->countryContext->current()->id)->where('business_type', DocumentOwner::Certification)->where('is_active', true)->get(),
            'categories' => Category::query()->where('requires_certification', true)->orderBy('name_en')->get(),
            'uploads' => Document::query()->where('owner_type', 'vendor')->where('owner_id', $vendor->id)->with('category')->latest('id')->get(),
            'missing' => $this->documents->missingMandatory(DocumentOwner::Vendor, 'vendor', $vendor->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $vendor = $this->workspace->vendor();
        $type = DocumentType::query()->where('country_id', $this->countryContext->current()->id)
            ->whereIn('business_type', [DocumentOwner::Vendor, DocumentOwner::Certification])
            ->findOrFail($request->integer('document_type_id'));
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.($type->max_mb * 1024)],
            'category_id' => ['nullable', 'integer', 'exists:'.(new Category)->getConnectionName().'.categories,id'],
            'valid_until' => ['nullable', 'date', 'after:today'],
        ]);

        $this->documents->upload($type, 'vendor', $vendor->id, $request->file('file'), $request->user()->id, $data['category_id'] ?? null, $data['valid_until'] ?? null);

        return back()->with('status', __('Uploaded. Nawy will review it.'));
    }

    public function download(int $document): StreamedResponse
    {
        $document = Document::query()->where('owner_type', 'vendor')->where('owner_id', $this->workspace->vendor()->id)->findOrFail($document);

        return Storage::disk(Documents::DISK)->download($document->file_path, $document->original_name);
    }
}
