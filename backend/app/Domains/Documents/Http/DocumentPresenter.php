<?php

namespace App\Domains\Documents\Http;

use App\Domains\Documents\Enums\DocumentRequirement;
use App\Domains\Documents\Enums\DocumentStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;

/**
 * App shapes of required document types and uploads (delivery app and the
 * ops apps: vendor, marketer, supervisor, travel agency). Docs:
 * docs/project/storefront-api-additions.md § "Ops-app documents".
 */
class DocumentPresenter
{
    /**
     * Days before `valid_until` from which a document counts as expiring soon.
     */
    public const EXPIRING_SOON_DAYS = 30;

    /**
     * Files the apps may upload (each type caps the size with `max_mb`).
     */
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /**
     * @return array<string, mixed>
     */
    public static function requirement(DocumentType $type): array
    {
        return [
            'id' => $type->id,
            'code' => $type->code,
            'name' => $type->name(),
            'mandatory' => $type->requirement === DocumentRequirement::Mandatory,
            'has_expiry' => (bool) $type->has_expiry,
            'max_mb' => (int) ($type->max_mb ?: 10),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Document $document, ?DocumentType $type = null): array
    {
        $days = $document->valid_until === null ? null : (int) now()->startOfDay()->diffInDays($document->valid_until->startOfDay(), false);

        return [
            'id' => $document->id,
            'document_type_id' => $document->document_type_id,
            'type' => (string) (($type ?? $document->type())?->name() ?? ''),
            'status' => $document->status->value,
            'valid_until' => $document->valid_until?->toDateString(),
            'expires_in_days' => $days,
            'expiring_soon' => $days !== null && $document->status->value === 'approved' && $days <= self::EXPIRING_SOON_DAYS,
            'rejection_reason' => $document->rejection_reason,
            // A rejected or expired document is re-uploaded on the same row (back to pending).
            'can_reupload' => in_array($document->status, [DocumentStatus::Rejected, DocumentStatus::Expired], true),
            'original_name' => $document->original_name,
            'mime' => $document->mime,
            'size' => (int) $document->size,
            'uploaded_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
