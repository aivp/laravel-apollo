<?php

    namespace Ling5821\LaravelApollo\Commands;

    use Illuminate\Console\Command;
    use Ling5821\LaravelApollo\ApolloManager;


    class ApolloCmd extends Command
    {
        /**
         * The name and signature of the console command.
         * @var string
         */
        protected $signature = 'env:manager {type} {--action=} {--instance=}';
        /**
         * The console command description.
         * @var string
         */
        protected $description = 'env or config manager';

        /**
         * Create a new command instance.
         * @return void
         */
        public function __construct()
        {
            parent::__construct();
        }

        /**
         * Execute the console command.
         * @return mixed
         */
        public function handle()
        {
            $type   = $this->argument('type');
            $action = $this->option("action");
            switch ($type) {
                case "apollo":
                    app(ApolloManager::class)->up($action);
                    break;
                case "testApollo":
                    while (TRUE) {
                        $this->info(env("ttt", "404"));
                        sleep(1);
                    }
                default :
                    break;
            }
        }


    }
