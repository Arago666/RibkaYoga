<?php

namespace App\Modules\Practices\PublicApi;

use App\Modules\Access\PublicApi\WorkspaceContext;
use App\Modules\Practices\Services\DocumentSharing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Authorizes an immutable analysis image without granting access to its source workspace. */
class ReviewMediaAccess
{
    public function __construct(private WorkspaceContext $workspace, private DocumentSharing $sharing) {}

    public function authorize(string $reviewId, string $assetId): void
    {
        abort_unless(Str::isUuid($reviewId) && Str::isUuid($assetId), 404);
        $review = DB::table('practice_reviews')->where('id', strtolower($reviewId))
            ->where('workspace_id', $this->workspace->id())->where('user_id', $this->workspace->userId())->first();
        abort_unless($review, 404);
        $this->sharing->access($review->source_document_id);
        abort_unless(DB::table('practice_review_assets')->where('review_id', $review->id)
            ->where('image_asset_id', strtolower($assetId))->exists(), 404);
    }
}
