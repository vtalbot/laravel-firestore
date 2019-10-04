<?php

namespace TalbotNinja\LaravelFirestore\Cache;

use Closure;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Cache\RetrievesMultipleKeys;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\InteractsWithTime;

class FirestoreStore implements Store
{
    use InteractsWithTime, RetrievesMultipleKeys;

    /** @var FirestoreClient */
    protected $connection;

    /** @var string */
    protected $table;

    /** @var string */
    protected $prefix;

    public function __construct(FirestoreClient $connection, string $table, string $prefix)
    {
        $this->table = $table;
        $this->prefix = $prefix;
        $this->connection = $connection;
    }

    /** {@inheritDoc} */
    public function get($key)
    {
        $prefixed = $this->prefix . $key;

        $cache = $this->getCollection()->document($prefixed)->snapshot()->data();

        if ($cache === null) {
            return null;
        }

        $cache = is_array($cache) ? (object) $cache : $cache;

        if ($this->currentTime() >= $cache->expiration) {
            $this->forget($key);

            return null;
        }

        return $this->unserialize($cache->value);
    }

    /** {@inheritDoc} */
    public function put($key, $value, $seconds): bool
    {
        $key = $this->prefix . $key;
        $value = $this->serialize($value);
        $expiration = $this->currentTime() + $seconds;

        $this->getCollection()->document($key)->set(compact('value', 'expiration'));

        return true;
    }

    /** {@inheritDoc} */
    public function increment($key, $value = 1)
    {
        return $this->incrementOrDecrement($key, $value, function ($current, $value) {
            return $current + $value;
        });
    }

    /** {@inheritDoc} */
    public function decrement($key, $value = 1)
    {
        return $this->incrementOrDecrement($key, $value, function ($current, $value) {
            return $current - $value;
        });
    }

    protected function incrementOrDecrement($key, $value, Closure $callback)
    {
        return $this->connection->runTransaction(function () use ($key, $value, $callback) {
            $prefixed = $this->prefix . $key;

            $item = $this->getCollection()->document($prefixed);

            $cache = $item->snapshot()->data();

            if ($cache === null) {
                return false;
            }

            $cache = (object) $cache;

            $current = $this->unserialize($cache->value);

            $new = $callback((int) $current, $value);

            if (! is_numeric($current)) {
                return false;
            }

            $item->update([['path' => 'value', 'value' => $this->serialize($new)]]);

            return $new;
        });
    }

    /** {@inheritDoc} */
    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 315360000);
    }

    /** {@inheritDoc} */
    public function forget($key): bool
    {
        $this->getCollection()->document($this->prefix . $key)->delete();

        return true;
    }

    /** {@inheritDoc} */
    public function flush(): bool
    {
        $items = $this->getCollection()->documents();

        foreach ($items as $item) {
            $item->reference()->delete();
        }

        return true;
    }

    /** {@inheritDoc} */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    protected function getCollection(): CollectionReference
    {
        return $this->connection->collection($this->table);
    }

    protected function serialize($value): string
    {
        $result = serialize($value);

        return base64_encode($result);
    }

    protected function unserialize($value)
    {
        $value = base64_decode($value);

        return unserialize($value);
    }
}
