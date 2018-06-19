<?php

namespace EntryNinja\ElasticApmLaravel\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Events\QueryExecuted;

class ElasticApmServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            $stackTrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 50);
            $stackTrace = collect($stackTrace)->map(function ($trace) {
                return array_set($trace, 'file', str_replace(base_path(), '', array_get($trace, 'file')));
            })->filter(function ($trace) {
                return !starts_with(array_get($trace, 'file'), [
                    '/vendor'
                ]);
            })->map(function ($trace) {
                // $sourceCode = collect(file(base_path().array_get($trace, 'file')))->filter(function ($code, $line) use ($trace) {
                //     $lineStart = array_get($trace, 'line') - 5;
                //     $lineStop = array_get($trace, 'line') + 5;
                    
                //     return $line >= $lineStart && $line <= $lineStop;
                // })->groupBy(function ($code, $line) use ($trace) {
                //     if ($line < array_get($trace, 'line')) {
                //         return 'pre_context';
                //     }

                //     if ($line == array_get($trace, 'line')) {
                //         return 'context_line';
                //     }

                //     if ($line > array_get($trace, 'line')) {
                //         return 'post_context';
                //     }

                //     return 'trash';
                // });

                // // $vars = array_get($trace, 'args'); // returns [0=>Object, 1=>'string',2=>false] etc.
                // // if (empty($vars)) {
                // $vars = null;
                // // }

                return [
                    'function' => array_get($trace, 'function').array_get($trace, 'type').array_get($trace, 'function'),
                    'abs_path' => array_get($trace, 'file'),
                    'filename' => basename(array_get($trace, 'file')),
                    'lineno' => array_get($trace, 'line', 0),
                    'library_frame' => false,
                    'vars' => $vars ?? null,
                    // 'module' => 'some module',
                    // 'colno' => 4,
                    // 'pre_context' => optional($sourceCode->get('pre_context'))->toArray(),
                    // 'context_line' => optional($sourceCode->get('context_line'))->first(),
                    // 'post_context' => optional($sourceCode->get('post_context'))->toArray(),
                ];
            })->values();
            
            $query = [
                'name' => 'Eloquent Query',
                'type' => 'db.mysql.query',
                'start' => round((microtime(true) - $query->time/1000 - LARAVEL_START) * 1000, 3),
                'duration' => round($query->time, 3), // milliseconds
                'stacktrace' => $stackTrace,
                'context' => [
                    'db' => [
                        'instance' => $query->connection->getDatabaseName(),
                        'statement' => $query->sql,
                        'type' => 'sql',
                        'user' => $query->connection->getConfig('username'),
                    ],
                ],
            ];
        
            app('query-log')->push($query);
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->instance('elastic-apm', $this->createElasticApmInstance());
        $this->app->instance('query-log', collect([]));
    }
    
    protected function createElasticApmInstance(): \PhilKra\Agent
    {
        return new \PhilKra\Agent([
            'appName' => config('app.name'),
        ]);
    }
}
