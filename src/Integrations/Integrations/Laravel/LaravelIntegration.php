<?php

namespace DDTrace\Integrations\Laravel;

use ReflectionClass;
use DDTrace\SpanData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class LaravelIntegration extends Integration
{
    const NAME = 'laravel';

    const UNNAMED_ROUTE = 'unnamed_route';

    /**
     * @var string
     */
    private $serviceName;

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    /**
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $rootSpan = \DDTrace\root_span();

        if (null === $rootSpan) {
            if (getenv('DD_TRACE_CLI_ENABLED')) {
                return $this->workerInit();
            }

            return Integration::NOT_LOADED;
        }

        $integration = $this;

        \DDTrace\trace_method(
            'Illuminate\Foundation\Application',
            'handle',
            function (SpanData $span, $args, $response) use ($rootSpan, $integration) {
                // Overwriting the default web integration
                $rootSpan->name = 'laravel.request';
                $integration->addTraceAnalyticsIfEnabled($rootSpan);
                if (\method_exists($response, 'getStatusCode')) {
                    $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                }
                $rootSpan->service = $integration->getServiceName();

                $span->name = 'laravel.application.handle';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = 'Illuminate\Foundation\Application@handle';
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Routing\Router',
            'findRoute',
            null,
            function ($This, $scope, $args, $route) use ($rootSpan, $integration) {
                if (!isset($route)) {
                    return;
                }

                /** @var \Illuminate\Http\Request $request */
                list($request) = $args;

                // Overwriting the default web integration
                $integration->addTraceAnalyticsIfEnabled($rootSpan);
                $routeName = LaravelIntegration::normalizeRouteName($route->getName());

                $rootSpan->resource = $route->getActionName() . ' ' . $routeName;

                $rootSpan->meta['laravel.route.name'] = $routeName;
                $rootSpan->meta['laravel.route.action'] = $route->getActionName();

                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($request->fullUrl());
                }
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->method();
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Routing\Route',
            'run',
            function (SpanData $span) use ($integration) {
                $span->name = 'laravel.action';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = $this->uri;
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Http\Response',
            'send',
            function ($This, $scope, $args) use ($rootSpan, $integration) {
                if (isset($This->exception) && $This->getStatusCode() >= 500) {
                    $integration->setError($rootSpan, $This->exception);
                }
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Events\Dispatcher',
            'fire',
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'laravel.event.handle';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = $args[0];
            }
        );

        \DDTrace\trace_method('Illuminate\View\View', 'render', function (SpanData $span) use ($integration) {
            $span->name = 'laravel.view.render';
            $span->type = Type::WEB_SERVLET;
            $span->service = $integration->getServiceName();
            $span->resource = $this->view;
        });

        \DDTrace\trace_method(
            'Illuminate\View\Engines\CompilerEngine',
            'get',
            function (SpanData $span, $args) use ($integration, $rootSpan) {
                // This is used by both laravel and lumen. For consistency we rename it for lumen traces as otherwise
                // users would see a span changing name as they upgrade to the new version.
                $span->name = $integration->isLumen($rootSpan) ? 'lumen.view' : 'laravel.view';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                if (isset($args[0]) && \is_string($args[0])) {
                    $span->resource = $args[0];
                }
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Foundation\ProviderRepository',
            'load',
            function (SpanData $span) use ($rootSpan, $integration) {
                $serviceName = $integration->getServiceName();
                $span->name = 'laravel.provider.load';
                $span->type = Type::WEB_SERVLET;
                $span->service = $serviceName;
                $span->resource = 'Illuminate\Foundation\ProviderRepository::load';
                $rootSpan->name = 'laravel.request';
                $rootSpan->service = $serviceName;
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Console\Application',
            '__construct',
            function () use ($rootSpan, $integration) {
                $rootSpan->name = 'laravel.artisan';
                $rootSpan->resource = !empty($_SERVER['argv'][1]) ? 'artisan ' . $_SERVER['argv'][1] : 'artisan';
            }
        );

        // renderException is since Symfony 4.4, use "renderThrowable()" instead
        // Used by Laravel < v7.0
        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderException',
            function ($This, $scope, $args) use ($rootSpan, $integration) {
                $integration->setError($rootSpan, $args[0]);
            }
        );

        // Used by Laravel > v7.0
        // More details: https://github.com/laravel/framework/commit/f81b6ed01fb60580ade8c7fb4386aff4cb4d7719
        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderThrowable',
            function ($This, $scope, $args) use ($rootSpan, $integration) {
                $integration->setError($rootSpan, $args[0]);
            }
        );

        return Integration::LOADED;
    }

    public function workerInit()
    {
        $integration = $this;

        \DDTrace\trace_method('Illuminate\\Queue\\Worker', 'process', function (SpanData $spanData, $args) use ($integration) {
            $serviceName = $integration->getServiceName();
            $spanData->service = $serviceName;

            $payload = $args[1]->payload();
            $data = $payload['data'];
            $spanData->resource = $integration->getJobName($args[1]);
            $spanData->name = 'worker-process';
            $spanData->type = $integration->getJobType($data['commandName']);
        });

        \DDTrace\trace_method('Bref\\LaravelBridge\\Queue\\LaravelSqsHandler', 'process', function (SpanData $spanData, $args) use ($integration) {
            $serviceName = $integration->getServiceName();
            $spanData->service = $serviceName;

            $payload = $args[1]->payload();
            $data = $payload['data'];
            $spanData->resource = $integration->getJobName($args[1]);
            $spanData->name = 'worker-process';
            $spanData->type = $integration->getJobType($data['commandName']);
        });

        \DDTrace\trace_method('Illuminate\\Queue\\Worker', 'handleJobException', function (SpanData $spanData, $args) use ($integration) {
            $payload = $args[1]->payload();
            $data = $payload['data'];
            $spanData->name = 'job-handle-exception';
            $spanData->parent->meta[Tag::JOB_PAYLOAD] = $integration->getJobPayload(unserialize($data['command']));
        });

        \DDTrace\trace_method('Bref\\LaravelBridge\\Queue\\LaravelSqsHandler', 'raiseExceptionOccurredJobEvent', function (SpanData $spanData, $args) use ($integration) {
            $payload = $args[1]->payload();
            $data = $payload['data'];
            $spanData->name = 'job-handle-exception';
            $spanData->parent->meta[Tag::JOB_PAYLOAD] = $integration->getJobPayload(unserialize($data['command']));
        });

        return Integration::LOADED;
    }

    public function getJobType($commandName)
    {
        switch ($commandName) {
            case 'Illuminate\Broadcasting\BroadcastEvent':
                return Type::LARAVEL_TYPE_BROADCAST;
            case 'Illuminate\Notifications\SendQueuedNotifications':
                return Type::LARAVEL_TYPE_NOTIFICATION;
            case 'Illuminate\Events\CallQueuedListener':
                return Type::LARAVEL_TYPE_LISTENER;
            default:
                return Type::LARAVEL_TYPE_JOB;
        }
    }

    public function getJobName($job)
    {
        return $job ? $job->resolveName() : 'unknown_job';
    }

    public function getJobPayload($command)
    {
        return json_encode($this->serializePayload($command), JSON_PRETTY_PRINT);
    }

    public function serializePayload($command)
    {
        $values = [];

        $properties = (new ReflectionClass($command))->getProperties();

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $property->setAccessible(true);

            $name = $property->getName();
            $value = $property->getValue($command);
            $values[$name] = $this->serializeValue($value);
        }

        return $values;
    }

    public function serializeValue($value)
    {
        if ($value instanceof Collection) {
            return $value->map(function ($item) {
                return $this->serializeValue($item);
            })->toArray();
        } elseif ($value instanceof Model) {
            return get_class($value) . ": " . $value->getKey();
        } elseif (is_object($value)) {
            return $this->serializePayload($value);
        } else {
            return $value;
        }
    }

    public function getServiceName()
    {
        if (!empty($this->serviceName)) {
            return $this->serviceName;
        }
        $this->serviceName = \ddtrace_config_app_name();
        if (empty($this->serviceName) && is_callable('config')) {
            $this->serviceName = config('app.name');
        }
        return $this->serviceName ?: 'laravel';
    }

    /**
     * Tells whether a span is a lumen request.
     *
     * @param SpanData $rootSpan
     * @return bool
     */
    public function isLumen(SpanData $rootSpan)
    {
        return $rootSpan->name === 'lumen.request';
    }

    /**
     * @param mixed $routeName
     * @return string
     */
    public static function normalizeRouteName($routeName)
    {
        if (!\is_string($routeName)) {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        $routeName = \trim($routeName);
        if ($routeName === '') {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        // Starting with PHP 7, unnamed routes have been given a randomly generated name that we need to
        // normalize:
        // https://github.com/laravel/framework/blob/7.x/src/Illuminate/Routing/AbstractRouteCollection.php#L227
        //
        // It can also be prefixed with domain name when caching is specified as Route::domain()->group(...);
        if (\strpos($routeName, 'generated::') !== false) {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        return $routeName;
    }
}
