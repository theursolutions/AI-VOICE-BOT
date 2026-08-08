<?php

use App\Http\Controllers\SeoFilesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Crawler files — /robots.txt, /sitemap.xml
|--------------------------------------------------------------------------
|
| Registered by RouteServiceProvider with NO middleware group. They were
| briefly in routes/web.php, which meant every bot fetch ran StartSession
| and came back with a Set-Cookie header. Two problems with that:
|
|   • bots fetch robots.txt constantly, and each fetch minted a throwaway
|     session record;
|   • `Cache-Control: public` together with `Set-Cookie` is a combination
|     shared caches either refuse to store (so the caching was dead) or —
|     worse — store and replay someone else's cookie to the next visitor.
|
| Nothing here reads the session, the authenticated user, or a CSRF token,
| so the whole web group is dead weight. The two global middleware
| (RedirectTrailingSlash, NoIndexPrivateAreas) still apply — they are in
| the kernel's global stack, not this group.
|
*/

Route::get('/robots.txt', [SeoFilesController::class, 'robots'])->name('seo.robots');
Route::get('/sitemap.xml', [SeoFilesController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/sitemap-{n}.xml', [SeoFilesController::class, 'sitemapChunk'])
    ->where('n', '[0-9]+')
    ->name('seo.sitemap.chunk');
