<?php

namespace App\Filament\Resources\PackageGrantResource\Pages;

use App\Filament\Resources\PackageGrantResource;
use Filament\Resources\Pages\ListRecords;

class ListPackageGrants extends ListRecords
{
    protected static string $resource = PackageGrantResource::class;

    // Sin acción de "crear": los PackageGrant solo se originan desde
    // PackageService::grantToUser() (UserResource::grantPackage). El panel
    // únicamente reporta.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
