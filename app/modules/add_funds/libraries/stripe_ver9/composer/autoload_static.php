<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit1eeae4cb938223cf4ae3634c6d7e722b
{
    public static $prefixLengthsPsr4 = array (
        'S' => 
        array (
            'Stripe\\' => 7,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Stripe\\' => 
        array (
            0 => __DIR__ . '/..' . '/stripe/stripe-php/lib',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit1eeae4cb938223cf4ae3634c6d7e722b::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit1eeae4cb938223cf4ae3634c6d7e722b::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit1eeae4cb938223cf4ae3634c6d7e722b::$classMap;

        }, null, ClassLoader::class);
    }
}
