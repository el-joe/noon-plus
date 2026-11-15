<?php

namespace App\Domains\Onboarding\Enums;

/**
 * Partner application (SRS §4.1.5 "Applications", Figma application status
 * pages): draft → submitted → under_review → approved | rejected |
 * changes_requested; changes_requested → submitted again. A draft or an
 * application waiting for changes can be withdrawn. Rejected applicants
 * start a new application.
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Withdrawn],
            self::Submitted => [self::UnderReview, self::Approved, self::Rejected, self::ChangesRequested],
            self::UnderReview => [self::Approved, self::Rejected, self::ChangesRequested],
            self::ChangesRequested => [self::Submitted, self::Withdrawn],
            self::Approved, self::Rejected, self::Withdrawn => [],
        };
    }

    /**
     * The applicant may edit steps and documents.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }

    /**
     * Still open: blocks a second application with the same email and type.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Draft, self::Submitted, self::UnderReview, self::ChangesRequested];
    }

    /**
     * Waiting for an admin decision.
     *
     * @return array<int, self>
     */
    public static function inReview(): array
    {
        return [self::Submitted, self::UnderReview];
    }
}
