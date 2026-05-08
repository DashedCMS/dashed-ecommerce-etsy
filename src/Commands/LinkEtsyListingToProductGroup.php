<?php

namespace Dashed\DashedEcommerceEtsy\Commands;

use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Illuminate\Console\Command;

class LinkEtsyListingToProductGroup extends Command
{
    protected $signature = 'dashed-etsy:link-listing
        {group_id : Het Dashed ProductGroup ID}
        {listing_id : Het Etsy listing_id (uit de listing-URL of API)}';

    protected $description = 'Koppel een Etsy listing_id aan een Dashed ProductGroup zodat sync de juiste producten kan matchen';

    public function handle(): int
    {
        $groupId = (int) $this->argument('group_id');
        $listingId = (string) $this->argument('listing_id');

        $group = ProductGroup::find($groupId);
        if (! $group) {
            $this->error("ProductGroup #{$groupId} niet gevonden.");

            return self::FAILURE;
        }

        $group->etsy_listing_id = $listingId;
        $group->save();

        $this->info('Group #'.$group->id.' ('.$group->getRawOriginal('name').') gekoppeld aan Etsy listing '.$listingId.'.');

        return self::SUCCESS;
    }
}
