<?php

$vendorDir = dirname(__DIR__);

return array(
    'yiisoft/yii2-swiftmailer' => array(
        'name'    => 'yiisoft/yii2-swiftmailer',
        'version' => '2.0.6.0',
        'alias'   => array(
            '@yii/swiftmailer' => $vendorDir . '/yiisoft/yii2-swiftmailer',
        ),
    ),
    'yiisoft/yii2-bootstrap'   => array(
        'name'    => 'yiisoft/yii2-bootstrap',
        'version' => '2.0.6.0',
        'alias'   => array(
            '@yii/bootstrap' => $vendorDir . '/yiisoft/yii2-bootstrap',
        ),
    ),
    'yiisoft/yii2-debug'       => array(
        'name'    => 'yiisoft/yii2-debug',
        'version' => '2.0.7.0',
        'alias'   => array(
            '@yii/debug' => $vendorDir . '/yiisoft/yii2-debug',
        ),
    ),
    'yiisoft/yii2-gii'         => array(
        'name'    => 'yiisoft/yii2-gii',
        'version' => '2.0.5.0',
        'alias'   => array(
            '@yii/gii' => $vendorDir . '/yiisoft/yii2-gii',
        ),
    ),
    'yiisoft/yii2-faker'       => array(
        'name'    => 'yiisoft/yii2-faker',
        'version' => '2.0.3.0',
        'alias'   => array(
            '@yii/faker' => $vendorDir . '/yiisoft/yii2-faker',
        ),
    ),
    'yiisoft/yii2-redis'       => array(
        'name'    => 'yiisoft/yii2-redis',
        'version' => '2.0.4.0',
        'alias'   => array(
            '@yii/redis' => $vendorDir . '/yiisoft/yii2-redis',
        ),
    ),
    'yiisoft/yii2-httpclient'  => array(
        'name'    => 'yiisoft/yii2-httpclient',
        'version' => '2.0.4.0',
        'alias'   => array(
            '@yii/httpclient' => $vendorDir . '/yiisoft/yii2-httpclient',
        ),
    ),
);
