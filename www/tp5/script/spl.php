<?php

/**
 * autoload_function    callable    是    待注册的自动加载函数。如果为 null，则注册默认的 spl_autoload()。
 * throw    bool    否    当 autoload_function 无法成功注册时，是否抛出异常。默认值为 true。
 * prepend    bool    否    是否将该函数添加至加载队列的最前面。如果为 true，它会优先于其他已注册的加载器执行。
 */
spl_autoload_register('autoload',true,false);

function autoload($class){
    echo "类名" . $class;
    include "./{$class}.php";
}
$a = new Ceshi();
$a->hello();