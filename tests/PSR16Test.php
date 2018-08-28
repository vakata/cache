<?php

namespace vakata\cache\test;

class PSR16Test extends \PHPUnit_Framework_TestCase
{
	protected static $dir = null;
	protected static $cache = null;

	public static function setUpBeforeClass() {
		self::$dir = __DIR__ . '/cache';
		mkdir(self::$dir);
		self::$cache = new \vakata\cache\Filecache(self::$dir);
		self::$cache->clear();
        self::$cache->clear('psr16');
        self::$cache = new \vakata\cache\PSR16Adapter(self::$cache, 'psr16');
	}
	public static function tearDownAfterClass() {
		self::$cache->clear();
		rmdir(self::$dir.'/psr16');
		rmdir(self::$dir);
	}
	protected function setUp() {
	}
	protected function tearDown() {
	}

	public function testSet() {
		$this->assertEquals(true, self::$cache->set('key', 'v1'));
		$this->assertEquals(true, self::$cache->set('key', 'v2'));
		$this->assertEquals(true, self::$cache->set('expire', 'v3', 1));
	}
	/**
	 * @depends testSet
	 */
	public function testGet() {
		$this->assertEquals('v2', self::$cache->get('key'));
		$this->assertEquals('default', self::$cache->get('missing', 'default'));
	}
	/**
	 * @depends testSet
	 */
	public function testExpire() {
		sleep(2);
		$this->assertEquals(null, self::$cache->get('expire', null));
	}
	/**
	 * @depends testSet
	 */
	public function testDelete() {
		self::$cache->delete('key');
		$this->assertEquals(null, self::$cache->get('key', null));
	}
	public function testClear() {
        $this->assertEquals(true, self::$cache->set('cleared', 'v1'));
        $this->assertEquals('v1', self::$cache->get('cleared'));
		self::$cache->clear();
		$this->assertEquals(null, self::$cache->get('cleared'));
	}
}
