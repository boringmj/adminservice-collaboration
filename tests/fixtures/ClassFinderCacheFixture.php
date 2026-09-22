<?php

namespace Tests\Fixtures;

/**
 * `ClassFinder` 缓存用例的样例层次
 *
 * - 故意把接口与实现放在**同一个文件**里: `require_once` 时两者一起被声明,
 *   查找就不再依赖"别处是否顺带加载过实现类"—— 那个依赖是**真实存在**的脆弱点
 *   (见 `ClassFinder::findDirectSubClassRecursive` 的"已知限制"注释与 `ClassFinderCacheTest` 的说明),
 *   在这里把它排除掉, 用例才只测缓存本身
 */
interface ClassFinderCacheInterface {
}

class ClassFinderCacheImpl implements ClassFinderCacheInterface {
}

/**
 * 故意**不给**实现: 用来验证"此刻没找到"这个否定结果不会被缓存住
 * (用例里用 `eval` 再声明一个实现, 必须能立刻找到)
 */
interface ClassFinderCacheLazyInterface {
}
