<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves files from the `public` disk at /media/{path}.
 *
 * Laravel's own arrangement is a public/storage symlink served statically by the
 * web server, with no PHP involved. Neither half of that survives this host, and
 * it took three wrong diagnoses to establish why, so the evidence is recorded
 * here rather than left to be rediscovered.
 *
 * The prefix. Hostinger blocks /storage at the web server, ahead of PHP. What
 * proved it: a request for a file that does not exist returned 403 from
 * LiteSpeed with no `x-powered-by` header, while the identical .jpg under any
 * other prefix reached Laravel and 404'd properly. Nothing in application code
 * or file permissions can reach that decision. It is a reasonable rule for a
 * shared host to enforce, since /storage is exactly where a careless Laravel
 * deploy exposes its logs, so this is worked around rather than argued with.
 *
 * The route. config/filesystems.php had `'serve' => true` on the *private*
 * `local` disk, which makes Laravel register its own `GET storage/{path}` route
 * for signed URLs. It occupied the same path the public disk is served from and
 * answered every unsigned request with 403 — a second, independent 403 hiding
 * behind the first. Reproduced locally and now off. Nothing generates signed
 * URLs here; prescriptions and licence documents stream through authenticated
 * controllers, which is stricter.
 *
 * Two false leads, for the record: file permissions (`namei` showed the whole
 * path traversable and the file 0644) and the symlink itself (deleting it
 * changed nothing, because the prefix was blocked either way).
 *
 * Serving through PHP is the same thing prescriptions have always done, and is
 * what makes it dependable here: reading a file the application owns is not
 * something this host has ever objected to. The cost is a PHP process per image
 * instead of a static read; the cache headers below turn repeat visits into
 * 304s. Whether a public/media symlink would allow static serving is untested —
 * worth trying only if image traffic ever justifies it.
 *
 * This is public by design. Anything confidential belongs on the `local` disk
 * behind an authenticated route. Nothing private should be written to the
 * `public` disk on the assumption that this route guards it, because it does
 * not.
 */
class PublicStorageController extends Controller
{
    /**
     * Extensions this route will serve.
     *
     * An allowlist rather than a denylist. The `public` disk takes uploads, and
     * while a stray .php file served through here would be sent as text rather
     * than executed, there is no reason to hand back anything that is not the
     * media it claims to be.
     *
     * SVG is deliberately absent. It can carry script, and served from
     * api.taga.ng it would run as same-origin to the API. Nothing on this site
     * needs to upload one.
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'pdf',
    ];

    /** A week. Long enough to be worth it; short enough that a replaced banner lands. */
    private const MAX_AGE = 604800;

    public function __invoke(Request $request, string $path): Response
    {
        $path = ltrim($path, '/');

        // Reject traversal before touching the filesystem. `..` is the obvious
        // one; a null byte can truncate a path inside C string handling; a
        // backslash is a separator on some platforms and noise on this one.
        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, "\0")
            || str_contains($path, '\\')) {
            abort(404);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            abort(404);
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            abort(404);
        }

        $absolute = $disk->path($path);

        // Belt and braces: resolve both sides and confirm the file really sits
        // inside the disk root. `exists()` above already goes through the disk,
        // but a symlink *within* storage could still point outside it.
        $root = realpath($disk->path(''));
        $real = realpath($absolute);
        if ($root === false || $real === false || ! str_starts_with($real, $root)) {
            abort(404);
        }

        $response = response()->file($real, [
            // The browser must not second-guess the type. Without this, a file
            // whose bytes look like HTML could be sniffed and rendered.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->setAutoLastModified();

        // Turns a repeat request into a 304 with no body. Cheaper than the
        // static serving we cannot have, for everyone who has been here before.
        $response->isNotModified($request);

        return $response;
    }
}
