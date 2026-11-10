<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\ManagedDocumentPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AvatarController extends Controller
{
    public function own(Request $request, ManagedDocumentPath $paths): StreamedResponse
    {
        $request->user()->unsetRelation('avatar');
        abort_unless($document = $request->user()->avatar, 404);

        return $this->image($document, $request->user(), $paths, false, $request);
    }

    public function user(Request $request, User $user, ManagedDocumentPath $paths): StreamedResponse
    {
        abort_unless($request->user()->can('users.view'), 403);
        abort_unless($document = $user->avatar, 404);

        return $this->image($document, $user, $paths, false, $request);
    }

    public static function response(Document $document, ManagedDocumentPath $paths, bool $public, Request $request, ?string $category = null): StreamedResponse
    {
        return (new self)->image($document, null, $paths, $public, $request, $category);
    }

    private function image(Document $document, ?User $owner, ManagedDocumentPath $paths, bool $public, Request $request, ?string $category = null): StreamedResponse
    {
        abort_unless($document->category === ($category ?? ($public ? 'branding.logo' : 'user.avatar')), 404);
        if ($owner && (! ($document->documentable instanceof User) || ! $document->documentable->is($owner))) {
            abort(404);
        }
        abort_unless(in_array($document->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        abort_unless($paths->matches($document), 404);
        try {
            $disk = Storage::disk($document->disk);
            abort_unless($disk->exists($document->path), 404);
            $etag = '"'.substr($document->checksum, 0, 64).'"';
            $headers = [
                'Content-Type' => $document->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Cache-Control' => $public ? 'public, max-age=3600, stale-while-revalidate=60' : 'private, max-age=300',
                'ETag' => $etag,
            ];
            if ($request->headers->get('If-None-Match') === $etag) {
                return response()->stream(fn () => null, 304, $headers);
            }

            return response()->stream(function () use ($disk, $document): void {
                $stream = $disk->readStream($document->path);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, $headers);
        } catch (Throwable) {
            abort(404);
        }
    }
}
