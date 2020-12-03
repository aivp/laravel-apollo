<?php

    namespace Ling5821\LaravelApollo\Apollo {

        use Carbon\Carbon;

        class ApolloClient
        {
            public    $save_dir; //apollo服务端地址
            protected $configServer; //apollo配置项目的appid
            protected $appId;
            protected $cluster                 = 'default'; //绑定IP做灰度发布用
            protected $clientIp                = '127.0.0.1';
            protected $notifications           = []; //获取某个namespace配置的请求超时时间
            protected $pullTimeout             = 10; //每次请求获取apollo配置变更时的超时时间
            protected $intervalTimeout         = 0; //是否写到配置文件，默认不写
            protected $writeToConfigFile       = FALSE; //监控的命名的空间。
            protected $namespaces              = []; //配置更新返回完整配置还是只返回更新的配置，默认只返回更新了的日志。
            protected $updateResponseAllConfig = FALSE; //配置保存目录
            protected $maxLoopSeconds          = 3600; // 默认轮询时间
            protected $accessKeySecret         = NULL; // Apollo鉴权密钥

            /**
             * ApolloClient constructor.
             *
             * @param string $configServer apollo服务端地址
             * @param string $appId        apollo配置项目的appid
             * @param array  $namespaces   apollo配置项目的namespace
             */
            public function __construct($configServer, $appId, array $namespaces)
            {
                $this->configServer = $configServer;
                $this->appId        = $appId;
                $this->namespaces   = $namespaces;
                foreach ($namespaces as $namespace) {
                    $this->notifications[ $namespace ] = ['namespaceName' => $namespace, 'notificationId' => -1];
                }
                $this->save_dir = dirname($_SERVER['SCRIPT_FILENAME']);
            }

            public function setCluster($cluster)
            {
                $this->cluster = $cluster;
            }

            public function setClientIp($ip)
            {
                $this->clientIp = $ip;
            }

            public function setPullTimeout($pullTimeout)
            {
                $pullTimeout       = intval($pullTimeout);
                $this->pullTimeout = $pullTimeout;
            }

            public function setIntervalTimeout($intervalTimeout)
            {
                $intervalTimeout       = intval($intervalTimeout);
                $this->intervalTimeout = $intervalTimeout;
            }

            public function setWriteToConfigFile($isWrite)
            {
                $this->writeToConfigFile = $isWrite;
            }

            public function setUpdateResponseAllConfig($updateResponseAllConfig)
            {
                $this->updateResponseAllConfig = $updateResponseAllConfig;
            }

            public function setMaxLoopSeconds($maxLoopSeconds)
            {
                $this->maxLoopSeconds = $maxLoopSeconds;
            }

            public function setAccessKeySecret($accessKeySecret)
            {
                $this->accessKeySecret = $accessKeySecret;
            }

            public function pullConfig($namespaceName)
            {
                $base_api = rtrim($this->configServer, '/') . '/configfiles/' . $this->appId . '/' . $this->cluster . '/';
                $api      = $base_api . $namespaceName;

                $args               = [];
                $args['ip']         = $this->clientIp;
                $config_file        = $this->getConfigFile($namespaceName);
                $args['releaseKey'] = $this->_getReleaseKey($config_file);

                $api .= '?' . http_build_query($args);

                $ch = curl_init($api);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->pullTimeout);
                curl_setopt($ch, CURLOPT_HEADER, FALSE);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

                $body     = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error    = curl_error($ch);
                curl_close($ch);

                if ($httpCode == 200) {
                    if ($this->writeToConfigFile) {
                        file_put_contents($config_file, $body);
                    }
                    return $body;
                } elseif ($httpCode != 304) {
                    echo $body ?: $error . "\n";
                    return FALSE;
                }
                return TRUE;
            }

            //获取单个namespace的配置文件路径

            public function getConfigFile($namespaceName)
            {
                return $this->save_dir . DIRECTORY_SEPARATOR . 'apolloConfig.' . $namespaceName . '.php';
            }

            //获取单个namespace的配置-无缓存的方式

            private function _getReleaseKey($config_file)
            {
                $releaseKey = '';
                if (file_exists($config_file)) {
                    $last_config = require $config_file;
                    is_array($last_config) && isset($last_config['releaseKey']) && $releaseKey = $last_config['releaseKey'];
                }
                return $releaseKey;
            }

            //获取多个namespace的配置-无缓存的方式

            /**
             * @param $callback 监听到配置变更时的回调处理
             *
             * @return mixed
             */
            public function start($callback = NULL)
            {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->intervalTimeout);
                curl_setopt($ch, CURLOPT_HEADER, FALSE);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                try {
                    $this->_listenChange($ch, $callback);
                } catch (\Exception $e) {
                    curl_close($ch);
                    return $e->getMessage();
                }
            }

            protected function _listenChange(&$ch, $callback = NULL)
            {
                $base_url          = rtrim($this->configServer, '/') . '/notifications/v2?';
                $params            = [];
                $params['appId']   = $this->appId;
                $params['cluster'] = $this->cluster;
                $startTimestamp    = Carbon::now()->timestamp;
                do {
                    $params['notifications'] = json_encode(array_values($this->notifications));
                    $query                   = http_build_query($params);
                    curl_setopt($ch, CURLOPT_URL, $base_url . $query);
                    if ($this->accessKeySecret) {
                        $header    = [];
                        $now       = Carbon::now();
                        $timestamp = intval($now->timestamp * 1000 + $now->micro / 1000);
                        $header[]  = "Timestamp: $timestamp";
                        $signature = base64_encode(hash_hmac('sha1', "$timestamp\n/notifications/v2?$query",
                                                             $this->accessKeySecret, TRUE));
                        $header[]  = "Authorization: Apollo $this->appId:$signature";
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
                    }
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error    = curl_error($ch);
                    if ($httpCode == 200) {
                        $res         = json_decode($response, TRUE);
                        $change_list = [];
                        foreach ($res as $r) {
                            if ($r['notificationId'] != $this->notifications[ $r['namespaceName'] ]['notificationId']) {
                                $change_list[ $r['namespaceName'] ] = $r['notificationId'];
                            }
                        }

                        if ($this->updateResponseAllConfig) {
                            $response_list = $this->pullConfigBatch($this->namespaces);
                        } else {
                            $response_list = $this->pullConfigBatch(array_keys($change_list));
                        }

                        foreach ($response_list as $namespaceName => $result) {
                            $result && array_key_exists($namespaceName, $change_list)
                            && ($this->notifications[ $namespaceName ]['notificationId'] = $change_list[ $namespaceName ]);
                        }

                        //如果定义了配置变更的回调，比如重新整合配置，则执行回调
                        ($callback instanceof \Closure) && call_user_func($callback, $this, $response_list);
                    }

                    $currentTimestamp = Carbon::now()->timestamp;
                } while ($currentTimestamp - $startTimestamp < $this->maxLoopSeconds);
            }

            public function pullConfigBatch(array $namespaceNames)
            {
                if (!$namespaceNames) {
                    $namespaceNames = $this->namespaces;
                }

                $multi_ch         = curl_multi_init();
                $request_list     = [];
                $path             = '/configfiles/' . $this->appId . '/' . $this->cluster . '/';
                $base_url         = rtrim($this->configServer, '/') . $path;
                $query_args       = [];
                $query_args['ip'] = $this->clientIp;
                foreach ($namespaceNames as $namespaceName) {
                    $request                  = [];
                    $config_file              = $this->getConfigFile($namespaceName);
                    $request_url              = $base_url . $namespaceName;
                    $query_args['releaseKey'] = $this->_getReleaseKey($config_file);
                    $query_string             = '?' . http_build_query($query_args);
                    $request_url              .= $query_string;
                    $ch                       = curl_init($request_url);
                    curl_setopt($ch, CURLOPT_TIMEOUT, $this->pullTimeout);
                    curl_setopt($ch, CURLOPT_HEADER, FALSE);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    if ($this->accessKeySecret) {
                        $header    = [];
                        $now       = Carbon::now();
                        $timestamp = intval($now->timestamp * 1000 + $now->micro / 1000);
                        $header[]  = "Timestamp: $timestamp";
                        $signature = base64_encode(hash_hmac('sha1', "$timestamp\n$path$namespaceName$query_string", $this->accessKeySecret, TRUE));
                        $header[]  = "Authorization: Apollo $this->appId:$signature";
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
                    }
                    $request['ch']                  = $ch;
                    $request['config_file']         = $config_file;
                    $request_list[ $namespaceName ] = $request;
                    curl_multi_add_handle($multi_ch, $ch);
                }

                $active = NULL;
                // 执行批处理句柄
                do {
                    $mrc = curl_multi_exec($multi_ch, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);

                while ($active && $mrc == CURLM_OK) {
                    if (curl_multi_select($multi_ch) == -1) {
                        usleep(100);
                    }
                    do {
                        $mrc = curl_multi_exec($multi_ch, $active);
                    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

                }

                // 获取结果
                $response_list = [];
                foreach ($request_list as $namespaceName => $req) {
                    $response_list[ $namespaceName ] = TRUE;
                    $result                          = curl_multi_getcontent($req['ch']);
                    $code                            = curl_getinfo($req['ch'], CURLINFO_HTTP_CODE);
                    $error                           = curl_error($req['ch']);
                    curl_multi_remove_handle($multi_ch, $req['ch']);
                    curl_close($req['ch']);
                    if ($code == 200) {
                        if ($this->writeToConfigFile) {
                            file_put_contents($req['config_file'], $result);
                        }
                        $response_list[ $namespaceName ] = $result;
                    } elseif ($code != 304) {
                        echo 'pull config of namespace[' . $namespaceName . '] error:' . ($result ?: $error) . "\n";
                        $response_list[ $namespaceName ] = FALSE;
                    }
                }
                curl_multi_close($multi_ch);
                return $response_list;
            }
        }
    }
