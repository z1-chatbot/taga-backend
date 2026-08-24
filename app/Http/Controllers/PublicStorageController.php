<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves files from the `public` disk at /storage/{path}.
 *
 * Normally this is the web server's job: `php artisan storage:link` puts a
 * symlink at public/storage and the file is served statically, no PHP involved.
 * That is faster and is what Laravel expects.
 *
 * It did not work on this host, and the reason turned out to be two separate
 * problems wearing one 403.
 *
 * The first was ours. config/filesystems.php had `'serve' => true` on the
 * *private* `local` disk, which makes Laravel register its own
 * `GET storage/{path}` route for signed temporary URLs. That route occupies the
 * exact path the public disk is served from, and answers anything without a
 * valid signature with 403. Any /storage request that reached PHP was refused
 * before it could get near a file. Reproduced locally, and it is now off.
 *
 * The second may or may not exist: whether this host's LiteSpeed will follow
 * the symlink at all is still untested, since the application was rejecting the
 * request first. Rather than find out across another deploy cycle, .htaccess
 * now routes /storage into the front controller unconditionally, which is
 * correct either way.
 *
 * Serving it here is the same thing prescriptions and licence documents have
 * always done, which is what makes it safe to rely on: PHP reading a file it
 * owns is not something this host has ever objected to.
 *
 * The cost is a PHP process per image rather than a static file read. At this
 * traffic that is fine, and the cache headers below mean a returning visitor
 * revalidates with a 304 instead of re-downloading.
 *
 * This is public by design — banners, product photographs, sale artwork.
 * Anything confidential belongs on the `local` disk behind an authenticated
 * route, which is where prescriptions already are. Nothing private should ever
 * be written to the `public` disk on the assumption that this route guards it,
 * because it does not.
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
