<?php

namespace Webkul\RestApi\Tests;

use Tests\TestCase;

/**
 * Base test case for the REST API package.
 *
 * Extends the host Bagisto test case (which uses DatabaseTransactions), so the
 * feature tests run against the already-seeded application database and roll
 * back any changes they make.
 */
class RestApiTestCase extends TestCase {}
