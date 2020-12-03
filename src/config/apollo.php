<?php

    return [
        'server' => '',
        'appid' => '',
        /**
         * 指定要拉取哪些namespace的配置
         * 顺序很重要，如果namespace有相同的键值，后加载的会覆盖先加载的
         * 默认我们认为application在从apollo获取的配置中具有最高的优先级
         */
        'namespaces' => [
            'application'
        ],
        'accessKeySecret' => NULL,
        'cluster' => 'default',
        'maxLoopSeconds' => 3600
    ];
