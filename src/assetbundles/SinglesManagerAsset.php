<?php

namespace thupsi\singlesmanager\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class SinglesManagerAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'dist/js/singles-manager.js',
        ];

        parent::init();
    }
}
