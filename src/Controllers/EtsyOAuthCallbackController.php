<?php

namespace Dashed\DashedEcommerceEtsy\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;

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
            return $this->redirectToSettings('OAuth callback ontbrak parameters.', false);
        }

        $expectedState = Customsetting::get('etsy_oauth_state', $siteId);
        if ($state !== $expectedState) {
            return $this->redirectToSettings('OAuth state-mismatch (CSRF). Probeer opnieuw.', false);
        }

        $redirectUri = url('/dashed/etsy/oauth/callback?site_id='.urlencode($siteId));
        $ok = Etsy::exchangeCodeForTokens($siteId, $code, $redirectUri);

        return $this->redirectToSettings(
            $ok ? 'Etsy is gekoppeld.' : 'Koppeling mislukt; check de instellingen.',
            $ok
        );
    }

    private function redirectToSettings(string $message, bool $success)
    {
        $target = Route::has('filament.dashed.pages.etsy-settings-page')
            ? route('filament.dashed.pages.etsy-settings-page')
            : url('/dashed/etsy-settings-page');

        return redirect()->to($target)->with($success ? 'success' : 'error', $message);
    }
}
