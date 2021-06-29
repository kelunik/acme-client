<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File;
use Amp\Promise;
use function Amp\call;

class ChallengeStore
{
    private $docroot;

    public function __construct(string $docroot)
    {
        $this->docroot = \rtrim(\str_replace("\\", '/', $docroot), '/');
    }

    public function put(string $token, string $payload, string $user = null): Promise
    {
        return call(function () use ($token, $payload, $user) {
            $path = $this->docroot . '/.well-known/acme-challenge';
            $userInfo = null;

            if (!yield File\exists($this->docroot)) {
                throw new ChallengeStoreException("Document root doesn't exist: '{$this->docroot}'");
            }

            yield File\createDirectoryRecursively($path, 0755);

            if ($user && !$userInfo = \posix_getpwnam($user)) {
                throw new ChallengeStoreException("Unknown user: '{$user}'");
            }

            if ($userInfo !== null) {
                yield File\changeOwner($this->docroot . '/.well-known', $userInfo['uid'], -1);
                yield File\changeOwner($this->docroot . '/.well-known/acme-challenge', $userInfo['uid'], -1);
            }

            yield File\write("{$path}/{$token}", $payload);

            if ($userInfo !== null) {
                yield File\changeOwner("{$path}/{$token}", $userInfo['uid'], -1);
            }

            yield File\changePermissions("{$path}/{$token}", 0644);
        });
    }

    public function delete(string $token): Promise
    {
        return call(function () use ($token) {
            $path = $this->docroot . "/.well-known/acme-challenge/{$token}";

            if (yield File\exists($path)) {
                yield File\deleteFile($path);
            }
        });
    }
}
