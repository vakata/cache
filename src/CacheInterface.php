<?php

namespace vakata\cache;

interface CacheInterface
{
    /**
     * изчиства всички стойности от даден дял, ако не е посочен дял, се изчиствя делът по подразбиране.
     *
     * @method clear
     *
     * @param string $partition дял за изчистване, ако не е посочен се изчиства делът по подразбиране
     */
    public function clear($partition = null);
    /**
     * подготвя ключ за попълване, удобно при времеемки операции. Ако ключът е в режим на чакане на стойност, при поискване от друг скрипт, скрипта ще изчака за стойност. Ако времето за изчаване изтече (5 секунди), скрипта ще продължи.
     *
     * @method prepare
     *
     * @param string $key       ключът, който заключваме
     * @param string $partition делът, в който е ключа, ако не е посочен се използва делът по подразбиране
     */
    public function prepare($key, $partition = null);
    public function set($key, $value, $partition = null, $expires = 14400);
    public function get($key, $partition = null, $metaOnly = false);
    public function delete($key, $partition = null);
    public function getSet($key, callable $value = null, $partition = null, $time = 14400);
}
