<?php
namespace vakata\cache\test;

class RedisTest extends \PHPUnit\Framework\TestCase
{
	protected static $cache = null;

	public static function setUpBeforeClass(): void {
		self::$cache = new \vakata\cache\Redis();
		self::$cache->clear();
		self::$cache->clear('test');
	}
	public static function tearDownAfterClass(): void {
		self::$cache->clear();
		self::$cache->clear('test');
	}

	public function testSet() {
		self::$cache = new \vakata\cache\Redis();

		$this->assertEquals('v1', self::$cache->set('key', 'v1'));
		$this->assertEquals('v2', self::$cache->set('key', 'v2', 'cache'));
		$this->assertEquals('v3', self::$cache->set('expire', 'v3', 'cache', 1));
		$this->assertEquals('v3', self::$cache->set('expire2', 'v3', null, 1));
	}
	/**
	 * @depends testSet
	 */
	public function testGet() {
		$this->assertEquals('v1', self::$cache->get('key'));
		$this->assertEquals('v2', self::$cache->get('key', null, 'cache'));
	}
	/**
	 * @depends testSet
	 */
	public function testExpire() {
		sleep(2);
		$this->assertEquals(null, self::$cache->get('expire', null, 'cache'));
	}
	/**
	 * @depends testSet
	 */
	public function testDelete() {
		self::$cache->delete('key', 'cache');
		$this->assertEquals(null, self::$cache->get('key', null, 'cache'));
	}
	public function testGetSet() {
		self::$cache->getSet('getset', function () { return 'v4'; }, 'cache');
		$this->assertEquals('v4', self::$cache->get('getset', null, 'cache'));
	}
	/**
	 * @depends testGetSet
	 */
	public function testClear() {
		self::$cache->clear('cache');
		$this->assertEquals(null, self::$cache->get('getset'));
	}
}
