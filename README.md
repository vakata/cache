# cache

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Build Status][ico-travis]][link-travis]
[![Code Climate][ico-cc]][link-cc]
[![Tests Coverage][ico-cc-coverage]][link-cc]

A collection of caching classes with a common interface (currently file cache and memcached are supported).

## Install

Via Composer

``` bash
$ composer require vakata/cache
```

## Usage

``` php
// create an instance (with file based cache)
// caches will be stored in the dir specified by the first argument
$cache = new \vakata\cache\Filecache(__DIR__ . '/cache'); 
// to use Memcached instead simply create a memcached instance:
// $cache = new \vakata\cache\Memcache(); // by default connects to 127.0.0.1

// simple get / set
$cache->set('key', 'value'); // key is stored and "value" is returned
$cache->get('key'); // "value"

// using prepare ensures that a single client updates the cache at any given moment
$cache->prepare('long-running-operation');
$data = long_running_operation();
$cache->set('long-running-operation', $data);

// there is a special getSet method which gets the current key value and if it does not exist - invokes a callable, stores the result and returns it:
$cache->getSet('some-key', function () {
    return some_long_running_operation();
});
```

Read more in the [API docs](docs/README.md)

## Testing

``` bash
$ composer test
```


## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email github@vakata.com instead of using the issue tracker.

## Credits

- [vakata][link-author]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/vakata/cache.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/vakata/cache/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/vakata/cache.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/vakata/cache.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/vakata/cache.svg?style=flat-square
[ico-cc]: https://img.shields.io/codeclimate/github/vakata/cache.svg?style=flat-square
[ico-cc-coverage]: https://img.shields.io/codeclimate/coverage/github/vakata/cache.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/vakata/cache
[link-travis]: https://travis-ci.org/vakata/cache
[link-scrutinizer]: https://scrutinizer-ci.com/g/vakata/cache/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/vakata/cache
[link-downloads]: https://packagist.org/packages/vakata/cache
[link-author]: https://github.com/vakata
[link-contributors]: ../../contributors
[link-cc]: https://codeclimate.com/github/vakata/cache

