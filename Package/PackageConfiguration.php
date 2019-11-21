<?php

namespace Webkul\UVDesk\ApiBundle\Package;

use Webkul\UVDesk\PackageManager\Composer\ComposerPackage;
use Webkul\UVDesk\PackageManager\Composer\ComposerPackageExtension;

class PackageConfiguration extends ComposerPackageExtension
{
    public function loadConfiguration()
    {
        $composerPackage = new ComposerPackage();
        $composerPackage
            ->combineProjectConfig('config/packages/security.yaml', 'Templates/package/security.yaml');
        
        return $composerPackage;
    }
}
