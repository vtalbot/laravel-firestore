<?php

namespace TalbotNinja\LaravelFirestore\Auth;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Hash;

class FirestoreUserProvider implements UserProvider
{
    /** @var FirestoreClient */
    protected $connection;

    /** @var string */
    protected $model;

    /** @var string */
    protected $table;

    /** @var string */
    protected $passwordField;

    public function __construct(FirestoreClient $connection, string $model, string $table, string $passwordField)
    {
        $this->model = $model;
        $this->table = $table;
        $this->connection = $connection;
        $this->passwordField = $passwordField;
    }

    /** {@inheritDoc} */
    public function retrieveById($identifier): ?Authenticatable
    {
        $user = $this->getCollection()->document($identifier)->snapshot();

        if (! $user->exists()) {
            return null;
        }

        return $this->getUser($user);
    }

    /** {@inheritDoc} */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        $model = $this->getUserShell();
        $user = $this->getCollection()->document($identifier)->snapshot();

        if (! $user->exists()
            || array_key_exists($model->getRememberTokenName(), $user->data())
            || $user->get($model->getRememberTokenName()) !== $token) {
            return null;
        }

        return $this->getUser($user);
    }

    /** {@inheritDoc} */
    public function updateRememberToken(Authenticatable $user, $token): void
    {
        $model = $this->getUserShell();
        $this->getCollection()
            ->document($user->getAuthIdentifier())
            ->update([['path' => $model->getRememberTokenName(), 'value' => $token]]);
    }

    /** {@inheritDoc} */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = $this->getCollection();

        collect($credentials)
            ->except($this->passwordField)
            ->each(static function ($value, $key) use (&$query) {
                $query = $query->where($key, '=', $value);
            });

        $user = $query->documents()->getIterator()->current();

        if ($user !== null) {
            return $this->getUser($user);
        }

        return null;
    }

    /** {@inheritDoc} */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return Hash::check($credentials[$this->passwordField], $user->getAuthPassword());
    }

    protected function getCollection(): CollectionReference
    {
        return $this->connection->collection($this->table);
    }

    protected function getUser(DocumentSnapshot $snapshot): Authenticatable
    {
        $user = $this->getUserShell();

        $data = $snapshot->data();
        $data[$this->getUserShell()->getAuthIdentifierName()] = $snapshot->id();

        foreach ($data as $key => $value) {
            $user->{$key} = $value;
        }

        return $user;
    }

    protected function getUserShell(): Authenticatable
    {
        return new $this->model;
    }
}
