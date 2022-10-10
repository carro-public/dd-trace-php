<?php

namespace DDTrace;

class Type
{
    const CACHE = 'cache';
    const HTTP_CLIENT = 'http';
    const WEB_SERVLET = 'web';
    const CLI = 'cli';
    const SQL = 'sql';

    const MESSAGE_CONSUMER = 'queue';
    const MESSAGE_PRODUCER = 'queue';

    const LARAVEL_TYPE_JOB = 'job';
    const LARAVEL_TYPE_NOTIFICATION = 'notification';
    const LARAVEL_TYPE_LISTENER = 'listener';
    const LARAVEL_TYPE_BROADCAST = 'broadcast';

    const CASSANDRA = 'cassandra';
    const ELASTICSEARCH = 'elasticsearch';
    const MEMCACHED = 'memcached';
    const MONGO = 'mongodb';
    const REDIS = 'redis';
}
