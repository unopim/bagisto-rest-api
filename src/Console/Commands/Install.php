<?php

namespace Webkul\RestApi\Console\Commands;

use Illuminate\Console\Command;

class Install extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bagisto-rest-api:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish L5SwaggerServiceProvider provider, view and config files.';

    /**
     * Install and configure bagisto rest api.
     */
    public function handle()
    {
        $this->warn('Step: Publishing L5Swagger Provider File...');

        $configFilePath = config_path('l5-swagger.php');
        if (file_exists($configFilePath)) {
            $this->warn('File [config/l5-swagger.php] already exists. Deleting the old file...');
            unlink($configFilePath); // Delete the existing file
        }
        $result = shell_exec('php artisan vendor:publish --tag=bagisto-rest-api-swagger');
        $this->info($result);

        $this->warn('Step: Generate l5-swagger docs (Admin & Shop)...');
        $result = shell_exec('php artisan l5-swagger:generate --all');
        $this->info($result);

        $this->comment('-----------------------------');
        $this->comment('Success: Bagisto REST API has been configured successfully.');
    }
}
