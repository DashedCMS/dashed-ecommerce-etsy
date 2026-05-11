<?php

namespace Dashed\DashedEcommerceEtsy\Commands;

use Illuminate\Console\Command;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;

class RefreshEtsyToken extends Command
{
    protected $signature = 'dashed-etsy:refresh-token';

    protected $description = 'Vernieuw Etsy access tokens voor alle gekoppelde sites';

    public function handle(): int
    {
        foreach (Sites::getSites() as $site) {
            $siteId = (string) $site['id'];
            if (! Etsy::isConnected($siteId)) {
                continue;
            }
            $ok = Etsy::refreshAccessToken($siteId);
            $this->line("Site {$siteId}: ".($ok ? 'token ververst' : 'refresh faalde'));
        }

        return self::SUCCESS;
    }
}
