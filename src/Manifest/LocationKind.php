<?php

declare(strict_types=1);

namespace PrivacyCI\Manifest;

/** The sort of store a location lives in. */
enum LocationKind: string
{
    case DatabaseColumn = 'database_column';
    case WarehouseColumn = 'warehouse_column';
    case RedisKey = 'redis_key';
    case ObjectStorage = 'object_storage';
    case SearchIndex = 'search_index';
    case ExternalService = 'external_service';
    case LogStream = 'log_stream';
}
