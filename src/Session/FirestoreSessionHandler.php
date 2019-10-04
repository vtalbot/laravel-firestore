<?php

namespace TalbotNinja\LaravelFirestore\Session;

use Carbon\Carbon;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Session\ExistenceAwareInterface;
use Illuminate\Support\InteractsWithTime;
use SessionHandlerInterface;

class FirestoreSessionHandler implements ExistenceAwareInterface, SessionHandlerInterface
{
    use InteractsWithTime;

    /** @var FirestoreClient */
    protected $connection;

    /** @var string */
    protected $table;

    /** @var int */
    protected $minutes;

    /** @var Container */
    protected $container;

    /** @var bool */
    protected $exists;

    public function __construct(FirestoreClient $connection, string $table, int $minutes, Container $container = null)
    {
        $this->table = $table;
        $this->minutes = $minutes;
        $this->container = $container;
        $this->connection = $connection;
    }

    /** {@inheritDoc} */
    public function close(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function destroy($sessionId): bool
    {
        $this->getCollection()->document($sessionId)->delete();

        return true;
    }

    /** {@inheritDoc} */
    public function gc($lifetime): bool
    {
        $sessions = $this->getCollection()
            ->where('last_activity', '<=', $this->currentTime() - $lifetime)
            ->documents();

        /** @var DocumentSnapshot $session */
        foreach ($sessions as $session) {
            $session->reference()->delete();
        }

        return true;
    }

    /** {@inheritDoc} */
    public function open($savePath, $name): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function read($sessionId): string
    {
        $session = $this->getCollection()->document($sessionId)->snapshot()->data();

        if ($session === null || $this->expired($session)) {
            $this->exists = true;

            return '';
        }

        if (isset($session['payload'])) {
            $this->exists = true;

            return base64_decode($session['payload']);
        }

        return '';
    }

    /** {@inheritDoc} */
    public function setExists($value): self
    {
        $this->exists = $value;

        return $this;
    }

    /** {@inheritDoc} */
    public function write($sessionId, $sessionData): bool
    {
        $payload = $this->getDefaultPayload($sessionData);

        if (! $this->exists) {
            $this->read($sessionId);
        }

        if ($this->exists) {
            $this->performUpdate($sessionId, $payload);
        } else {
            $this->performInsert($sessionId, $payload);
        }

        return $this->exists = true;
    }

    protected function addRequestInformation(array &$payload): self
    {
        if ($this->container->bound('request')) {
            $payload = array_merge($payload, [
                'ip_address' => $this->ipAddress(),
                'user_agent' => $this->userAgent(),
            ]);
        }

        return $this;
    }

    protected function addUserInformation(array &$payload): self
    {
        if ($this->container->bound(Guard::class)) {
            $payload['user_id'] = $this->userId();
        }

        return $this;
    }

    protected function expired(array $session): bool
    {
        return isset($session['last_activity']) &&
            $session['last_activity'] < Carbon::now()->subMinutes($this->minutes)->getTimestamp();
    }

    protected function getCollection(): CollectionReference
    {
        return $this->connection->collection($this->table);
    }

    protected function getDefaultPayload(string $data): array
    {
        $payload = [
            'payload' => base64_encode($data),
            'last_activity' => $this->currentTime(),
        ];

        if ($this->container === null) {
            return $payload;
        }

        return tap($payload, function (&$payload) {
            $this->addUserInformation($payload)
                 ->addRequestInformation($payload);
        });
    }

    protected function ipAddress(): ?string
    {
        try {
            return $this->container->make('request')->ip();
        } catch (BindingResolutionException $exception) {
            return null;
        }
    }

    protected function performInsert(string $sessionId, array $payload): array
    {
        return $this->getCollection()->document($sessionId)->create($payload);
    }

    protected function performUpdate(string $sessionId, array $payload): array
    {
        return $this->getCollection()->document($sessionId)->set($payload);
    }

    protected function userAgent(): ?string
    {
        try {
            return substr((string) $this->container->make('request')->header('User-Agent'), 0, 500);
        } catch (BindingResolutionException $exception) {
            return null;
        }
    }

    protected function userId()
    {
        try {
            return $this->container->make(Guard::class)->id();
        } catch (BindingResolutionException $exception) {
            return null;
        }
    }
}
