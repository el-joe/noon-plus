<?php

namespace App\Domains\Documents\Support;

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Documents\Enums\DocumentOwner;
use App\Domains\Documents\Enums\DocumentRequirement;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Documents per country × business type × category (SRS §13.7): which
 * types apply, uploads to the private disk (pending), admin review (approve,
 * reject with a reason) and expiry. Product certifications are documents of
 * a `certification` type for a category; an approved, unexpired one for the
 * category or a parent clears the ListingCertificationGate.
 */
class Documents
{
    public const DISK = 'local';

    public function __construct(private CountryContext $countryContext, private StateMachine $machine) {}

    /**
     * Types that apply (mandatory or optional) for a business type, most
     * specific category row first; N/A rows hide the general row.
     *
     * @return Collection<int, DocumentType>
     */
    public function requirements(DocumentOwner $owner, ?int $categoryId = null): Collection
    {
        $types = DocumentType::query()
            ->where('country_id', $this->countryContext->current()->id)
            ->where('business_type', $owner)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('category_id')->when($categoryId !== null, fn ($inner) => $inner->orWhere('category_id', $categoryId)))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return $types->groupBy('code')
            ->map(fn (Collection $rows): DocumentType => $rows->sortByDesc(fn (DocumentType $type): int => $type->category_id === null ? 0 : 1)->first())
            ->reject(fn (DocumentType $type): bool => $type->requirement === DocumentRequirement::NotApplicable)
            ->values();
    }

    public function upload(DocumentType $type, string $ownerType, int $ownerId, UploadedFile $file, ?int $userId = null, ?int $categoryId = null, ?string $validUntil = null): Document
    {
        if ($type->country_id !== $this->countryContext->current()->id) {
            throw new TransitionRefused(__('This document type belongs to another country.'));
        }

        if ($type->requirement === DocumentRequirement::NotApplicable) {
            throw new TransitionRefused(__('This document is not needed.'));
        }

        if ($type->business_type === DocumentOwner::Certification && $categoryId === null) {
            $categoryId = $type->category_id ?? throw new TransitionRefused(__('Choose the category this certification is for.'));
        }

        $path = $file->storeAs('documents/'.$this->countryContext->current()->code.'/'.$ownerType.'/'.$ownerId, Str::uuid().'.'.$file->getClientOriginalExtension(), self::DISK);

        $document = Document::query()->create([
            'document_type_id' => $type->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'category_id' => $categoryId,
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'status' => DocumentStatus::Pending,
            'valid_until' => $validUntil,
            'uploaded_by_user_id' => $userId,
        ]);
        $this->machine->created($document, $ownerType === 'vendor' ? ActorType::Vendor : ActorType::Customer, $userId);

        return $document;
    }

    public function approve(Document $document, int $adminUserId): Document
    {
        return $this->machine->move($document, DocumentStatus::Approved, ActorType::Admin, $adminUserId, null, [
            'reviewed_by_user_id' => $adminUserId, 'reviewed_at' => now(), 'rejection_reason' => null,
        ]);
    }

    public function reject(Document $document, int $adminUserId, string $reason): Document
    {
        if (trim($reason) === '') {
            throw new TransitionRefused(__('Give a reason for the rejection.'));
        }

        return $this->machine->move($document, DocumentStatus::Rejected, ActorType::Admin, $adminUserId, $reason, [
            'reviewed_by_user_id' => $adminUserId, 'reviewed_at' => now(), 'rejection_reason' => mb_substr($reason, 0, 255),
        ]);
    }

    /**
     * Approved documents past `valid_until` expire (daily).
     */
    public function expire(): int
    {
        $expired = 0;

        Document::query()->where('status', DocumentStatus::Approved)->whereNotNull('valid_until')->whereDate('valid_until', '<', now()->toDateString())
            ->each(function (Document $document) use (&$expired): void {
                $this->machine->move($document, DocumentStatus::Expired, ActorType::System);
                $expired++;
            });

        return $expired;
    }

    /**
     * ListingCertificationGate: an approved, unexpired certification of the
     * store for the category or one of its parents.
     */
    public function hasApprovedCertification(int $vendorId, Category $category): bool
    {
        $certificationTypes = DocumentType::query()
            ->where('country_id', $this->countryContext->current()->id)
            ->where('business_type', DocumentOwner::Certification)
            ->pluck('id');

        return Document::query()
            ->where('owner_type', 'vendor')
            ->where('owner_id', $vendorId)
            ->whereIn('document_type_id', $certificationTypes)
            ->whereIn('category_id', array_map(fn (Category $ancestor): int => $ancestor->id, $category->lineage()))
            ->where('status', DocumentStatus::Approved)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))
            ->exists();
    }

    /**
     * Mandatory types without a pending or approved upload.
     *
     * @return Collection<int, DocumentType>
     */
    public function missingMandatory(DocumentOwner $owner, string $ownerType, int $ownerId): Collection
    {
        $held = Document::query()->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->whereIn('status', [DocumentStatus::Pending, DocumentStatus::Approved])->pluck('document_type_id')->all();

        return $this->requirements($owner)
            ->filter(fn (DocumentType $type): bool => $type->requirement === DocumentRequirement::Mandatory && ! in_array($type->id, $held, true))
            ->values();
    }
}
