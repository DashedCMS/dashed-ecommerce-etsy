<?php

namespace Dashed\DashedEcommerceEtsy\Controllers;

use App\Http\Controllers\Controller;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EtsyOAuthStartController extends Controller
{
    public function __invoke(Request $request, string $siteId)
    {
        if (! Auth::check() || (Auth::user()->role ?? null) !== 'superadmin') {
            abort(403);
        }

        if (! Etsy::clientId($siteId)) {
            return redirect()->to(url('/dashed/etsy-settings-page'))
                ->with('error', 'Vul eerst de keystring + secret in en sla op voordat je verbindt.');
        }

        $redirectUri = url('/dashed/etsy/oauth/callback?site_id='.urlencode($siteId));
        $payload = Etsy::buildAuthorizeUrl($siteId, $redirectUri);

        return redirect()->away($payload['url']);
    }
}
