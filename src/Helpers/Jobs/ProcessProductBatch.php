<?php

namespace Webkul\RestApi\Helpers\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Webkul\RestApi\Helpers\Importers\Product\Importer;

class ProcessProductBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $timeout = 300000;

    protected $batch;

    /**
     * Create a new job instance.
     *
     * @param array $batch
     */
    public function __construct(array $batch)
    {
        $this->batch = $batch;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $importer = app(Importer::class);
        $importer->saveProductsData($this->batch);
        $importer->indexBatch($this->batch);
    }
}
