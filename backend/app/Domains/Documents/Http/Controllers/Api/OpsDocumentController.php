<?php

namespace App\Domains\Documents\Http\Controllers\Api;

use App\Domains\Delivery\Models\Carrier;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Http\DocumentPresenter;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Support\Documents;
use App\Domains\Marketers\Enums\MarketerJobType;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Panels\Http\Controllers\Api\Concerns\RefusesTransitions;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ops apps documents (`GET|POST ops/{vendor|marketer|travel|supervisor}/documents`,
 * `GET …/documents/{id}/file`): the workspace's required document types of
 * the country and its uploads, reusing the Documents domain rules (SRS
 * §13.7) exactly as Seller Center / the delivery app do: types per
 * business type (product store → vendor, Open Souq store → classified,
 * travel agency → agency, marketer → marketer or broker, shipping company
 * → carrier), the type's `max_mb`, `valid_until` required when the type
 * has an expiry, a new upload is pending until Nawy reviews it, and a
 * rejected or expired one is re-uploaded on the same row (back to pending).
 */
class OpsDocumentController extends Controller
{
    use RefusesTransitions;

    public function __construct(private Documents $documents) {}

    public function index(Request $request): JsonResponse
    {
        [$business, $ownerType, $ownerId] = $this->owner($request);
        $requirements = $this->documents->requirements($business);
        $types = $requirements->keyBy('id');

        return ApiResponse::success([
            'requirements' => $requirements->map(fn (DocumentType $type): array => DocumentPresenter::requirement($type))->values()->all(),
            'uploads' => Document::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->whereNull('category_id')->latest('id')->get()
                ->map(fn (Document $document): array => DocumentPresenter::document($document, $types->get($document->document_type_id)))->all(),
            'missing' => $this->documents->missingMandatory($business, $ownerType, $ownerId)->pluck('id')->values()->all(),
        ], ['business_type' => $business->value, 'mimes' => DocumentPresenter::MIMES, 'expiring_soon_days' => DocumentPresenter::EXPIRING_SOON_DAYS]);
    }

    /**
     * Multipart `document_type_id`, `file` (pdf / jpg / jpeg / png / webp, at
     * most the type's `max_mb`, 20 MB cap) and `valid_until` (Y-m-d, after
     * today; required when the type has an expiry).
     */
    public function store(Request $request): JsonResponse
    {
        [$business, $ownerType, $ownerId] = $this->owner($request);
        $type = $this->documents->requirements($business)->firstWhere('id', $request->integer('document_type_id'));
        $data = $request->validate([
            'document_type_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($type): void {
                if ($type === null) {
                    $fail(__('This document is not needed.'));
                }
            }],
            'file' => ['required', 'file', 'mimes:'.implode(',', DocumentPresenter::MIMES), 'max:'.(1024 * min(20, (int) ($type?->max_mb ?: 10)))],
            'valid_until' => [$type?->has_expiry ? 'required' : 'nullable', 'date_format:Y-m-d', 'after:today'],
        ]);

        return $this->refusable(fn (): JsonResponse => ApiResponse::success(DocumentPresenter::document(
            $this->documents->upload($type, $ownerType, $ownerId, $request->file('file'), $request->user()->id, null, $data['valid_until'] ?? null),
        ), status: 201));
    }

    /**
     * The workspace's own uploaded file (private disk).
     */
    public function file(Request $request, int $document): StreamedResponse
    {
        [, $ownerType, $ownerId] = $this->owner($request);
        $document = Document::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)->findOrFail($document);

        return Storage::disk(Documents::DISK)->download($document->file_path, $document->original_name);
    }

    /**
     * @return array{0: DocumentOwner, 1: string, 2: int}
     */
    private function owner(Request $request): array
    {
        $workspace = $request->attributes->get('ops_workspace');

        return match (true) {
            $workspace instanceof Vendor => [match ($workspace->type) {
                'travel' => DocumentOwner::Agency,
                'classified' => DocumentOwner::Classified,
                default => DocumentOwner::Vendor,
            }, 'vendor', $workspace->id],
            $workspace instanceof MarketerProfile => [
                $workspace->hasJobType(MarketerJobType::Broker) && ! $workspace->hasJobType(MarketerJobType::Influencer) && ! $workspace->hasJobType(MarketerJobType::Affiliate)
                    ? DocumentOwner::Broker : DocumentOwner::Marketer,
                'marketer', $workspace->id,
            ],
            $workspace instanceof Carrier => [DocumentOwner::Carrier, 'carrier', $workspace->id],
            default => abort(403),
        };
    }
}
