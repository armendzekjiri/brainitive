<?php

namespace App\Http\Controllers;

use App\Support\Legal\LegalInfo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Imprint, privacy policy, terms, cookies, sub-processors and DPA (BRAI-156). Content lives in the
 * pages (resources/js/Pages/Legal/*); the operator's facts come from LegalInfo (config/legal.php).
 * Brainitive Cloud shows its own documents; self-hosted installs show an installation notice with
 * their operator's data, and the Cloud-only documents (sub-processors, DPA) don't exist there.
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return Inertia::render('Privacy', LegalInfo::pageProps('privacy'));
    }

    public function terms(): Response
    {
        return Inertia::render('Terms', LegalInfo::pageProps('terms'));
    }

    public function cookies(): Response
    {
        return Inertia::render('Cookies', LegalInfo::pageProps('cookies'));
    }

    public function imprint(): Response|RedirectResponse
    {
        abort_unless(LegalInfo::hasImprint(), 404);

        $external = LegalInfo::externalDocuments()['imprint_url'];
        $operator = LegalInfo::operator();
        // self-hosted with only a link to the operator's own imprint: send people there
        if (! LegalInfo::isCloud() && $external && ! filled($operator['name']) && ! filled($operator['address'])) {
            return redirect()->away($external);
        }

        return Inertia::render('Legal/Imprint', LegalInfo::pageProps('imprint'));
    }

    public function subprocessors(): Response
    {
        abort_unless(LegalInfo::isCloud(), 404);

        return Inertia::render('Legal/Subprocessors', LegalInfo::pageProps('subprocessors'));
    }

    public function dpa(): Response
    {
        abort_unless(LegalInfo::isCloud(), 404);

        return Inertia::render('Legal/Dpa', LegalInfo::pageProps('dpa'));
    }
}
