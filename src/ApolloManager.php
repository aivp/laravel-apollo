<?php


    namespace Ling5821\LaravelApollo;

    use Illuminate\Support\Str;
    use Ling5821\LaravelApollo\Apollo\ApolloClient;

    class ApolloManager
    {
        private $apollo;

        /**
         * ServiceNode constructor.
         */
        public function __construct()
        {
            $server = config('apollo.server');
            $appid = config('apollo.appid');
            $namespaces = config('apollo.namespaces');
            $accessKeySecret = config('apollo.accessKeySecret');
            $cluster = config('apollo.cluster');
            $maxLoopSeconds = config('apollo.maxLoopSeconds');

            $apollo = new ApolloClient($server, $appid, $namespaces);
            $apollo->setUpdateResponseAllConfig(TRUE);
            $apollo->setAccessKeySecret($accessKeySecret);
            $apollo->setCluster($cluster);
            $apollo->setMaxLoopSeconds($maxLoopSeconds);
            $this->apollo = $apollo;

        }


        public function up($action) {
            //定义apollo配置变更时的回调函数，动态异步更新.env
            $callback = function ($apolloClient, $newLoadConfigs) {
                if (!$newLoadConfigs || count(collect($newLoadConfigs)->filter(function ($value, $key) {
                        return $value;
                    })) == 0) {
                    return;
                }

                $fullConfigMap = [];

                foreach ($newLoadConfigs as $namespace => $configContent) {
                    $this->configTextCover($configContent, $fullConfigMap);
                }

                $fullConfigText = '';

                if ($fullConfigMap) {
                    foreach ($fullConfigMap as $key => $value) {
                        $fullConfigText .= "$key=$value\n";
                    }
                }

                if ($fullConfigText) {
                    file_put_contents('.env', $fullConfigText);
                }
            };
            if ($action == 'start') {
                $this->apollo->start($callback); //此处传入回调
            } else if ($action == 'pull') {
                $this->apollo->setMaxLoopSeconds(20);
                $this->apollo->start($callback); //此处传入回调
            }
        }


        /**
         * 将properties格式的配置文本覆盖填充到最终配置数组中。
         *
         * @param string $configContent
         * @param array  $fullConfigMap
         * @param bool   $operateTransferred 是否处理java properties保存时产生的转义。
         */
        function configTextCover($configContent, &$fullConfigMap, $operateTransferred = TRUE)
        {
            $lines = preg_split('/\\n|\\r\\n|\\r/', $configContent);
            foreach ($lines as $line) {
                if (Str::startsWith($line, '#')) {
                    continue;
                }

                $matched = preg_match('/([^=]+)=(.+)/', $line, $matchResult);
                if ($matched) {
                    if ($operateTransferred) {
                        // 处理由于java properties保存时产生的原始文件转义
                        $value = preg_replace('/\\\\([:=\\#!])/', '$1', $matchResult[2]);
                    } else {
                        $value = $matchResult[2];
                    }

                    // 中间存在空格的值外部增加引号，不然php读取会报错
                    if (Str::contains($value, ' ') && $value[0] != '"') {
                        if (Str::contains($value, '#')) {
                            $value = rtrim(preg_split('/#/', $value)[0]);
                        }

                        $value = "\"$value\"";
                    }

                    $fullConfigMap[ $matchResult[1] ] = $value;
                }
            }
        }

    }