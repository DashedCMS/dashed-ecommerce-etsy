<?php

namespace Dashed\DashedEcommerceEtsy\Controllers;

use App\Http\Controllers\Controller;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EtsyOAuthCallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! Auth::check() || (Auth::user()->role ?? null) !== 'superadmin') {
            abort(403);
        }

        $siteId = (string) $request->query('site_id', '');
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($siteId === '' || $code === '' || $state === '') {
            return redirect('/dashed/settings')
                ->with('error', 'OAuth callback ontbrak parameters.');
        }

        $expectedState = Customsetting::get('etsy_oauth_state', $siteId);
        if ($state !== $expectedState) {
            return redirect('/dashed/settings')
                ->with('error', 'OAuth state-mismatch (CSRF). Probeer opnieuw.');
        }

        $redirectUri = url('/dashed/etsy/oauth/callback?site_id='.urlencode($siteId));
        $ok = Etsy::exchangeCodeForTokens($siteId, $code, $redirectUri);

        return redirect('/dashed/settings')
            ->with($ok ? 'success' : 'error', $ok ? 'Etsy is gekoppeld.' : 'Koppeling mislukt; check de instellingen.');
    }
}
