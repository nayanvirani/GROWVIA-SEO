<?php

namespace App\Support\Enums;

/** Content workflow from section 10.2. Publishing is always a manual status update. */
enum ContentStatus: string
{
    case Idea = 'idea';
    case BriefDraft = 'brief_draft';
    case BriefReviewed = 'brief_reviewed';
    case ContentDraft = 'content_draft';
    case HumanEdit = 'human_edit';
    case ApprovedForExport = 'approved_for_export';
    case Exported = 'exported';
    case PublishedExternally = 'published_externally';
    case PerformanceReview = 'performance_review';
    case Rejected = 'rejected';

    /** Statuses a user may set by hand on a draft. */
    public static function manualDraftTransitions(): array
    {
        return [
            self::HumanEdit->value,
            self::PublishedExternally->value,
            self::PerformanceReview->value,
            self::Rejected->value,
        ];
    }
}
