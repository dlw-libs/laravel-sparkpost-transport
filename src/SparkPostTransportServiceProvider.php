<?php

namespace Dlw\Mail;

use Dlw\Mail\SparkPostTransport;
use Illuminate\Support\ServiceProvider;
use GuzzleHttp\Client;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use SparkPost\SparkPost;

class SparkPostTransportServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app['config']['mail.driver'] !== 'sparkpost') {
            return;
        }

        $this->app['swift.transport']->extend('sparkpost', function () {
            $httpClient = new GuzzleAdapter(new Client());
            $key = $this->app['config']['services.sparkpost.secret'] ?? '';
            $options = $this->app['config']['services.sparkpost.options'] ?? [];
            return new SparkPostTransport(new SparkPost($httpClient, ['key'=>$key]), $options);
        });
    }
}
