<?php
/**
 * @author Aaron Francis <aaron@hammerstone.dev|https://twitter.com/aarondfrancis>
 */

namespace Torchlight;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Torchlight\Contracts\PostProcessor;
use Torchlight\Exceptions\ConfigurationException;

class Manager
{
    use Macroable;

    /**
     * @var null|callable
     */
    protected $getConfigUsing;

    /**
     * @var Repository
     */
    protected $cache;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var null|string
     */
    protected $environment;

    /**
     * @var array
     */
    protected $postProcessors = [];

    /**
     * @var bool
     */
    protected $currentlyCompilingViews = false;

    /**
     * @param  Client  $client
     * @return Manager
     */
    public function setClient(Client $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return Client
     */
    public function client()
    {
        if (!$this->client) {
            $this->client = new Client;
        }

        return $this->client;
    }

    /**
     * @param  $value
     */
    public function currentlyCompilingViews($value)
    {
        $this->currentlyCompilingViews = $value;
    }

    /**
     * @param  $blocks
     * @return mixed
     */
    public function highlight($blocks)
    {
        $blocks = $this->client()->highlight($blocks);

        $this->postProcessBlocks($blocks);

        return $blocks;
    }

    /**
     * @return string
     */
    public function environment()
    {
        return $this->environment ?? app()->environment();
    }

    /**
     * @param  string|null  $environment
     */
    public function overrideEnvironment($environment = null)
    {
        $this->environment = $environment;
    }

    /**
     * @param  array|string  $classes
     */
    public function addPostProcessors($classes)
    {
        $classes = Arr::wrap($classes);

        foreach ($classes as $class) {
            $this->postProcessors[] = $this->validatedPostProcessor($class);
        }
    }

    /**
     * @param  $blocks
     */
    public function postProcessBlocks($blocks)
    {
        // Global post-processors
        foreach ($this->postProcessors as $processor) {
            if ($this->shouldSkipProcessor($processor)) {
                continue;
            }

            foreach ($blocks as $block) {
                $processor->process($block);
            }
        }

        // Block specific post-processors
        foreach ($blocks as $block) {
            foreach ($block->postProcessors as $processor) {
                if ($this->shouldSkipProcessor($processor)) {
                    continue;
                }

                $processor->process($block);
            }
        }
    }

    public function processFileContents($file)
    {
        if (Str::startsWith($file, '##LARAVEL_TRIM_FIXER##')) {
            return false;
        }

        $directories = $this->config('snippet_directories', []);

        // Add a blank path to account for absolute paths.
        array_unshift($directories, '');

        foreach ($directories as $directory) {
            if (!empty($directory)) {
                $directory = Str::finish($directory, DIRECTORY_SEPARATOR);
            }

            $contents = @file_get_contents($directory . $file);
            if ($contents) {
                return $contents;
            }
        }

        return false;
    }

    /**
     * Get an item out of the config using dot notation.
     *
     * @param  $key
     * @param  null  $default
     * @return mixed
     */
    public function config($key, $default = null)
    {
        // Default to Laravel's config method.
        $method = $this->getConfigUsing ?? 'config';

        // If we are using Laravel's config method, then we'll prepend
        // the key with `torchlight` if it isn't already there.
        if ($method === 'config') {
            $key = Str::start($key, 'torchlight.');
        }

        return call_user_func($method, $key, $default);
    }

    /**
     * A callback function used to access configuration. By default this
     * is null, which will fall through to Laravel's `config` function.
     *
     * @param  $callback
     */
    public function getConfigUsing($callback)
    {
        if (is_array($callback)) {
            $callback = function ($key, $default) use ($callback) {
                return Arr::get($callback, $key, $default);
            };
        }

        $this->getConfigUsing = $callback;
    }

    /**
     * Set the cache implementation directly instead of using a driver.
     *
     * @param  Repository  $cache
     */
    public function setCacheInstance(Repository $cache)
    {
        $this->cache = $cache;
    }

    /**
     * The cache store to use.
     *
     * @return Repository
     */
    public function cache()
    {
        if ($this->cache) {
            return $this->cache;
        }

        // If the developer has requested a particular store, we'll use it.
        // If the config value is null, the default cache will be used.
        return Cache::store($this->config('cache'));
    }

    /**
     * Return all the Torchlight IDs in a given string.
     *
     * @param  string  $content
     * @return array
     */
    public function findTorchlightIds($content)
    {
        preg_match_all('/__torchlight-block-\[(.+?)\]/', $content, $matches);

        return array_values(array_unique(Arr::get($matches, 1, [])));
    }

    /**
     * @param  $processor
     * @return PostProcessor
     *
     * @throws ConfigurationException
     */
    public function validatedPostProcessor($processor)
    {
        if (is_string($processor)) {
            $processor = app($processor);
        }

        if (!in_array(PostProcessor::class, class_implements($processor))) {
            $class = get_class($processor);
            throw new ConfigurationException("Post-processor '$class' does not implement " . PostProcessor::class);
        }

        return $processor;
    }

    protected function shouldSkipProcessor($processor)
    {
        // By default we do _not_ run post-processors when Laravel is compiling
        // views, because it could lead to data leaks if a post-processor swaps
        // user data in. If the developer understands this, they can turn
        // `processEvenWhenCompiling` on and we'll happily run them.
        $processWhenCompiling = property_exists($processor, 'processEvenWhenCompiling')
            && $processor->processEvenWhenCompiling;

        return $this->currentlyCompilingViews && !$processWhenCompiling;
    }
}
