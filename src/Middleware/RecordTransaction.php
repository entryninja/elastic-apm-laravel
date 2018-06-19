<?php

namespace EntryNinja\ElasticApmLaravel\Middleware;

use Closure;
use Illuminate\Routing\Route;
use Ramsey\Uuid\Uuid;

class RecordTransaction
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $apm = app('elastic-apm');

        // start the transaction
        $tmpName = Uuid::uuid4()->toString();
        $transaction = $apm->startTransaction($tmpName);

        // await the outcome
        $response = $next($request);
        
        // set the response data
        $transaction->setResponseContext([
            'status_code' => $response->getStatusCode(),
            'headers' => $this->formatHeaders($response->headers->all()),
            'headers_sent' => true,
            'finished' => true,
        ]);

        // set the user
        $transaction->setUserContext([
            'id'    => optional($request->user())->id,
            'email' => optional($request->user())->email,
         ]);

        // set the meta details
        $transaction->setMeta([
            'result' => $response->getStatusCode(),
            'type'   => 'request'
         ]);

        // add the spans
        $transaction->setSpans(app('query-log')->toArray());

        // stop the transaction
        $transaction->stop(
            $this->getDuration(LARAVEL_START)
        );

        // update the name
        $transaction->setTransactionName(
            $this->getTransactionName($request->route())
        );

        return $response;
    }

    /**
     * Perform any final actions for the request lifecycle.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     *
     * @return void
     */
    public function terminate($request, $response)
    {
        app('elastic-apm')->send();
    }

    protected function getTransactionName(Route $route)
    {
        // fix leading /
        if ($route->uri !== '/') {
            $route->uri = '/'.$route->uri;
        }

        return sprintf(
            "%s %s",
            head($route->methods),
            $route->uri
        );
    }

    protected function getDuration($start): float
    {
        $diff = microtime(true) - $start;
        $corrected = $diff * 1000; // convert to miliseconds

        return round($corrected, 3);
    }

    protected function formatHeaders(array $headers): array
    {
        return collect($headers)->map(function ($values, $header) {
            return head($values);
        })->toArray();
    }
}
