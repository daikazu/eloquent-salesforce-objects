<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Exceptions;

/**
 * Salesforce rejected the SOQL as invalid (error code MALFORMED_QUERY).
 */
class MalformedQueryException extends SalesforceException {}
