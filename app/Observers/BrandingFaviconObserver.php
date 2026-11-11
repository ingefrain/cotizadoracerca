<?php

namespace App\Observers;

use App\Models\ApplicationBranding;
use App\Models\Document;
use App\Services\BrandingFavicon;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class BrandingFaviconObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Document $document): void
    {
        $this->refresh($document);
    }

    public function deleted(Document $document): void
    {
        $this->refresh($document);
    }

    public function restored(Document $document): void
    {
        $this->refresh($document);
    }

    private function refresh(Document $document): void
    {
        if ($document->documentable_type === (new ApplicationBranding)->getMorphClass()
            && (string) $document->documentable_id === '1') {
            app(BrandingFavicon::class)->forLogo(ApplicationBranding::current()->logo);
        }
    }
}
