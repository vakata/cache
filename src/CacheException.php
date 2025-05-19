<?php

namespace vakata\cache;
use Psr\SimpleCache\CacheException as CE;

class CacheException extends \Exception implements CE
{
}
