<?php
namespace vakata\cache\test;

class APCuTest extends \PHPUnit\Framework\TestCase
{
	protected static $cache = null;

	public static function setUpBeforeClass(): void {
		self::$cache = new \vakata\cache\APCu();
		self::$cache->clear();
	}
	public static function tearDownAfterClass(): void {
		self::$cache->clear();
	}

	public function testSet() {
		self::$cache = new \vakata\cache\APCu();

		$this->assertEquals('v1', self::$cache->set('key', 'v1'));
		$this->assertEquals('v2', self::$cache->set('key', 'v2'));
		$this->assertEquals('v3', self::$cache->set('expire', 'v3', 1));
		$this->assertEquals('v3', self::$cache->set('expire2', 'v3', 1));
	}
	/**
	 * @depends testSet
	 */
	public function testGet() {
		$this->assertEquals('v2', self::$cache->get('key'));
	}
	/**
	 * @depends testSet
	 */
	public function testExpire() {
		sleep(2);
		$this->assertEquals(null, self::$cache->get('expire'));
	}
	/**
	 * @depends testSet
	 */
	public function testDelete() {
		self::$cache->delete('key');
		$this->assertEquals(null, self::$cache->get('key'));
	}
	public function testGetSet() {
		self::$cache->getSet('getset', function () { return 'v4'; });
		$this->assertEquals('v4', self::$cache->get('getset'));
	}
	/**
	 * @depends testGetSet
	 */
	public function testClear() {
		self::$cache->clear();
		$this->assertEquals(null, self::$cache->get('getset'));
	}
}
