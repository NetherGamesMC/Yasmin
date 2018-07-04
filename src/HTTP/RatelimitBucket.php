<?php
/**
 * Yasmin
 * Copyright 2017-2018 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Yasmin/blob/master/LICENSE
*/

namespace CharlotteDunois\Yasmin\HTTP;

/**
 * Manages a route's ratelimit in memory.
 * @internal
 */
final class RatelimitBucket implements \CharlotteDunois\Yasmin\Interfaces\RatelimitBucketInterface {
    /**
     * @var \CharlotteDunois\Yasmin\HTTP\APIManager
     */
    protected $api;
    
    /**
     * @var string
     */
    protected $endpoint;
    
    /**
     * @var int
     */
    protected $limit = 0;
    
    /**
     * @var int
     */
    protected $remaining = \INF;
    
    /**
     * @var int
     */
    protected $resetTime = 0;
    
    /**
     * @var array
     */
    protected $queue = array();
    
    /**
     * @var bool
     */
    protected $busy = false;
    
    /**
     * DO NOT initialize this class yourself.
     * @param \CharlotteDunois\Yasmin\HTTP\APIManager  $api
     * @param string                                   $endpoint
     */
    function __construct(\CharlotteDunois\Yasmin\HTTP\APIManager $api, string $endpoint) {
        $this->api = $api;
        $this->endpoint = $endpoint;
    }
    
    /**
     * Destroys the bucket.
     */
    function __destruct() {
        $this->clear();
    }
    
    /**
     * Whether we are busy.
     * @return bool
     */
    function isBusy(): bool {
        return $this->busy;
    }
    
    /**
     * Sets the busy flag (marking as running).
     * @param bool  $busy
     */
    function setBusy(bool $busy) {
        $this->busy = $busy;
    }
    
    /**
     * Sets the ratelimits from the response.
     * @param int|null  $limit
     * @param int|null  $remaining
     * @param int|null  $resetTime
     * @return \React\Promise\ExtendedPromiseInterface|void
     */
    function handleRatelimit(?int $limit, ?int $remaining, ?int $resetTime) {
        if($limit === null && $remaining === null && $resetTime === null) {
            $this->remaining++; // there is no ratelimit...
            return;
        }
        
        $this->limit = $limit ?? $this->limit;
        $this->remaining = $remaining ?? $this->remaining;
        $this->resetTime = $resetTime ?? $this->resetTime;
        
        if($this->remaining === 0 && $this->resetTime > \time()) {
            $this->api->client->emit('debug', 'Endpoint "'.$this->endpoint.'" ratelimit encountered, continueing in '.($this->resetTime - \time()).' seconds');
        }
    }
    
    /**
     * Returns the endpoint this bucket is for.
     * @return string
     */
    function getEndpoint(): string {
        return $this->endpoint;
    }
    
    /**
     * Returns the size of the queue.
     * @var int
     */
    function size(): int {
        return \count($this->queue);
    }
    
    /**
     * Pushes a new request into the queue.
     * @param \CharlotteDunois\Yasmin\HTTP\APIRequest $request
     * @return $this
     */
    function push(\CharlotteDunois\Yasmin\HTTP\APIRequest $request) {
        $this->queue[] = $request;
        return $this;
    }
    
    /**
     * Unshifts a new request into the queue. Modifies remaining ratelimit.
     * @param \CharlotteDunois\Yasmin\HTTP\APIRequest $request
     * @return $this
     */
    function unshift(\CharlotteDunois\Yasmin\HTTP\APIRequest $request) {
        \array_unshift($this->queue, $request);
        $this->remaining++;
        return $this;
    }
    
    /**
     * Retrieves ratelimit meta data.
     *
     * The resolved value must be:
     * <pre>
     * array(
     *     'limited' => bool,
     *     'resetTime' => int|null
     * )
     * </pre>
     *
     * @return \React\Promise\ExtendedPromiseInterface|array
     */
    function getMeta() {
        if($this->resetTime && \time() > $this->resetTime) {
            $this->resetTime = null;
            $this->remaining = ($this->limit ? $this->limit : \INF);
            
            $limited = false;
        } else {
            $limited = ($this->limit !== 0 && $this->remaining === 0);
        }
        
        return array('limited' => $limited, 'resetTime' => $this->resetTime);
    }
    
    /**
     * Returns the first queue item or false. Modifies remaining ratelimit.
     * @return \CharlotteDunois\Yasmin\HTTP\APIRequest|false
     */
    function shift() {
        if(\count($this->queue) === 0) {
            return false;
        }
        
        $item = \array_shift($this->queue);
        $this->remaining--;
        
        return $item;
    }
    
    /**
     * Unsets all queue items.
     */
    function clear() {
        $this->remaining = 0;
        while($item = \array_shift($this->queue)) {
            unset($item);
        }
    }
}
