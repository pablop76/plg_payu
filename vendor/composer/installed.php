<?php return array(
    'root' => array(
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'type' => 'joomla-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'reference' => NULL,
        'name' => 'webservice/hikashop-payu',
        'dev' => true,
    ),
    'versions' => array(
        'openpayu/openpayu' => array(
            'pretty_version' => '2.3.6',
            'version' => '2.3.6.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../openpayu/openpayu',
            'aliases' => array(),
            'reference' => '3d5a609147777e2ba64d72957492457cdd67d239',
            'dev_requirement' => false,
        ),
        'webservice/hikashop-payu' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'type' => 'joomla-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'reference' => NULL,
            'dev_requirement' => false,
        ),
    ),
);
